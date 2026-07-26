<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Core\Logger;
use LitePic\Repository\DavEntryRepository;
use LitePic\Repository\ImageRepository;
use LitePic\Service\Album\AlbumService;
use LitePic\Service\Image\ImageDeleter;
use LitePic\Service\Upload\UploadService;

/**
 * The mutating half of WebDAV: PUT, DELETE, MKCOL, MOVE, COPY.
 *
 * Every write funnels into machinery that already exists — uploads go through
 * {@see UploadService::storeFromPath()} so a WebDAV write gets the identical
 * MIME sniffing, SVG scrubbing, content-hash dedupe, storage layout and
 * post-processing queue as a browser upload; deletes go through
 * {@see ImageDeleter} so they clean up derivatives, thumbnails and remote
 * objects the same way the gallery does. This class only translates filesystem
 * gestures into those calls.
 *
 * Three places where filesystem semantics and an image host genuinely diverge,
 * each resolved in favour of not losing data:
 *
 *   Deleting a folder removes the *album*, not its photos — they reappear under
 *   `未分类`. A mis-dragged folder in Finder should not be able to erase a
 *   thousand images, and the album is the only thing a folder really is here.
 *
 *   COPY links rather than duplicates. Content-hash dedupe means writing the
 *   same bytes twice cannot produce two stored files, so a copy into another
 *   album adds a membership row — which is exactly what an album is. Copying
 *   within one album is refused instead of silently doing nothing.
 *
 *   DELETE of a linked name only drops that name (and its album membership).
 *   The underlying image is removed only when nothing else — no other
 *   `dav_entries` row, no remaining `album_images` membership — still points
 *   at it. That keeps COPY's hard-link semantics honest on both sides.
 *
 *   Overwriting a name replaces the image behind it and deletes the old one,
 *   because that is what PUT over an existing resource means. When dedupe
 *   recognises the incoming bytes as the file already there, nothing is deleted.
 */
final class DavWriter
{
    /** @var array{code:int,condition:string,headers:array<string,string>} */
    private const OK_CREATED = ['code' => 201, 'condition' => '', 'headers' => []];

    private DavTree $tree;
    private DavEntryRepository $entries;
    private AlbumService $albums;
    private UploadService $uploads;
    private ImageRepository $images;
    private ImageDeleter $deleter;
    private DavIndex $index;

    /** True once a PUT has stored something, so the caller can drain the queue. */
    private bool $storedSomething = false;

    public function __construct(
        DavTree $tree,
        ?DavEntryRepository $entries = null,
        ?AlbumService $albums = null,
        ?UploadService $uploads = null,
        ?ImageRepository $images = null,
        ?ImageDeleter $deleter = null,
        ?DavIndex $index = null
    ) {
        $this->tree = $tree;
        $this->entries = $entries ?? new DavEntryRepository();
        $this->albums = $albums ?? new AlbumService();
        $this->uploads = $uploads ?? new UploadService();
        $this->images = $images ?? new ImageRepository();
        $this->deleter = $deleter ?? new ImageDeleter();
        $this->index = $index ?? new DavIndex($this->entries);
    }

    public function storedSomething(): bool
    {
        return $this->storedSomething;
    }

    // ---- PUT -----------------------------------------------------------------

    /**
     * Store (or replace) the request body at $path.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function put(DavRequest $request, string $path): array
    {
        if ($request->isPartialPut()) {
            // A partial write can't be applied to an immutable stored image, and
            // pretending otherwise would silently corrupt it.
            return self::fail(501);
        }

        $name = DavPath::basename($path);
        $parent = $this->tree->resolveParentCollection($path);

        if ($parent === null) {
            // RFC 4918 §9.7.1: no parent collection → 409, not 404.
            return self::fail(409);
        }
        if (!$this->isWritableCollection($parent)) {
            return self::fail(403);
        }
        if (count(DavPath::segments($path)) !== 2) {
            return self::fail(409);
        }

        // OS scratch files are accepted and discarded — see DavQuirks.
        if (DavQuirks::isJunkName($name)) {
            self::discardBody();
            return self::OK_CREATED;
        }

        $existing = $this->tree->resolve($path);
        if ($existing !== null && $existing->isCollection) {
            return self::fail(405);
        }

        [$temp, $written] = $request->streamBodyToTempFile(DavConfig::maxPutBytes());
        if ($temp === null) {
            return self::fail($written > DavConfig::maxPutBytes() ? 413 : 500);
        }

        try {
            if ($written === 0) {
                @unlink($temp);
                return $this->storePlaceholder($parent, $name, $existing);
            }
            return $this->storeUpload($parent, $name, $existing, $temp);
        } finally {
            // storeFromPath() consumes the temp file on success; this catches
            // every path where it didn't.
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Reserve a name with no bytes behind it. Finder and Office both open a
     * transfer by PUTting zero bytes and only send content once that succeeds;
     * answering 403 there aborts the copy before the real data is ever sent.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function storePlaceholder(DavNode $parent, string $name, ?DavNode $existing): array
    {
        $albumId = (int)$parent->albumId;

        if ($existing !== null) {
            // Truncating an existing image to zero bytes is not something this
            // store can represent, and treating it as "delete the image" would
            // turn a probe into data loss. Report success and change nothing.
            return ['code' => 204, 'condition' => '', 'headers' => []];
        }

        if ($this->entries->insert($albumId, $name, null) === null) {
            return self::fail(409);
        }
        return self::OK_CREATED;
    }

    /**
     * Run the body through the upload pipeline and bind it to $name.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function storeUpload(DavNode $parent, string $name, ?DavNode $existing, string $temp): array
    {
        $albumId = (int)$parent->albumId;

        $result = $this->uploads->storeFromPath($temp, $name);
        $status = (string)($result['status'] ?? 'error');

        if ($status !== 'success' && $status !== 'duplicate') {
            $message = (string)($result['message'] ?? '');
            Logger::warning('WebDAV PUT rejected', ['name' => $name, 'reason' => $message]);
            // Size is the one rejection with a dedicated status code; everything
            // else is "these bytes are not an acceptable image".
            return self::fail(str_contains($message, '超过大小限制') ? 413 : 415);
        }

        $filename = (string)($result['filename'] ?? '');
        if ($filename === '') {
            return self::fail(500);
        }
        $this->storedSomething = true;

        if ($albumId > 0) {
            $this->albums->addImages($albumId, [$filename]);
        }

        $previousFilename = $existing !== null ? $existing->filename : null;

        if ($existing !== null && $existing->entryId !== null) {
            $this->entries->updateFilename($existing->entryId, $filename);
        } elseif ($this->entries->insert($albumId, $name, $filename) === null) {
            // Another request claimed the name between resolve and insert.
            $raced = $this->entries->findByName($albumId, $name);
            if ($raced === null) {
                return self::fail(409);
            }
            $this->entries->updateFilename($raced['id'], $filename);
        }

        // Replacing a name means the image that used to be there is gone — but
        // only when nothing else still points at it. Two names can share one
        // image after a content-hash dedupe (PUT the same bytes as an existing
        // name); deleting that shared image would cascade-remove the other name.
        if ($previousFilename !== null && $previousFilename !== $filename) {
            if (!$this->filenameStillMapped($previousFilename)) {
                $this->deleter->delete($previousFilename);
            }
        }

        return $existing === null
            ? self::OK_CREATED
            : ['code' => 204, 'condition' => '', 'headers' => []];
    }

    /** True when any dav_entries row still points at $filename. */
    private function filenameStillMapped(string $filename): bool
    {
        $pdo = \LitePic\Core\Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM dav_entries WHERE filename = :fn LIMIT 1');
        $stmt->execute([':fn' => $filename]);
        return (bool)$stmt->fetchColumn();
    }

    // ---- DELETE --------------------------------------------------------------

    /**
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function delete(DavNode $node): array
    {
        if (!$node->writable || $node->kind === DavNode::KIND_ROOT) {
            return self::fail(403);
        }

        if ($node->kind === DavNode::KIND_UNFILED) {
            // Synthetic view; there is no such thing as removing it.
            return self::fail(403);
        }

        if ($node->kind === DavNode::KIND_ALBUM) {
            $albumId = (int)$node->albumId;
            $this->entries->deleteByAlbum($albumId);
            if (!$this->albums->delete($albumId)) {
                return self::fail(500);
            }
            $this->tree->forgetAlbums();
            return ['code' => 204, 'condition' => '', 'headers' => []];
        }

        if ($node->kind !== DavNode::KIND_FILE) {
            return self::fail(403);
        }

        if ($node->isPlaceholder()) {
            if ($node->entryId !== null) {
                $this->entries->delete($node->entryId);
            }
            return ['code' => 204, 'condition' => '', 'headers' => []];
        }

        $filename = (string)$node->filename;
        $albumId = (int)$node->albumId;

        // Drop this name first. COPY is a hard link (one image, many dav_entries /
        // album memberships), so removing a name must not take the bytes with it
        // while another name — or another album — still points at them.
        if ($node->entryId !== null) {
            $this->entries->delete($node->entryId);
        }
        if ($albumId > 0 && $filename !== '') {
            $this->albums->removeImage($albumId, $filename);
        }

        if ($filename !== '' && !$this->filenameStillReferenced($filename)) {
            $result = $this->deleter->delete($filename);
            if (!$result['ok']) {
                Logger::warning('WebDAV DELETE failed', [
                    'path' => $node->path,
                    'reason' => $result['message'],
                ]);
                return self::fail(500);
            }
        }

        return ['code' => 204, 'condition' => '', 'headers' => []];
    }

    // ---- MKCOL ---------------------------------------------------------------

    /**
     * Create an album for a new top-level folder.
     *
     * New albums are created `private`. A folder made over a network mount is
     * not an intentional publishing action, and silently exposing whatever gets
     * dropped into it would be the wrong default; visibility is one click away
     * in the albums UI.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function mkcol(DavRequest $request, string $path): array
    {
        // RFC 4918 §9.3.1: a MKCOL body in a format the server doesn't
        // understand must be refused rather than ignored.
        if ($request->contentLength() !== null && $request->contentLength() > 0) {
            return self::fail(415);
        }

        return $this->createCollection($path);
    }

    /**
     * MKCOL minus the protocol envelope: create a top-level album folder.
     * Shared with the admin file browser, which has no DavRequest to inspect.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function createCollection(string $path): array
    {
        $segments = DavPath::segments($path);
        if (count($segments) === 0) {
            return self::fail(405);
        }
        if (count($segments) > 1) {
            // Nesting below an album has no representation: album membership is
            // flat, and inventing sub-albums here would create a tree the rest
            // of LitePic can't show.
            return self::fail(403);
        }

        $name = $segments[0];
        if (DavQuirks::isJunkName($name)) {
            return self::fail(403);
        }
        if ($this->tree->resolve($path) !== null) {
            return self::fail(405);
        }
        if (isset(DavName::reservedTopLevel()[$name])) {
            return self::fail(405);
        }

        $created = $this->albums->create(['name' => $name, 'visibility' => 'private']);
        if (!is_int($created)) {
            Logger::warning('WebDAV MKCOL failed', ['name' => $name, 'reason' => $created]);
            return self::fail(409);
        }

        $this->tree->forgetAlbums();

        // The derived folder name must round-trip: if the album title had to be
        // sanitised, the client asked for a folder that will not appear under
        // that name, and reporting success would leave it looking at a ghost.
        if ($this->tree->folderNameForAlbum($created) !== $name) {
            $this->albums->delete($created);
            $this->tree->forgetAlbums();
            return self::fail(403);
        }

        return self::OK_CREATED;
    }

    // ---- MOVE ----------------------------------------------------------------

    /**
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function move(DavNode $source, string $destination, bool $overwrite): array
    {
        if (!$source->writable || $source->kind === DavNode::KIND_ROOT) {
            return self::fail(403);
        }
        if ($source->kind === DavNode::KIND_UNFILED) {
            return self::fail(403);
        }
        if (DavPath::isAtOrBelow($destination, $source->path)) {
            // Moving something into itself.
            return self::fail(409);
        }

        return $source->kind === DavNode::KIND_ALBUM
            ? $this->moveAlbum($source, $destination, $overwrite)
            : $this->moveFile($source, $destination, $overwrite);
    }

    /**
     * Renaming an album folder renames the album.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function moveAlbum(DavNode $source, string $destination, bool $overwrite): array
    {
        $segments = DavPath::segments($destination);
        if (count($segments) !== 1) {
            return self::fail(403);
        }
        $name = $segments[0];
        if (isset(DavName::reservedTopLevel()[$name])) {
            return self::fail(403);
        }

        $existing = $this->tree->resolve($destination);
        if ($existing !== null) {
            // Merging two albums is not a rename; refuse rather than guess.
            return self::fail($overwrite ? 403 : 412);
        }

        $albumId = (int)$source->albumId;
        $oldName = $source->name;
        $updated = $this->albums->update($albumId, ['name' => $name]);
        if ($updated !== true) {
            Logger::warning('WebDAV MOVE album failed', ['album' => $albumId, 'reason' => $updated]);
            return self::fail(409);
        }

        $this->tree->forgetAlbums();
        if ($this->tree->folderNameForAlbum($albumId) !== $name) {
            // Disambiguation renamed us (collision with 未分类 / 按日期 / twin
            // album). Roll back so the client failure matches the store.
            $this->albums->update($albumId, ['name' => $oldName]);
            $this->tree->forgetAlbums();
            return self::fail(403);
        }

        return ['code' => 204, 'condition' => '', 'headers' => []];
    }

    /**
     * Moving a file renames it, refiles it into another album, or both.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function moveFile(DavNode $source, string $destination, bool $overwrite): array
    {
        if ($source->entryId === null) {
            return self::fail(403);
        }

        $parent = $this->tree->resolveParentCollection($destination);
        if ($parent === null) {
            return self::fail(409);
        }
        if (!$this->isWritableCollection($parent)) {
            return self::fail(403);
        }
        if (count(DavPath::segments($destination)) !== 2) {
            return self::fail(409);
        }

        $name = DavPath::basename($destination);
        $targetAlbumId = (int)$parent->albumId;
        $sourceAlbumId = (int)$source->albumId;

        $conflict = $this->tree->resolve($destination);
        if ($conflict !== null) {
            if (!$overwrite) {
                return self::fail(412);
            }
            if ($conflict->isCollection) {
                return self::fail(403);
            }
            $replaced = $this->delete($conflict);
            if ($replaced['code'] >= 300) {
                return $replaced;
            }
        }

        if ($targetAlbumId !== $sourceAlbumId) {
            $filename = (string)$source->filename;
            // A placeholder has no image to refile; only its name moves.
            if ($filename !== '') {
                if ($targetAlbumId > 0) {
                    $this->albums->addImages($targetAlbumId, [$filename]);
                }
                if ($sourceAlbumId > 0) {
                    $this->albums->removeImage($sourceAlbumId, $filename);
                }
            }
        }

        if (!$this->entries->relocate($source->entryId, $targetAlbumId, $name)) {
            return self::fail(409);
        }

        return $conflict === null
            ? self::OK_CREATED
            : ['code' => 204, 'condition' => '', 'headers' => []];
    }

    // ---- COPY ----------------------------------------------------------------

    /**
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    public function copy(DavNode $source, string $destination, bool $overwrite, int $depth): array
    {
        if ($source->kind === DavNode::KIND_ROOT) {
            return self::fail(403);
        }
        if (DavPath::isAtOrBelow($destination, $source->path)) {
            return self::fail(409);
        }

        return $source->isCollection
            ? $this->copyCollection($source, $destination, $overwrite, $depth)
            : $this->copyFile($source, $destination, $overwrite);
    }

    /**
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function copyFile(DavNode $source, string $destination, bool $overwrite): array
    {
        $filename = $source->filename;
        if ($filename === null) {
            // Nothing behind the name yet; there is no content to copy.
            return self::fail(403);
        }

        $parent = $this->tree->resolveParentCollection($destination);
        if ($parent === null) {
            return self::fail(409);
        }
        if (!$this->isWritableCollection($parent)) {
            return self::fail(403);
        }
        if (count(DavPath::segments($destination)) !== 2) {
            return self::fail(409);
        }

        $targetAlbumId = (int)$parent->albumId;

        // Two names for one image inside one album would break the invariant
        // that reconciliation relies on (one mapping per album per image), and
        // dedupe makes a genuine second copy impossible anyway.
        if ($targetAlbumId === (int)$source->albumId) {
            return self::fail(403);
        }

        $name = DavPath::basename($destination);
        $conflict = $this->tree->resolve($destination);
        if ($conflict !== null) {
            if (!$overwrite) {
                return self::fail(412);
            }
            if ($conflict->isCollection) {
                return self::fail(403);
            }
            $replaced = $this->delete($conflict);
            if ($replaced['code'] >= 300) {
                return $replaced;
            }
        }

        if ($targetAlbumId > 0) {
            $this->albums->addImages($targetAlbumId, [$filename]);
        } else {
            // Copying into 未分类 means "belong to no album", which is the
            // absence of membership rather than a row to add. Removing it from
            // every album would be a move, not a copy, so refuse.
            return self::fail(403);
        }

        if ($this->entries->insert($targetAlbumId, $name, $filename) === null) {
            $existing = $this->entries->findByName($targetAlbumId, $name);
            if ($existing === null) {
                return self::fail(409);
            }
            $this->entries->updateFilename($existing['id'], $filename);
        }

        return $conflict === null
            ? self::OK_CREATED
            : ['code' => 204, 'condition' => '', 'headers' => []];
    }

    /**
     * Copying an album folder creates a new album holding the same images.
     *
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private function copyCollection(DavNode $source, string $destination, bool $overwrite, int $depth): array
    {
        if ($depth === 0) {
            // Depth 0 means "the collection itself, no members". An empty album
            // is just a rename-shaped create — refuse rather than invent one.
            return self::fail(403);
        }
        if ($source->kind !== DavNode::KIND_ALBUM && $source->kind !== DavNode::KIND_UNFILED) {
            return self::fail(403);
        }

        $segments = DavPath::segments($destination);
        if (count($segments) !== 1) {
            return self::fail(403);
        }
        $name = $segments[0];
        if (isset(DavName::reservedTopLevel()[$name])) {
            return self::fail(403);
        }
        if ($this->tree->resolve($destination) !== null) {
            return self::fail($overwrite ? 403 : 412);
        }

        $created = $this->albums->create(['name' => $name, 'visibility' => 'private']);
        if (!is_int($created)) {
            return self::fail(409);
        }
        $this->tree->forgetAlbums();
        if ($this->tree->folderNameForAlbum($created) !== $name) {
            $this->albums->delete($created);
            $this->tree->forgetAlbums();
            return self::fail(403);
        }

        $filenames = [];
        foreach ($this->entries->listByAlbum((int)$source->albumId, DavConfig::maxListing()) as $entry) {
            if ($entry['filename'] !== null) {
                $filenames[] = $entry['filename'];
            }
        }
        if ($filenames !== []) {
            $this->albums->addImages($created, $filenames);
            $this->index->reconcile($created, DavConfig::maxListing());
        }

        return self::OK_CREATED;
    }

    // ---- helpers -------------------------------------------------------------

    private function isWritableCollection(DavNode $node): bool
    {
        return $node->isCollection
            && $node->writable
            && ($node->kind === DavNode::KIND_ALBUM || $node->kind === DavNode::KIND_UNFILED);
    }

    /**
     * True when any dav_entries row OR any album membership still points at
     * $filename. Either is enough to keep the bytes around after a name is
     * removed — COPY leaves both kinds of reference behind.
     */
    private function filenameStillReferenced(string $filename): bool
    {
        if ($this->filenameStillMapped($filename)) {
            return true;
        }
        $pdo = \LitePic\Core\Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM album_images WHERE filename = :fn LIMIT 1');
        $stmt->execute([':fn' => $filename]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Read and throw away a request body. Skipping it entirely can leave
     * unread bytes in the connection, which makes keep-alive clients treat the
     * next response as garbage.
     */
    private static function discardBody(): void
    {
        // Drain php://input in bounded chunks so keep-alive clients don't see
        // leftover bytes as the next response.
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return;
        }
        while (!feof($stream)) {
            if (fread($stream, 8192) === false) {
                break;
            }
        }
        fclose($stream);
    }

    /**
     * @return array{code:int,condition:string,headers:array<string,string>}
     */
    private static function fail(int $code, string $condition = ''): array
    {
        return ['code' => $code, 'condition' => $condition, 'headers' => []];
    }
}

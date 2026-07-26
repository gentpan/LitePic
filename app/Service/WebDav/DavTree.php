<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Repository\AlbumRepository;
use LitePic\Repository\DavDateRepository;
use LitePic\Repository\DavEntryRepository;
use LitePic\Repository\ImageRepository;

/**
 * The virtual filesystem: internal path → {@see DavNode}, and collection → children.
 *
 * Shape of the tree:
 *
 *   /                    mount root
 *   /<album>             one folder per album, writable
 *   /未分类               images belonging to no album, writable
 *   /按日期/2026/07       read-only mirror of the on-disk layout
 *
 * Album folder names are *derived*, not stored. Album titles are free text and
 * not unique, so the mapping is computed by walking albums in id order and
 * letting the lowest id keep the plain name; later collisions get an id suffix
 * (see {@see DavName::forAlbum()}). Deriving it in id order rather than storing
 * it is what makes the name stable: renaming an album in the web UI changes its
 * folder immediately, and no separate row can fall out of sync with the title.
 *
 * Instances are per-request and cache the album folder map, since resolving any
 * path below the root needs it.
 */
final class DavTree
{
    private AlbumRepository $albums;
    private DavEntryRepository $entries;
    private DavDateRepository $dates;
    private ImageRepository $images;
    private DavIndex $index;

    /** @var array<string,array<string,mixed>>|null folder name => album row */
    private ?array $foldersByName = null;

    /** @var array<int,string>|null album id => folder name */
    private ?array $namesByAlbumId = null;

    /** @var array<int,true> Collections already reconciled during this request. */
    private array $reconciled = [];

    public function __construct(
        ?AlbumRepository $albums = null,
        ?DavEntryRepository $entries = null,
        ?DavDateRepository $dates = null,
        ?ImageRepository $images = null,
        ?DavIndex $index = null
    ) {
        $this->albums = $albums ?? new AlbumRepository();
        $this->entries = $entries ?? new DavEntryRepository();
        $this->dates = $dates ?? new DavDateRepository();
        $this->images = $images ?? new ImageRepository();
        $this->index = $index ?? new DavIndex($this->entries);
    }

    // ---- album folder naming --------------------------------------------------

    /**
     * @return array<string,array<string,mixed>> folder name => album row
     */
    public function albumFolders(): array
    {
        if ($this->foldersByName !== null) {
            return $this->foldersByName;
        }

        $albums = $this->albums->all();
        usort($albums, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        $taken = DavName::reservedTopLevel();
        $byName = [];
        $byId = [];
        foreach ($albums as $album) {
            $id = (int)($album['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = DavName::forAlbum($id, (string)($album['name'] ?? ''), $taken);
            $taken[$name] = true;
            $byName[$name] = $album;
            $byId[$id] = $name;
        }

        $this->foldersByName = $byName;
        $this->namesByAlbumId = $byId;
        return $byName;
    }

    public function folderNameForAlbum(int $albumId): ?string
    {
        if ($albumId === 0) {
            return DavPath::DIR_UNFILED;
        }
        $this->albumFolders();
        return $this->namesByAlbumId[$albumId] ?? null;
    }

    /** Internal path of the collection an image lives in, for a given album. */
    public function collectionPathForAlbum(int $albumId): ?string
    {
        $name = $this->folderNameForAlbum($albumId);
        return $name === null ? null : '/' . $name;
    }

    /**
     * Discard cached naming after a MKCOL / MOVE / DELETE changed the album set,
     * so a follow-up resolve in the same request sees the new folder.
     */
    public function forgetAlbums(): void
    {
        $this->foldersByName = null;
        $this->namesByAlbumId = null;
    }

    // ---- resolution ----------------------------------------------------------

    /**
     * The node at $path, or null when nothing is there (→ 404).
     */
    public function resolve(string $path): ?DavNode
    {
        $segments = DavPath::segments($path);
        $depth = count($segments);

        if ($depth === 0) {
            return DavNode::root();
        }

        $first = $segments[0];

        if ($first === DavPath::DIR_DATE) {
            return $this->resolveDate(array_slice($segments, 1));
        }

        if ($first === DavPath::DIR_UNFILED) {
            if ($depth === 1) {
                return DavNode::unfiled();
            }
            return $depth === 2 ? $this->resolveFileIn(0, DavPath::DIR_UNFILED, $segments[1]) : null;
        }

        $folders = $this->albumFolders();
        if (!isset($folders[$first])) {
            return null;
        }
        $album = $folders[$first];

        if ($depth === 1) {
            return DavNode::album('/' . $first, $first, $album);
        }
        if ($depth === 2) {
            return $this->resolveFileIn((int)$album['id'], $first, $segments[1]);
        }
        // Albums are flat: no nesting below an album folder.
        return null;
    }

    /**
     * The collection a path *would* live in, whether or not the path itself
     * exists. Used by PUT / MKCOL / MOVE to validate the parent before writing.
     */
    public function resolveParentCollection(string $path): ?DavNode
    {
        return $this->resolve(DavPath::parent($path));
    }

    /**
     * @param string[] $rest Segments after `按日期`.
     */
    private function resolveDate(array $rest): ?DavNode
    {
        $depth = count($rest);
        if ($depth === 0) {
            return DavNode::dateRoot();
        }

        $year = $rest[0];
        if (preg_match('/^[0-9]{4}$/', $year) !== 1 || !$this->dates->yearExists($year)) {
            return null;
        }
        if ($depth === 1) {
            return DavNode::dateYear($year);
        }

        $month = $rest[1];
        if (preg_match('/^[0-9]{2}$/', $month) !== 1 || !$this->dates->monthExists($year, $month)) {
            return null;
        }
        if ($depth === 2) {
            return DavNode::dateMonth($year, $month);
        }
        if ($depth !== 3) {
            return null;
        }

        // The date view addresses images by their stored identifier, so the leaf
        // name is the system filename rather than a mapped display name.
        $identifier = $year . '/' . $month . '/' . $rest[2];
        $image = $this->images->find($identifier);
        if ($image === null) {
            return null;
        }

        return DavNode::file(
            '/' . DavPath::DIR_DATE . '/' . $identifier,
            $rest[2],
            $identifier,
            $image,
            null,
            null,
            false
        );
    }

    /**
     * A mapped file inside a writable collection.
     */
    private function resolveFileIn(int $albumId, string $folderName, string $name): ?DavNode
    {
        $entry = $this->entries->findByName($albumId, $name);

        if ($entry === null) {
            // A client may GET a file it never listed — for instance rclone
            // copying a known path. One reconciliation pass per collection per
            // request makes that work without turning every miss (Finder probes
            // a lot of names that never existed) into extra queries.
            if (!DavQuirks::looksLikeImageName($name) || isset($this->reconciled[$albumId])) {
                return null;
            }
            $this->reconcileOnce($albumId);
            $entry = $this->entries->findByName($albumId, $name);
            if ($entry === null) {
                return null;
            }
        }

        $path = '/' . $folderName . '/' . $name;

        if ($entry['filename'] === null) {
            $node = DavNode::file($path, $name, null, null, $entry['id'], $albumId);
            $node->modifiedAt = $entry['updated_at'];
            $node->createdAt = $entry['created_at'];
            return $node;
        }

        $image = $this->images->find($entry['filename']);
        if ($image === null) {
            // The foreign key should have removed this row with the image. If it
            // somehow survived, drop it rather than serve a broken resource.
            $this->entries->delete($entry['id']);
            return null;
        }

        return DavNode::file($path, $name, $entry['filename'], $image, $entry['id'], $albumId);
    }

    // ---- listing -------------------------------------------------------------

    /**
     * Children of a collection, capped at {@see DavConfig::maxListing()}.
     * Returns an empty array for files and for unknown kinds.
     *
     * @return DavNode[]
     */
    public function children(DavNode $node): array
    {
        switch ($node->kind) {
            case DavNode::KIND_ROOT:
                return $this->rootChildren();
            case DavNode::KIND_ALBUM:
            case DavNode::KIND_UNFILED:
                return $this->collectionChildren((int)$node->albumId, $node->name);
            case DavNode::KIND_DATE:
                return array_map(
                    static fn (string $year): DavNode => DavNode::dateYear($year),
                    $this->dates->years()
                );
            case DavNode::KIND_DATE_YEAR:
                $year = $node->name;
                return array_map(
                    static fn (string $month): DavNode => DavNode::dateMonth($year, $month),
                    $this->dates->months($year)
                );
            case DavNode::KIND_DATE_MONTH:
                return $this->dateMonthChildren($node);
            default:
                return [];
        }
    }

    /**
     * @return DavNode[]
     */
    private function rootChildren(): array
    {
        $children = [];
        foreach ($this->albumFolders() as $name => $album) {
            $children[] = DavNode::album('/' . $name, $name, $album);
        }
        // Sorted so a client's folder list doesn't reshuffle between requests
        // just because an album's sort_order changed in the admin UI.
        usort($children, static fn (DavNode $a, DavNode $b): int => strcasecmp($a->name, $b->name));

        $children[] = DavNode::unfiled();
        $children[] = DavNode::dateRoot();
        return $children;
    }

    /**
     * @return DavNode[]
     */
    private function collectionChildren(int $albumId, string $folderName): array
    {
        $limit = DavConfig::maxListing();
        $this->reconcileOnce($albumId);

        $rows = $this->entries->listByAlbum($albumId, $limit);
        if ($rows === []) {
            return [];
        }

        $filenames = [];
        foreach ($rows as $row) {
            if ($row['filename'] !== null) {
                $filenames[] = $row['filename'];
            }
        }
        $imagesByName = $filenames === [] ? [] : $this->images->findMany($filenames);

        $children = [];
        foreach ($rows as $row) {
            $path = '/' . $folderName . '/' . $row['name'];

            if ($row['filename'] === null) {
                $node = DavNode::file($path, $row['name'], null, null, $row['id'], $albumId);
                $node->modifiedAt = $row['updated_at'];
                $node->createdAt = $row['created_at'];
                $children[] = $node;
                continue;
            }

            $image = $imagesByName[$row['filename']] ?? null;
            if ($image === null) {
                continue;
            }
            $children[] = DavNode::file($path, $row['name'], $row['filename'], $image, $row['id'], $albumId);
        }

        return $children;
    }

    /**
     * @return DavNode[]
     */
    private function dateMonthChildren(DavNode $node): array
    {
        $segments = DavPath::segments($node->path);
        // /按日期/YYYY/MM
        if (count($segments) !== 3) {
            return [];
        }
        [, $year, $month] = $segments;

        $children = [];
        foreach ($this->dates->imagesIn($year, $month, DavConfig::maxListing()) as $image) {
            $identifier = (string)$image['filename'];
            $name = DavPath::basename('/' . $identifier);
            $children[] = DavNode::file(
                '/' . DavPath::DIR_DATE . '/' . $identifier,
                $name,
                $identifier,
                $image,
                null,
                null,
                false
            );
        }
        return $children;
    }

    private function reconcileOnce(int $albumId): void
    {
        if (isset($this->reconciled[$albumId])) {
            return;
        }
        $this->reconciled[$albumId] = true;
        $this->index->reconcile($albumId, DavConfig::maxListing());
    }
}

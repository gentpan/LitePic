<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Core\Logger;
use LitePic\Service\Image\PathService;

/**
 * Method dispatch for the WebDAV mount, and the read-side handlers.
 *
 * Mutations are delegated to {@see DavWriter}; what stays here is everything
 * that answers rather than changes — OPTIONS, PROPFIND, PROPPATCH, GET/HEAD —
 * plus the two cross-cutting gates every write has to pass first: the read-only
 * switch and the lock check.
 *
 * Locking is enforced in one place, at dispatch, rather than inside each write
 * handler. A missed check in one branch is precisely the kind of bug that shows
 * up as two clients silently clobbering each other's uploads, so there is a
 * single list of "methods that write" and a single place that consults the lock
 * table for them.
 */
final class DavServer
{
    /** Methods advertised in `Allow` and `DAV`. */
    private const METHODS = [
        'OPTIONS', 'GET', 'HEAD', 'PUT', 'DELETE',
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK',
    ];

    /** Comma-separated Allow header value — one source for dav.php and OPTIONS. */
    public static function allowHeader(): string
    {
        return implode(', ', self::METHODS);
    }

    /** Methods that mutate, and therefore need the lock and read-only gates. */
    private const WRITE_METHODS = ['PUT', 'DELETE', 'MKCOL', 'MOVE', 'COPY', 'PROPPATCH'];

    /**
     * Properties PROPPATCH accepts. Windows Explorer sets the Win32 timestamps
     * during a copy and treats a 403 as a failed transfer; the values are
     * discarded because the store has no room for them, but reporting success
     * is what keeps a copy from being rolled back.
     */
    private const ACCEPTED_PROPPATCH = [
        '{urn:schemas-microsoft-com:}Win32CreationTime',
        '{urn:schemas-microsoft-com:}Win32LastModifiedTime',
        '{urn:schemas-microsoft-com:}Win32LastAccessTime',
        '{urn:schemas-microsoft-com:}Win32FileAttributes',
    ];

    private DavTree $tree;
    private DavWriter $writer;
    private DavLockManager $locks;
    private DavProperties $properties;
    private DavIndex $index;

    public function __construct(
        ?DavTree $tree = null,
        ?DavWriter $writer = null,
        ?DavLockManager $locks = null,
        ?DavProperties $properties = null,
        ?DavIndex $index = null
    ) {
        $this->tree = $tree ?? new DavTree();
        $this->locks = $locks ?? new DavLockManager();
        $this->writer = $writer ?? new DavWriter($this->tree);
        $this->properties = $properties ?? new DavProperties($this->locks);
        $this->index = $index ?? new DavIndex();
    }

    public function writer(): DavWriter
    {
        return $this->writer;
    }

    /**
     * Handle one authenticated request. Emits the full response.
     */
    public function handle(DavRequest $request): void
    {
        // Lazily expire locks and abandoned placeholders. Doing it here rather
        // than on a schedule means timeouts are honoured on installs with no cron.
        $this->locks->purgeExpired();
        $this->index->purgeStalePlaceholders();

        $method = $request->method();
        $path = $request->path();

        if (!in_array($method, self::METHODS, true)) {
            DavResponse::emitStatus(405, ['Allow' => self::allowHeader()]);
            return;
        }

        if (in_array($method, self::WRITE_METHODS, true)) {
            if (DavConfig::readOnly()) {
                DavResponse::emitError(403, '');
                return;
            }
            if (!$this->passesLockGate($request, $method, $path)) {
                return;
            }
        }

        switch ($method) {
            case 'OPTIONS':
                $this->handleOptions();
                return;
            case 'PROPFIND':
                $this->handlePropfind($request, $path);
                return;
            case 'PROPPATCH':
                $this->handleProppatch($request, $path);
                return;
            case 'GET':
            case 'HEAD':
                $this->handleGet($request, $path, $method === 'HEAD');
                return;
            case 'PUT':
                $this->emit($this->writer->put($request, $path));
                return;
            case 'DELETE':
                $this->handleDelete($path);
                return;
            case 'MKCOL':
                $this->emit($this->writer->mkcol($request, $path));
                return;
            case 'MOVE':
            case 'COPY':
                $this->handleMoveOrCopy($request, $path, $method === 'MOVE');
                return;
            case 'LOCK':
                $this->handleLock($request, $path);
                return;
            case 'UNLOCK':
                $this->handleUnlock($request);
                return;
        }
    }

    // ---- lock gate -----------------------------------------------------------

    /**
     * False when a lock blocks the request; the response has already been sent.
     */
    private function passesLockGate(DavRequest $request, string $method, string $path): bool
    {
        $tokens = $request->submittedLockTokens();

        // Removing or moving a collection requires its whole subtree to be free.
        $blocking = in_array($method, ['DELETE', 'MOVE'], true)
            ? $this->locks->blockingLockInSubtree($path, $tokens)
            : $this->locks->blockingLock($path, $tokens);

        if ($blocking === null && ($method === 'MOVE' || $method === 'COPY')) {
            $destination = $request->destination();
            if ($destination !== null) {
                $blocking = $this->locks->blockingLock($destination, $tokens);
            }
        }

        if ($blocking === null) {
            return true;
        }

        DavResponse::emitError(423, 'lock-token-submitted');
        return false;
    }

    // ---- OPTIONS -------------------------------------------------------------

    /**
     * Advertise DAV class 1 and 2. Finder mounts read-only without class 2, and
     * `MS-Author-Via` is what stops Windows Explorer from deciding the URL is a
     * plain web page rather than a share.
     */
    private function handleOptions(): void
    {
        DavResponse::emitStatus(200, [
            'DAV' => '1, 2',
            'MS-Author-Via' => 'DAV',
            'Allow' => self::allowHeader(),
            'Accept-Ranges' => 'bytes',
        ]);
    }

    // ---- PROPFIND ------------------------------------------------------------

    private function handlePropfind(DavRequest $request, string $path): void
    {
        $node = $this->tree->resolve($path);
        if ($node === null) {
            DavResponse::emitStatus(404);
            return;
        }

        $depth = $request->depth(1);
        if ($depth === -1) {
            // An unbounded walk of the whole library is not something to serve on
            // request. RFC 4918 §9.1 provides this exact precondition for saying so.
            DavResponse::emitError(403, 'propfind-finite-depth');
            return;
        }

        $parsed = DavXml::parsePropfind($request->xmlBody());

        $entries = [$this->properties->describe($node, $parsed['mode'], $parsed['props'])];

        if ($depth === 1 && $node->isCollection) {
            foreach ($this->tree->children($node) as $child) {
                $entries[] = $this->properties->describe($child, $parsed['mode'], $parsed['props']);
            }
        }

        DavResponse::emitMultiStatus($entries);
    }

    // ---- PROPPATCH -----------------------------------------------------------

    private function handleProppatch(DavRequest $request, string $path): void
    {
        $node = $this->tree->resolve($path);
        if ($node === null) {
            DavResponse::emitStatus(404);
            return;
        }
        if (!$node->writable) {
            DavResponse::emitError(403, '');
            return;
        }

        $parsed = DavXml::parsePropPatch($request->xmlBody());
        $names = array_merge($parsed['set'], $parsed['remove']);
        if ($names === []) {
            DavResponse::emitMultiStatus([['href' => $node->href(), 'found' => [], 'notFound' => []]]);
            return;
        }

        $accepted = [];
        $refused = [];
        foreach ($names as $name) {
            if (in_array($name, self::ACCEPTED_PROPPATCH, true)) {
                $accepted[$name] = '';
            } else {
                $refused[] = $name;
            }
        }

        // Two propstat blocks, 200 for what was swallowed and 403 for the rest —
        // which is the shape clients parse, unlike a bare status response.
        $entries = [];
        if ($accepted !== []) {
            $entries[] = ['href' => $node->href(), 'found' => $accepted, 'notFound' => []];
        }
        foreach ($refused as $name) {
            $entries[] = ['href' => $node->href(), 'status' => 403];
        }
        DavResponse::emitMultiStatus($entries);
    }

    // ---- GET / HEAD ----------------------------------------------------------

    private function handleGet(DavRequest $request, string $path, bool $headOnly): void
    {
        $node = $this->tree->resolve($path);
        if ($node === null) {
            DavResponse::emitStatus(404);
            return;
        }

        if ($node->isCollection) {
            $this->emitDirectoryIndex($node, $headOnly);
            return;
        }

        if ($node->isPlaceholder()) {
            // The name exists with no bytes behind it yet.
            http_response_code(200);
            header('Content-Type: application/octet-stream');
            header('Content-Length: 0');
            return;
        }

        $file = PathService::resolveFilePath((string)$node->filename);
        if (!is_file($file)) {
            Logger::warning('WebDAV GET missing file on disk', ['path' => $node->path]);
            DavResponse::emitStatus(404);
            return;
        }

        $this->streamFile($request, $node, $file, $headOnly);
    }

    /**
     * A browsable HTML listing for collections.
     *
     * WebDAV clients never ask for this — they use PROPFIND — but a browser
     * pointed at `/dav/` does, and an HTML index turns "did the mount work?"
     * into something answerable without a WebDAV client at hand.
     */
    private function emitDirectoryIndex(DavNode $node, bool $headOnly): void
    {
        $rows = '';
        if ($node->path !== '/') {
            $rows .= '<li><a href="' . DavResponse::escape(DavPath::href(DavPath::parent($node->path), true)) . '">../</a></li>';
        }
        foreach ($this->tree->children($node) as $child) {
            $label = $child->name . ($child->isCollection ? '/' : '');
            $rows .= '<li><a href="' . DavResponse::escape($child->href()) . '">'
                . DavResponse::escape($label) . '</a></li>';
        }

        $title = DavResponse::escape($node->path === '/' ? 'LitePic WebDAV' : $node->path);
        $body = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $title . '</title>'
            . '<style>body{font:14px/1.7 system-ui,sans-serif;margin:2rem;max-width:52rem}'
            . 'h1{font-size:1.1rem;font-weight:600}ul{list-style:none;padding:0}'
            . 'a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}</style>'
            . '</head><body><h1>' . $title . '</h1><ul>' . $rows . '</ul></body></html>';

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        if (!$headOnly) {
            echo $body;
        }
    }

    /**
     * Send a stored image, honouring a single `Range`.
     *
     * Range support is not optional in practice: Finder's Quick Look and the
     * macOS thumbnailer both fetch the first few kilobytes of an image before
     * deciding to read the rest, and a server that answers 200-with-everything
     * makes browsing a folder of large photos download the whole folder.
     */
    private function streamFile(DavRequest $request, DavNode $node, string $file, bool $headOnly): void
    {
        $size = (int)@filesize($file);
        $contentType = $node->contentType !== '' ? $node->contentType : 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Accept-Ranges: bytes');
        header('ETag: ' . $node->etag());
        header('Last-Modified: ' . DavResponse::httpDate($node->modifiedAt));
        // The mount is authenticated; a shared cache must never keep these.
        header('Cache-Control: private, no-store');

        $start = 0;
        $end = $size - 1;
        $partial = false;

        $range = $request->header('Range');
        if ($range !== null && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) === 1) {
            $rawStart = $m[1];
            $rawEnd = $m[2];

            if ($rawStart === '' && $rawEnd === '') {
                DavResponse::emitStatus(416, ['Content-Range' => 'bytes */' . $size]);
                return;
            }
            if ($rawStart === '') {
                // Suffix range: the last N bytes.
                $length = (int)$rawEnd;
                $start = max(0, $size - $length);
            } else {
                $start = (int)$rawStart;
                $end = $rawEnd === '' ? $size - 1 : (int)$rawEnd;
            }
            $end = min($end, $size - 1);

            if ($start > $end || $start >= $size) {
                DavResponse::emitStatus(416, ['Content-Range' => 'bytes */' . $size]);
                return;
            }
            $partial = true;
        }

        $length = $size === 0 ? 0 : $end - $start + 1;

        if ($partial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }
        header('Content-Length: ' . $length);

        if ($headOnly || $length === 0) {
            return;
        }

        // Drop any buffering so a large image isn't assembled in memory first.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        if ($start > 0) {
            fseek($handle, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, (int)min(262144, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
    }

    // ---- DELETE / MOVE / COPY ------------------------------------------------

    private function handleDelete(string $path): void
    {
        $node = $this->tree->resolve($path);
        if ($node === null) {
            // A client deleting an OS scratch file it thinks it wrote should not
            // see an error — the file was absorbed, so "gone" is the truth.
            if (DavQuirks::isJunkPath($path)) {
                DavResponse::emitStatus(204);
                return;
            }
            DavResponse::emitStatus(404);
            return;
        }

        $result = $this->writer->delete($node);
        if ($result['code'] < 300) {
            $this->locks->releaseSubtree($path);
        }
        $this->emit($result);
    }

    private function handleMoveOrCopy(DavRequest $request, string $path, bool $isMove): void
    {
        $node = $this->tree->resolve($path);
        if ($node === null) {
            DavResponse::emitStatus(404);
            return;
        }

        if (!$request->hasDestinationHeader()) {
            DavResponse::emitStatus(400);
            return;
        }
        $destination = $request->destination();
        if ($destination === null) {
            // Destination outside this mount — §9.9.4 calls for 502.
            DavResponse::emitStatus(502);
            return;
        }
        if ($destination === $path) {
            DavResponse::emitError(403, '');
            return;
        }

        $overwrite = $request->overwrite();

        if ($isMove) {
            $result = $this->writer->move($node, $destination, $overwrite);
            if ($result['code'] < 300) {
                $this->locks->relocate($path, $destination);
            }
        } else {
            $result = $this->writer->copy($node, $destination, $overwrite, $request->depth(-1));
        }

        $this->emit($result);
    }

    // ---- LOCK / UNLOCK -------------------------------------------------------

    private function handleLock(DavRequest $request, string $path): void
    {
        if (DavConfig::readOnly()) {
            DavResponse::emitError(403, '');
            return;
        }

        $info = DavXml::parseLockInfo($request->xmlBody());
        $timeout = $request->lockTimeout(DavConfig::LOCK_DEFAULT_TIMEOUT, DavConfig::LOCK_MAX_TIMEOUT);

        // Empty body = refresh the lock named in the If header.
        if ($info['refresh']) {
            $tokens = $request->submittedLockTokens();
            foreach ($tokens as $token) {
                $refreshed = $this->locks->refresh($token, $timeout);
                if ($refreshed !== null) {
                    $this->emitLock($refreshed, 200);
                    return;
                }
            }
            DavResponse::emitStatus(412);
            return;
        }

        $node = $this->tree->resolve($path);
        $created = false;

        if ($node === null) {
            // Finder / Explorer lock-then-write junk (.DS_Store, desktop.ini…).
            // PUT absorbs these; LOCK must too — returning 201 from prepareLockNull
            // without a node used to fall through to a 500 below.
            if (DavQuirks::isJunkPath($path)) {
                $blocking = $this->locks->blockingLock($path, $request->submittedLockTokens());
                if ($blocking !== null) {
                    DavResponse::emitError(423, 'no-conflicting-lock');
                    return;
                }
                $lock = $this->locks->create($path, 0, $info['owner'], $timeout);
                $stored = $this->locks->find($lock['token']);
                if ($stored === null) {
                    DavResponse::emitStatus(500);
                    return;
                }
                $this->emitLock($stored, 201);
                return;
            }

            // Locking a path that doesn't exist yet is the normal opening move
            // for Windows Explorer and Finder: lock, then PUT. RFC 4918 §7.3
            // calls the result a lock-null resource; here it is backed by the
            // same empty placeholder a 0-byte PUT would create.
            $prepared = $this->prepareLockNull($path);
            if ($prepared !== 201) {
                DavResponse::emitStatus($prepared);
                return;
            }
            $created = true;
            $node = $this->tree->resolve($path);
            if ($node === null) {
                DavResponse::emitStatus(500);
                return;
            }
        }

        if (!$node->writable) {
            DavResponse::emitError(403, '');
            return;
        }

        $blocking = $this->locks->blockingLock($path, $request->submittedLockTokens());
        if ($blocking !== null) {
            DavResponse::emitError(423, 'no-conflicting-lock');
            return;
        }

        $depth = $node->isCollection ? $request->depth(-1) : 0;
        $lock = $this->locks->create($path, $depth, $info['owner'], $timeout);
        $stored = $this->locks->find($lock['token']);
        if ($stored === null) {
            DavResponse::emitStatus(500);
            return;
        }

        $this->emitLock($stored, $created ? 201 : 200);
    }

    /**
     * Create the empty placeholder that backs a lock on a not-yet-existing path.
     *
     * @return int 201 on success, otherwise the status to report.
     */
    private function prepareLockNull(string $path): int
    {
        if (count(DavPath::segments($path)) !== 2) {
            return 409;
        }

        $parent = $this->tree->resolveParentCollection($path);
        if ($parent === null) {
            return 409;
        }
        if (!$parent->isCollection || !$parent->writable
            || !in_array($parent->kind, [DavNode::KIND_ALBUM, DavNode::KIND_UNFILED], true)) {
            return 403;
        }

        $entries = new \LitePic\Repository\DavEntryRepository();
        return $entries->insert((int)$parent->albumId, DavPath::basename($path), null) === null ? 409 : 201;
    }

    /**
     * @param array<string,mixed> $lock
     */
    private function emitLock(array $lock, int $code): void
    {
        $body = DavLockManager::lockResponseXml($lock);
        http_response_code($code);
        header('Content-Type: application/xml; charset=utf-8');
        header('Lock-Token: <' . (string)$lock['token'] . '>');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function handleUnlock(DavRequest $request): void
    {
        $header = $request->header('Lock-Token');
        if ($header === null) {
            DavResponse::emitStatus(400);
            return;
        }
        if (preg_match('/<([^>]+)>/', $header, $m) !== 1) {
            DavResponse::emitStatus(400);
            return;
        }

        $token = trim($m[1]);
        if ($this->locks->find($token) === null) {
            // §9.11.1: unlocking a token the server doesn't hold is a conflict.
            DavResponse::emitError(409, 'lock-token-matches-request-uri');
            return;
        }

        $this->locks->release($token);
        DavResponse::emitStatus(204);
    }

    // ---- emit ----------------------------------------------------------------

    /**
     * @param array{code:int,condition:string,headers:array<string,string>} $result
     */
    private function emit(array $result): void
    {
        $code = $result['code'];
        if ($code >= 400 && $result['condition'] !== '') {
            DavResponse::emitError($code, $result['condition'], $result['headers']);
            return;
        }
        DavResponse::emitStatus($code, $result['headers']);
    }
}

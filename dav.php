<?php
declare(strict_types=1);

/**
 * WebDAV endpoint — everything under `/dav`.
 *
 * Mount LitePic as a network drive: albums appear as folders, dropping an image
 * in uploads it through the same pipeline as the web uploader, and `按日期` is a
 * read-only mirror of the on-disk layout. See {@see \LitePic\Service\WebDav\DavTree}
 * for the tree and {@see \LitePic\Service\WebDav\DavServer} for the protocol.
 *
 * Disabled by default. When `WEBDAV_ENABLED` is off the endpoint answers 404
 * rather than 403 or 503, so a scan of a LitePic install cannot tell whether the
 * feature exists and is switched off or was never compiled in — there is nothing
 * to be gained from confirming an unauthenticated prober's guess.
 */

require __DIR__ . '/bootstrap.php';

use LitePic\Core\Logger;
use LitePic\Service\Image\ImageProcessor;
use LitePic\Service\WebDav\DavAuth;
use LitePic\Service\WebDav\DavConfig;
use LitePic\Service\WebDav\DavRequest;
use LitePic\Service\WebDav\DavResponse;
use LitePic\Service\WebDav\DavServer;

if (!DavConfig::usable()) {
    http_response_code(404);
    header('Content-Length: 0');
    exit;
}

// Responses carry private, authenticated content and are streamed; neither a
// shared cache nor gzip buffering has any business in the middle.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
// Stop the client upgrading this connection to HTTP/3 — Finder's WebDAVFS
// often dies on h3 with the same vague "problem connecting" dialog.
header('Alt-Svc: clear');
@ini_set('zlib.output_compression', 'Off');

$request = DavRequest::capture();
if ($request === null) {
    DavResponse::emitStatus(400);
    exit;
}

// An unauthenticated OPTIONS is answered so clients can discover DAV support
// before prompting for a password — Windows Explorer in particular gives up on
// a share whose OPTIONS it never saw. It reveals only that WebDAV is here,
// which any authenticated user already knows.
if ($request->method() === 'OPTIONS') {
    DavResponse::emitStatus(200, [
        'DAV' => '1, 2',
        'MS-Author-Via' => 'DAV',
        'Allow' => DavServer::allowHeader(),
        'Accept-Ranges' => 'bytes',
    ]);
    exit;
}

switch ((new DavAuth())->authenticate($request)) {
    case 'ok':
        break;
    case 'throttled':
        DavResponse::emitStatus(429, ['Retry-After' => '300']);
        exit;
    default:
        DavAuth::emitChallenge();
        exit;
}

$server = new DavServer();

try {
    $server->handle($request);
} catch (\Throwable $e) {
    Logger::error('WebDAV request failed', [
        'method' => $request->method(),
        'path' => $request->path(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    if (!headers_sent()) {
        DavResponse::emitStatus(500);
    }
    exit;
}

// A PUT only stores the original; thumbnails, compression, format conversion and
// remote sync are queued. Draining a couple of tasks after the response is
// flushed means a single drag-and-drop is fully processed even on installs with
// no cron, without making the client wait for it.
if ($server->writer()->storedSomething()) {
    register_shutdown_function(static function (): void {
        try {
            ImageProcessor::drain(2, 8);
        } catch (\Throwable $e) {
            Logger::warning('WebDAV post-upload drain failed', ['error' => $e->getMessage()]);
        }
    });
}

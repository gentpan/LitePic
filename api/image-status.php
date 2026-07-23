<?php
declare(strict_types=1);

/**
 * GET /api/v1/image-status?ids=2026/05/abc.jpg,2026/05/def.png
 *
 * Polled by the upload page after a successful upload to track when
 * the async processing pipeline (thumb / compress / WebP / AVIF /
 * watermark / remote sync) finishes for each image.
 *
 * Per-image response shape:
 *   {
 *     "filename":          "2026/05/abc.jpg",   // requested identifier
 *     "exists":            true,                // is the source file still on disk?
 *     "queue_state":       "pending"|"processing"|"done",
 *     "queue_attempts":    0,                   // retry count if it failed once
 *     "queue_last_error":  null|"...",
 *     "current_filename":  "2026/05/abc.webp",  // post-conversion identifier (changes after webp/avif)
 *     "current_url":       "http://.../i/...webp",
 *     "thumb_ready":       true,
 *     "thumb_url":         "http://.../i/...thumb.jpg",
 *     "has_webp":          true,
 *     "has_avif":          false
 *   }
 *
 * Auth: same as upload — admin cookie OR upload token. Polling is
 * cheap (one SELECT + a few file_exists per id) but not anonymous.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    \LitePic\Core\Response::error('仅支持 GET 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->hasUploadApiAccess()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$idsRaw = (string)($_GET['ids'] ?? '');
if ($idsRaw === '') {
    \LitePic\Core\Response::success(['items' => []]);
}

// Cap how many IDs a single request can ask about — protects against
// a runaway client polling 10000 ids and pinning the DB
$ids = array_slice(
    array_filter(
        array_map(static fn ($s) => trim((string)$s), explode(',', $idsRaw)),
        static fn ($s) => $s !== ''
    ),
    0, 100
);

$pathSvc = '\\LitePic\\Service\\Image\\PathService';
$urlSvc  = '\\LitePic\\Service\\Image\\ImageUrl';
$repo    = new \LitePic\Repository\ImageRepository();
$queue   = new \LitePic\Repository\ImportQueueRepository();

/**
 * Resolve a "current filename" — if the source got converted (jpg → webp)
 * the original file is gone but a sibling webp / avif under the same
 * stem now exists. Walk the variants in preference order (avif > webp >
 * original) and return the one that's actually on disk.
 */
$resolveCurrent = static function (string $id) use ($pathSvc): array {
    $stem = pathinfo($id, PATHINFO_FILENAME);
    $dir  = trim((string)dirname($id), './');
    $candidates = [];
    foreach (['avif', 'webp'] as $ext) {
        $candidates[] = ($dir !== '' ? $dir . '/' : '') . $stem . '.' . $ext;
    }
    $candidates[] = $id;
    foreach ($candidates as $cand) {
        $path = $pathSvc::resolveFilePath($cand);
        if (is_file($path)) return [$cand, $path];
    }
    return [$id, $pathSvc::resolveFilePath($id)];
};

$items = [];
foreach ($ids as $id) {
    $id = $pathSvc::normalizeIdentifier($id);
    if ($id === '') continue;

    [$current, $currentPath] = $resolveCurrent($id);
    $exists = is_file($currentPath);
    $row = $repo->find($current);
    $queueRow = $queue->findByFilename($id);

    $thumbPath = $urlSvc::thumbnailPath($current);
    $thumbReady = is_file($thumbPath);

    $items[] = [
        'filename'         => $id,
        'exists'           => $exists,
        'queue_state'      => $queueRow['status'] ?? 'done',
        'queue_attempts'   => $queueRow['attempts'] ?? 0,
        'queue_last_error' => $queueRow['last_error'] ?? null,
        'current_filename' => $current,
        'current_url'      => $exists ? $urlSvc::forIdentifier($current) : '',
        'thumb_ready'      => $thumbReady,
        'thumb_url'        => $thumbReady ? $urlSvc::thumbnailUrl($current) : '',
        'has_webp'         => (bool)($row['has_webp'] ?? str_ends_with($current, '.webp')),
        'has_avif'         => (bool)($row['has_avif'] ?? str_ends_with($current, '.avif')),
        'has_thumbnail'    => $thumbReady,
    ];
}

\LitePic\Core\Response::success(['items' => $items]);

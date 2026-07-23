<?php
declare(strict_types=1);

/**
 * POST /api/v1/queue/drain
 *
 * Manually trigger ImageProcessor::drain() for one batch. Used by the
 * settings → System tab "立即处理队列" button when the user wants to
 * push pending items through without waiting for the next upload /
 * cron tick.
 *
 * Bounded by the same time / count limits as the in-request drain
 * (default 20 tasks / 25 s) so the HTTP request can't hang forever
 * on a backed-up queue.
 *
 * Auth: admin only — this can move significant CPU work, no point
 * exposing to upload-token holders.
 *
 * Optional ?max_items=N&max_seconds=S query overrides (admin-only,
 * upper-bound clamped server-side).
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$maxItems   = max(1, min(100, (int)($_GET['max_items']   ?? $_POST['max_items']   ?? 20)));
$maxSeconds = max(1, min(60,  (int)($_GET['max_seconds'] ?? $_POST['max_seconds'] ?? 25)));

$queue = new \LitePic\Repository\ImportQueueRepository();
$pendingBefore = $queue->pendingCount();

$result = (new \LitePic\Service\Image\ImageProcessor())->drain($maxItems, $maxSeconds);

$pendingAfter = $queue->pendingCount();

// Stash a "last drain" summary so the System tab can show it
try {
    (new \LitePic\Repository\SettingsRepository())->setJson('worker_last_run', [
        'finished_at' => time(),
        'processed'   => (int)$result['processed'],
        'failed'      => (int)$result['failed'],
        'skipped'     => (int)$result['skipped'],
        'elapsed_ms'  => (int)$result['elapsed_ms'],
        'source'      => 'manual',
    ]);
} catch (\Throwable $_) {}

\LitePic\Core\Response::success([
    'pending_before' => $pendingBefore,
    'pending_after'  => $pendingAfter,
    'drain'          => $result,
]);

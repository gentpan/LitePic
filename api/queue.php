<?php
declare(strict_types=1);

/**
 * Queue management API — admin-only.
 *
 * Routes (all under the /api/v1/queue/* prefix, dispatched by api/v1.php):
 *
 *   GET  /api/v1/queue/failed
 *     List up to 50 failed tasks (attempts > 0). For the system tab UI.
 *
 *   POST /api/v1/queue/retry?id=N
 *     Reset one task's attempts to 0 + clear last_error so the next
 *     drain picks it up. Pass `?id=` query / form param.
 *
 *   POST /api/v1/queue/retry-all
 *     Same but for all failed tasks at once.
 *
 *   POST /api/v1/queue/discard?id=N
 *     Hard-drop one task (give up on it).
 *
 *   POST /api/v1/queue/discard-all-failed
 *     Hard-drop all failed tasks.
 *
 *   POST /api/v1/queue/reprocess?filename=2026/05/abc.jpg
 *     Push an existing image back through the processing pipeline at
 *     priority=10. Used by the gallery "重新处理" button.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');

require __DIR__ . '/../bootstrap.php';

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$repo = new \LitePic\Repository\ImportQueueRepository();
$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($action) {
    case 'failed':
        if ($method !== 'GET') \LitePic\Core\Response::error('仅支持 GET', 405);
        $_settings_repo = new \LitePic\Repository\SettingsRepository();
        $_last_run = $_settings_repo->getJson('worker_last_run', null);
        \LitePic\Core\Response::success([
            'pending'   => $repo->pendingCount(),
            'failed'    => $repo->failedCount(),
            'items'     => $repo->failedItems(50),
            'last_run'  => is_array($_last_run) ? $_last_run : null,
        ]);
        break;

    case 'retry': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) \LitePic\Core\Response::error('缺少 id 参数', 400);
        $ok = $repo->retryItem($id);
        \LitePic\Core\Response::success(['retried' => $ok ? 1 : 0]);
        break;
    }

    case 'retry-all':
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $n = $repo->retryAllFailed();
        \LitePic\Core\Response::success(['retried' => $n]);
        break;

    case 'discard': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) \LitePic\Core\Response::error('缺少 id 参数', 400);
        $ok = $repo->discardItem($id);
        \LitePic\Core\Response::success(['discarded' => $ok ? 1 : 0]);
        break;
    }

    case 'discard-all-failed':
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $n = $repo->discardAllFailed();
        \LitePic\Core\Response::success(['discarded' => $n]);
        break;

    case 'reprocess': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $filename = trim((string)($_GET['filename'] ?? $_POST['filename'] ?? ''));
        if ($filename === '') \LitePic\Core\Response::error('缺少 filename 参数', 400);

        // 校验文件存在 + 路径合法
        $normalized = \LitePic\Service\Image\PathService::normalizeIdentifier($filename);
        if ($normalized === '' || !is_file(\LitePic\Service\Image\PathService::resolveFilePath($normalized))) {
            \LitePic\Core\Response::error('找不到该图片', 404);
        }

        $ok = $repo->reprocess($normalized);
        \LitePic\Core\Response::success([
            'reprocessed'      => $ok,
            'filename'         => $normalized,
            'priority'         => 10,
            'queue_pending'    => $repo->pendingCount(),
        ]);
        break;
    }

    default:
        \LitePic\Core\Response::error('未知的队列操作', 400);
}

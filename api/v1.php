<?php
declare(strict_types=1);

/**
 * Versioned API dispatcher.
 *
 * Public routes:
 * - POST /api/v1                Upload images
 * - POST /api/v1/duplicate-check Check content hashes before upload
 * - GET  /api/v1/list           List images
 * - GET  /api/v1/export         Export image list
 * - POST /api/v1/action         Admin image operations
 * - GET  /api/v1/image-status   Poll async-processing state for given ids
 * - GET  /api/v1/system/status  Runtime server metrics
 * - POST /api/v1/queue/drain               Manually trigger the worker
 * - GET  /api/v1/queue/failed              List failed tasks
 * - POST /api/v1/queue/retry?id=N          Retry one failed task
 * - POST /api/v1/queue/retry-all           Retry all failed tasks
 * - POST /api/v1/queue/discard?id=N        Drop one task
 * - POST /api/v1/queue/discard-all-failed  Drop all failed tasks
 * - POST /api/v1/queue/reprocess?filename= Re-queue an image at priority=10
 *
 * - GET  /api/v1/backup/list                List local backups + schedule
 * - POST /api/v1/backup/create              Run a backup now
 * - POST /api/v1/backup/delete?file=        Delete one local backup
 * - POST /api/v1/backup/restore?file=       Restore live DB from a backup
 * - POST /api/v1/backup/config              Update schedule (json body)
 * - GET  /api/v1/update/check               Check latest GitHub Release
 * - POST /api/v1/update/install             Download and install latest Release ZIP
 */

define('LITEPIC_API_V1_DISPATCH', true);

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH);
$path = is_string($uriPath) && $uriPath !== '' ? rtrim($uriPath, '/') : '/api/v1';
if ($path === '') {
    $path = '/api/v1';
}

$route = substr($path, strlen('/api/v1'));
$route = $route === false || $route === '' ? '/' : '/' . trim($route, '/');

switch ($route) {
    case '/':
        require __DIR__ . '/upload.php';
        break;

    case '/list':
        require __DIR__ . '/list.php';
        break;

    case '/export':
        require __DIR__ . '/export.php';
        break;

    case '/action':
        require dirname(__DIR__) . '/action.php';
        break;

    case '/image-status':
        require __DIR__ . '/image-status.php';
        break;

    case '/duplicate-check':
        require __DIR__ . '/duplicate-check.php';
        break;

    case '/system/status':
        require __DIR__ . '/system_status.php';
        break;

    case '/uptime':
        require __DIR__ . '/uptime.php';
        break;

    case '/queue/drain':
        require __DIR__ . '/queue-drain.php';
        break;

    // Queue management (admin) — list failed / retry / discard / reprocess.
    // Sub-action chosen via ?action=failed|retry|retry-all|discard|...
    case '/queue/failed':              $_GET['action'] = 'failed';              require __DIR__ . '/queue.php'; break;
    case '/queue/retry':               $_GET['action'] = 'retry';               require __DIR__ . '/queue.php'; break;
    case '/queue/retry-all':           $_GET['action'] = 'retry-all';           require __DIR__ . '/queue.php'; break;
    case '/queue/discard':             $_GET['action'] = 'discard';             require __DIR__ . '/queue.php'; break;
    case '/queue/discard-all-failed':  $_GET['action'] = 'discard-all-failed';  require __DIR__ . '/queue.php'; break;
    case '/queue/reprocess':           $_GET['action'] = 'reprocess';           require __DIR__ . '/queue.php'; break;

    // Database backup management (admin)
    case '/backup/list':    $_GET['action'] = 'list';    require __DIR__ . '/backup.php'; break;
    case '/backup/create':  $_GET['action'] = 'create';  require __DIR__ . '/backup.php'; break;
    case '/backup/delete':  $_GET['action'] = 'delete';  require __DIR__ . '/backup.php'; break;
    case '/backup/restore': $_GET['action'] = 'restore'; require __DIR__ . '/backup.php'; break;
    case '/backup/config':  $_GET['action'] = 'config';  require __DIR__ . '/backup.php'; break;

    // Application updater (admin) — WordPress-style ZIP replacement, user data protected.
    case '/update/check':   $_GET['action'] = 'check';   require __DIR__ . '/update.php'; break;
    case '/update/install': $_GET['action'] = 'install'; require __DIR__ . '/update.php'; break;

    // Residual data cleanup (admin) — conservative, opt-in by category.
    case '/cleanup/scan':   $_GET['action'] = 'scan';    require __DIR__ . '/cleanup.php'; break;
    case '/cleanup/run':    $_GET['action'] = 'run';     require __DIR__ . '/cleanup.php'; break;

    // Albums (admin) — slug-parameterised so we can't use case-strings.
    // Falls through to the regex matcher below.
    default:
        if (preg_match('#^/albums(?:/([a-z0-9][a-z0-9-]{0,49}))?(?:/(images))?$#', $route, $m)) {
            require __DIR__ . '/albums.php';
            break;
        }
        // Telegram webhook — `/telegram/webhook/<32-hex-secret>`. Secret
        // value is the URL-path-segment auth (paired with X-Telegram-Bot-
        // Api-Secret-Token header check inside the handler). 16+ hex chars
        // is the documented Telegram minimum; we allow up to 64.
        if (preg_match('#^/telegram/webhook/([a-f0-9]{16,64})$#', $route, $m)) {
            $_GET['secret'] = $m[1];
            require __DIR__ . '/telegram.php';
            break;
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'API route not found',
        ], JSON_UNESCAPED_UNICODE);
        break;
}

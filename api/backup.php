<?php
declare(strict_types=1);

/**
 * Database backup management API — admin-only.
 *
 * Routes (under /api/v1/backup/* via api/v1.php):
 *   POST /api/v1/backup/create
 *     Force-run a backup now. Returns the new backup metadata.
 *
 *   GET  /api/v1/backup/list
 *     List local backup files + the schedule config (enabled, interval,
 *     keep_count, sync_to_remote, last_run_at).
 *
 *   POST /api/v1/backup/delete?file=litepic-20260501-090000.sqlite
 *     Delete one local backup file.
 *
 *   POST /api/v1/backup/restore?file=litepic-20260501-090000.sqlite
 *     Restore the live DB from a local backup. DESTRUCTIVE — overwrites
 *     the current data/litepic.sqlite. UI must double-confirm.
 *
 *   POST /api/v1/backup/config
 *     Update the schedule (enabled/interval_hours/keep_count/to_remote).
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

$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$service = new \LitePic\Service\Backup\DatabaseBackup();
$settings = new \LitePic\Repository\SettingsRepository();

switch ($action) {
    case 'list':
        if ($method !== 'GET') \LitePic\Core\Response::error('仅支持 GET', 405);
        \LitePic\Core\Response::success([
            'config' => [
                'enabled'         => $service->isScheduleEnabled(),
                'interval_hours'  => $settings->getInt(\LitePic\Service\Backup\DatabaseBackup::SETTING_INTERVAL_HOURS,
                                                      \LitePic\Service\Backup\DatabaseBackup::DEFAULT_INTERVAL_HOURS),
                'keep_count'      => $service->keepCount(),
                'to_remote'       => $service->syncToRemote(),
                'last_run_at'     => $service->lastRunAt(),
                'remote_enabled'  => (new \LitePic\Service\Storage\RemoteStorage())->isEnabled(),
            ],
            'backups' => $service->listLocalBackups(),
        ]);
        break;

    case 'create':
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $result = $service->runOnce(false);
        if (!$result['ran']) {
            \LitePic\Core\Response::error('备份失败：' . ($result['error'] ?? $result['reason']), 500);
        }
        \LitePic\Core\Response::success([
            'path'        => $result['path'] ?? null,
            'name'        => isset($result['path']) ? basename((string)$result['path']) : null,
            'remote_key'  => $result['remote_key'] ?? null,
            'pruned'      => (int)($result['pruned'] ?? 0),
        ]);
        break;

    case 'delete': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $name = trim((string)($_GET['file'] ?? $_POST['file'] ?? ''));
        if ($name === '') \LitePic\Core\Response::error('缺少 file 参数', 400);
        $ok = $service->deleteBackup($name);
        if (!$ok) \LitePic\Core\Response::error('删除失败（文件不存在或文件名非法）', 400);
        \LitePic\Core\Response::success(['deleted' => $name]);
        break;
    }

    case 'restore': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $name = trim((string)($_GET['file'] ?? $_POST['file'] ?? ''));
        if ($name === '') \LitePic\Core\Response::error('缺少 file 参数', 400);
        try {
            $service->restoreFromBackup($name);
        } catch (\Throwable $e) {
            \LitePic\Core\Response::error('恢复失败：' . $e->getMessage(), 500);
        }
        \LitePic\Core\Response::success([
            'restored' => $name,
            'note'     => '数据库已恢复 — 下次请求会自动用新数据',
        ]);
        break;
    }

    case 'config': {
        if ($method !== 'POST') \LitePic\Core\Response::error('仅支持 POST', 405);
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        // Whitelist + sanitise
        $updates = [];
        if (isset($payload['enabled'])) {
            $updates[\LitePic\Service\Backup\DatabaseBackup::SETTING_ENABLED] =
                !empty($payload['enabled']) ? 'true' : 'false';
        }
        if (isset($payload['interval_hours'])) {
            $h = max(0, min(720, (int)$payload['interval_hours']));   // up to 30 days
            $updates[\LitePic\Service\Backup\DatabaseBackup::SETTING_INTERVAL_HOURS] = (string)$h;
        }
        if (isset($payload['keep_count'])) {
            $k = max(1, min(100, (int)$payload['keep_count']));
            $updates[\LitePic\Service\Backup\DatabaseBackup::SETTING_KEEP_COUNT] = (string)$k;
        }
        if (isset($payload['to_remote'])) {
            $updates[\LitePic\Service\Backup\DatabaseBackup::SETTING_TO_REMOTE] =
                !empty($payload['to_remote']) ? 'true' : 'false';
        }
        \LitePic\Core\Config::write($updates);
        \LitePic\Core\Response::success(['updated' => array_keys($updates)]);
        break;
    }

    default:
        \LitePic\Core\Response::error('未知的备份操作', 400);
}

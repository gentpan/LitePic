<?php
declare(strict_types=1);

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
// 更新检查结果必须实时 —— 禁止浏览器/CDN 缓存,否则会一直返回旧的「最新版本」
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/../bootstrap.php';

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$service = new \LitePic\Service\System\UpdateService();

try {
    switch ($action) {
        case 'check':
            if ($method !== 'GET') {
                \LitePic\Core\Response::error('仅支持 GET', 405);
            }
            \LitePic\Core\Response::success($service->check());
            break;

        case 'install':
            if ($method !== 'POST') {
                \LitePic\Core\Response::error('仅支持 POST', 405);
            }
            $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
            if (!\LitePic\Core\Csrf::verify($csrf)) {
                \LitePic\Core\Response::error('安全令牌无效或已过期，请刷新页面后重试', 403);
            }
            \LitePic\Core\Response::success($service->installLatest());
            break;

        default:
            \LitePic\Core\Response::error('未知的更新操作', 400);
    }
} catch (\Throwable $e) {
    \LitePic\Core\Logger::error('Update API failed: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
    \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
}

<?php
declare(strict_types=1);

/**
 * POST /api/v1/system/enable-extensions
 *
 * Admin + CSRF only. Runs bin/enable-image-ext.sh via passwordless sudo
 * (must have been installed with --install-sudoers once).
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST', 405);
}

// Session-bound admin only — not bare X-API-Key (this escalates to root via sudo).
if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    \LitePic\Core\Response::error('权限不足，请先登录管理后台', 403);
}

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
if (!\LitePic\Core\Csrf::verify($csrf)) {
    \LitePic\Core\Response::error('安全令牌无效或已过期，请刷新页面后重试', 403);
}

$uploadMb = null;
$rawBody = (string)file_get_contents('php://input');
if ($rawBody !== '') {
    $json = json_decode($rawBody, true);
    if (is_array($json) && isset($json['upload_mb'])) {
        $uploadMb = max(1, min(2048, (int)$json['upload_mb']));
    }
}
if ($uploadMb === null && isset($_POST['upload_mb'])) {
    $uploadMb = max(1, min(2048, (int)$_POST['upload_mb']));
}
if ($uploadMb === null && defined('MAX_FILE_SIZE')) {
    $uploadMb = max(1, (int)ceil(MAX_FILE_SIZE / 1048576));
}

@set_time_limit(600);
@ini_set('max_execution_time', '600');

$service = new \LitePic\Service\System\EnableExtensionsService();
$result = $service->run($uploadMb);

if (!$result['ok']) {
    $status = match (true) {
        $result['exit_code'] === 503 => 503,
        $result['exit_code'] === 404 => 404,
        default => 500,
    };
    http_response_code($status);
    echo json_encode([
        'status' => 'error',
        'message' => $result['message'],
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

\LitePic\Core\Response::success([
    'message' => $result['message'],
    'data' => $result,
]);

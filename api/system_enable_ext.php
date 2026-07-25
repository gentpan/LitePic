<?php
declare(strict_types=1);

/**
 * /api/v1/system/enable-extensions
 *
 * POST  — start background enable (admin + CSRF)
 * GET   — poll status / log (admin)
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

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$auth = new \LitePic\Service\Auth\AuthService();

if (!$auth->isAdmin()) {
    \LitePic\Core\Response::error('权限不足，请先登录管理后台', 403);
}

$service = new \LitePic\Service\System\EnableExtensionsService();

if ($method === 'GET') {
    $result = $service->status();
    \LitePic\Core\Response::success([
        'message' => $result['message'],
        'data' => $result,
    ]);
}

if ($method !== 'POST') {
    \LitePic\Core\Response::error('仅支持 GET / POST', 405);
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

$result = $service->start($uploadMb);
if (!$result['ok']) {
    $status = match ($result['exit_code'] ?? 0) {
        503 => 503,
        404 => 404,
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

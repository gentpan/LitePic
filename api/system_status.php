<?php
declare(strict_types=1);

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'API route not found',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');
$_origin = cors_origin();
if ($_origin !== '') {
    header('Access-Control-Allow-Origin: ' . $_origin);
}
unset($_origin);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    \LitePic\Core\Response::error('仅支持 GET 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$metrics = (new \LitePic\Service\Stats\ServerInfo())->runtimeMetrics();
\LitePic\Core\Response::success(['data' => $metrics]);

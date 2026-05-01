<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    \LitePic\Core\Response::error('仅支持 GET 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$metrics = (new \LitePic\Service\Stats\ServerInfo())->runtimeMetrics();
\LitePic\Core\Response::success(['data' => $metrics]);


<?php
declare(strict_types=1);

/**
 * 图片导出 API
 * GET /api/v1/export?page=1&per_page=100&q=keyword&sort=date-desc
 * GET /api/v1/export?all=1
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'API route not found',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../bootstrap.php';

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

if (!(new \LitePic\Service\Auth\AuthService())->hasUploadApiAccess()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = max(1, min(500, (int)($_GET['per_page'] ?? 100)));
$query = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'date-desc');
$all = isset($_GET['all']) && in_array(strtolower((string)$_GET['all']), ['1', 'true', 'yes'], true);

$data = (new \LitePic\Repository\ImageRepository())->queryForApi($page, $per_page, $query, $sort, $all);

\LitePic\Core\Response::success([
    'data' => $data,
]);

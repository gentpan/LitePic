<?php
declare(strict_types=1);

/**
 * 图库列表 API
 * GET /api/v1/list?page=1&per_page=20&q=keyword&sort=date-desc
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
    error_response('仅支持 GET 请求', 405);
}

if (!is_api_request_authorized()) {
    error_response('权限不足', 403);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
$query = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'date-desc');

$data = query_uploaded_images_for_api($page, $per_page, $query, $sort, false);

success_response([
    'data' => $data,
]);

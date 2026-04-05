<?php
declare(strict_types=1);

/**
 * 图片导出 API
 * GET /api/export.php?page=1&per_page=100&q=keyword&sort=date-desc
 * GET /api/export.php?all=1
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../config.php';
require_once '../functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    error_response('仅支持 GET 请求', 405);
}

if (!has_upload_api_access()) {
    error_response('权限不足', 403);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = max(1, min(500, (int)($_GET['per_page'] ?? 100)));
$query = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'date-desc');
$all = isset($_GET['all']) && in_array(strtolower((string)$_GET['all']), ['1', 'true', 'yes'], true);

$data = query_uploaded_images_for_api($page, $per_page, $query, $sort, $all);

success_response([
    'data' => $data,
]);

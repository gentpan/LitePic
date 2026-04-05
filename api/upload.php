<?php
declare(strict_types=1);

/**
 * 第三方上传 API
 * 支持字段: image、image[]、file、files[]
 * 鉴权: X-API-Key 或 Authorization: Bearer <key>
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');

// API 响应必须保持 JSON，避免 warning/notices 混入响应体
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../config.php';
require_once '../functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    error_response('仅支持 POST 请求', 405);
}

if (!has_upload_api_access()) {
    error_response('权限不足', 403);
}

$raw_files = null;
if (isset($_FILES['image'])) {
    $raw_files = $_FILES['image'];
} elseif (isset($_FILES['file'])) {
    $raw_files = $_FILES['file'];
} elseif (isset($_FILES['files'])) {
    $raw_files = $_FILES['files'];
}

if ($raw_files === null) {
    error_response('未上传任何文件', 400);
}

$files = normalize_uploaded_files($raw_files);
$results = handle_uploaded_files($files);

success_response(['results' => $results]);

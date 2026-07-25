<?php
declare(strict_types=1);

/**
 * POST /api/v1/view — public image view counter for lightbox / explicit views.
 *
 * Body (JSON or form):
 *   filename  required  storage identifier, e.g. "2026/07/abc.webp"
 *
 * Counts once per call. Album lightbox uses this instead of relying on `/i/`
 * streaming (thumbnails never hit `/i/`, and album Referers skip `/i/` counts
 * to avoid double-counting).
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
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
$_origin = cors_origin();
if ($_origin !== '') {
    header('Access-Control-Allow-Origin: ' . $_origin);
}
unset($_origin);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => '仅支持 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filename = (string)($_POST['filename'] ?? '');
if ($filename === '') {
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $filename = (string)($body['filename'] ?? '');
        }
    }
}

$filename = \LitePic\Service\Image\PathService::normalizeIdentifier($filename);
if ($filename === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少 filename'], JSON_UNESCAPED_UNICODE);
    exit;
}

$repo = new \LitePic\Repository\ImageRepository();
$row = $repo->find($filename);
if ($row === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => '图片不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $repo->recordViewRequest($filename, (string)($_SERVER['HTTP_REFERER'] ?? ''));
} catch (\Throwable $e) {
    error_log('api/view: increment failed for ' . $filename . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '计数失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fresh = $repo->find($filename);
$views = (int)($fresh['view_count'] ?? (($row['view_count'] ?? 0) + 1));

echo json_encode([
    'status' => 'ok',
    'filename' => $filename,
    'views' => $views,
], JSON_UNESCAPED_UNICODE);

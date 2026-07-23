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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->hasUploadApiAccess()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    \LitePic\Core\Response::error('请求参数无效', 400);
}

$items = $payload['hashes'] ?? [];
if (!is_array($items)) {
    \LitePic\Core\Response::error('hashes 参数无效', 400);
}

$repo = new \LitePic\Repository\ImageRepository();
$duplicates = [];

foreach ($items as $item) {
    if (!is_array($item)) continue;
    $hash = strtolower(trim((string)($item['hash'] ?? '')));
    if ($hash === '' || !preg_match('/^[a-f0-9]{40}$/', $hash)) continue;
    $row = $repo->findByHashWithBackfill($hash);
    if ($row === null) continue;
    $filename = (string)($row['filename'] ?? '');
    $duplicates[$hash] = [
        'filename' => $filename,
        'original_name' => (string)($row['original_name'] ?? $filename),
        'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($filename),
    ];
}

\LitePic\Core\Response::success([
    'duplicates' => $duplicates,
]);

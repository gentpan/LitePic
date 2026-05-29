<?php
declare(strict_types=1);

/**
 * 第三方上传 API
 * 支持字段: image、image[]、file、files[]
 * 鉴权: X-API-Key 或 Authorization: Bearer <key>
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

// API 响应必须保持 JSON，避免 warning/notices 混入响应体
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->hasUploadApiAccess()) {
    \LitePic\Core\Response::error('权限不足', 403);
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
    \LitePic\Core\Response::error('未上传任何文件', 400);
}

$files = \LitePic\Service\Upload\UploadService::normaliseFilesArray($raw_files);
$maxFiles = defined('UPLOAD_MAX_FILES') ? (int)UPLOAD_MAX_FILES : 100;
if (count($files) > $maxFiles) {
    \LitePic\Core\Response::error('单次上传文件数量超过后台上限（当前 ' . $maxFiles . ' 个）', 413);
}
$results = (new \LitePic\Service\Upload\UploadService())->handle($files);

/*
 * Optional `album` field — when present, adds every successfully-uploaded
 * file to that album in the same request. The album must exist; an
 * unknown slug is ignored silently (we don't want to fail the upload
 * over a typo in the album field — uploads are always more important).
 *
 * Auth note: api/upload.php already validated that the request is at
 * least an upload-API caller (admin or third-party key). For album
 * membership we additionally require admin — third-party API keys are
 * not allowed to create album memberships, only push raw uploads.
 */
$albumSlug = trim((string)($_POST['album'] ?? $_GET['album'] ?? ''));
if ($albumSlug !== '' && (new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    $albumRepo = new \LitePic\Repository\AlbumRepository();
    $album = $albumRepo->findBySlug($albumSlug);
    if ($album !== null) {
        $filenames = [];
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'success' && ($r['filename'] ?? '') !== '') {
                $filenames[] = (string)$r['filename'];
            }
        }
        if ($filenames !== []) {
            (new \LitePic\Service\Album\AlbumService())->addImages((int)$album['id'], $filenames);
        }
    }
}

// 异步流水线：响应送达后继续在同一 PHP 进程跑 ImageProcessor::drain()
// 把队列里的缩略图 / 压缩 / WebP / AVIF / 水印 / 远程同步任务做完。
// register_shutdown_function 保证 Response::success() 里的 exit 之后还能跑。
// drain() 自带 25 秒 wall-time 上限和 20 个任务上限，不会卡住请求生命周期。
register_shutdown_function(static function () {
    \LitePic\Core\ResponseDetacher::runAfterResponse(static function () {
        (new \LitePic\Service\Image\ImageProcessor())->drain(20, 25);
    });
});

\LitePic\Core\Response::success(['results' => $results]);

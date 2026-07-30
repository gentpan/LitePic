<?php
declare(strict_types=1);

/**
 * 第三方上传 API
 * 支持字段: image、image[]、file、files[]
 * 鉴权: X-API-Key 或 Authorization: Bearer <key>；多用户模式下也可会话登录
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

try {
    require __DIR__ . '/../bootstrap.php';

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

    // 多用户模式：按用户配额拦截（quota_bytes = 0 表示不限，管理员默认不限）。
    if (\LitePic\Service\Auth\UserContext::enabled()) {
        $ownerId = \LitePic\Service\Auth\UserContext::contentOwnerId();
        $incomingBytes = 0;
        foreach ($files as $f) {
            $incomingBytes += max(0, (int)($f['size'] ?? 0));
        }
        if (!(new \LitePic\Repository\UserRepository())->hasQuotaFor($ownerId, $incomingBytes)) {
            $userRepo = new \LitePic\Repository\UserRepository();
            $owner = $userRepo->find($ownerId);
            $quotaMb = $owner !== null ? round(((int)$owner['quota_bytes']) / 1048576) : 0;
            \LitePic\Core\Response::error(
                '存储空间不足：配额 ' . $quotaMb . ' MB 已用完，请联系管理员提升配额',
                413
            );
        }
    }

    $results = (new \LitePic\Service\Upload\UploadService())->handle($files);

    /*
     * Optional `album` field — attach successful uploads to an album.
     * Admin may attach to any album; regular users / their tokens may only
     * attach to albums they own. Unknown slug is ignored (upload wins).
     */
    $albumSlug = trim((string)($_POST['album'] ?? $_GET['album'] ?? ''));
    if ($albumSlug !== '') {
        $isAdmin = \LitePic\Service\Auth\UserContext::isAdmin();
        $canAttach = $isAdmin
            || (\LitePic\Service\Auth\UserContext::enabled()
                && \LitePic\Service\Auth\UserContext::isLoggedIn());
        if ($canAttach) {
            $albumRepo = new \LitePic\Repository\AlbumRepository();
            $album = $albumRepo->findByKey($albumSlug);
            if ($album === null) {
                $album = $albumRepo->findBySlug($albumSlug);
            }
            if ($album !== null) {
                $ownerOk = $isAdmin
                    || (int)($album['user_id'] ?? 0) === \LitePic\Service\Auth\UserContext::contentOwnerId();
                if ($ownerOk) {
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
        }
    }

    // 异步流水线：响应送达后继续在同一 PHP 进程跑一小段 ImageProcessor::drain()。
    register_shutdown_function(static function () {
        \LitePic\Core\ResponseDetacher::runAfterResponse(static function () {
            (new \LitePic\Service\Image\ImageProcessor())->drain(3, 8);
        });
    });

    \LitePic\Core\Response::success(['results' => $results]);
} catch (\Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    if (class_exists(\LitePic\Core\Logger::class, false) || class_exists(\LitePic\Core\Logger::class)) {
        try {
            \LitePic\Core\Logger::error('Upload API failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable $_) {
            // ignore logger failure
        }
    }
    $message = '上传失败';
    if (class_exists(\LitePic\Core\Response::class, false) || class_exists(\LitePic\Core\Response::class)) {
        try {
            $message = \LitePic\Core\Response::safeMessage($e);
        } catch (\Throwable $_) {
            $message = '上传失败：' . $e->getMessage();
        }
        \LitePic\Core\Response::error($message, 500);
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $message !== '' ? $message : '上传失败',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

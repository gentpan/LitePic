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

// API 响应必须保持 JSON，避免 warning/notices 混入响应体
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';

// 在任何通用参数校验之前，先处理特殊动作
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === 'render_card') {
    header('Content-Type: text/html; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo '<b>Error</b> Method Not Allowed';
        exit;
    }

    if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
        http_response_code(403);
        echo '<b>Error</b> 权限不足';
        exit;
    }

    $img = (string)($_POST['img'] ?? '');
    $infoRaw = (string)($_POST['info'] ?? '');
    $info = json_decode($infoRaw, true);

    if ($img === '' || !is_array($info)) {
        http_response_code(400);
        echo '<b>Error</b> Invalid parameters';
        exit;
    }

    $stored_info = (new \LitePic\Service\Image\ImageInfo())->getSafe($img);
    if (is_array($stored_info)) {
        $info = array_merge($info, $stored_info);
    }

    $info = array_merge([
        'filename' => $img,
        'size' => 0,
        'time' => time(),
        'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($img),
        'thumb_url' => \LitePic\Service\Image\ImageUrl::forIdentifier($img),
        'dimensions' => '',
    ], $info);

    if (empty($info['thumb_url'])) {
        $info['thumb_url'] = $info['url'];
    }

    $type = (string)($_POST['type'] ?? 'gallery');
    $isGallery = $type === 'gallery';

    try {
        $card = new \LitePic\View\ImageCard($info, $isGallery, $isGallery, $isGallery);
        echo $card->render();
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<b>Error</b> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit;
}

header('Content-Type: application/json');
$_cors = cors_origin();
if ($_cors !== '') header('Access-Control-Allow-Origin: ' . $_cors);
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');
unset($_cors);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($action === 'get_next_image') {
    if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
        \LitePic\Core\Response::error('权限不足', 403);
    }

    $current_count = (int)($_GET['current_count'] ?? $_POST['current_count'] ?? 0);
    $count = (int)($_GET['count'] ?? $_POST['count'] ?? 1);
    if ($current_count < 0) $current_count = 0;
    if ($count <= 0) $count = 1;

    // 获取可渲染图片列表。数据库可能存在文件已丢失的孤儿记录，
    // 这里必须先用 ImageInfo 过滤，否则最近上传会显示图库已经跳过的坏记录。
    $repo = new \LitePic\Repository\ImageRepository();
    $infoService = new \LitePic\Service\Image\ImageInfo($repo);
    $all_images = [];
    foreach ($repo->listIdentifiersSafe() as $identifier) {
        if ($infoService->getSafe((string)$identifier)) {
            $all_images[] = (string)$identifier;
        }
    }
    $total = count($all_images);

    $images = [];
    if ($current_count < $total) {
        $slice = array_slice($all_images, $current_count, $count);
        foreach ($slice as $img) {
            $info = $infoService->getSafe($img);
            if ($info) {
                $images[] = [
                    'filename'   => $img,
                    'url'        => \LitePic\Service\Image\ImageUrl::forIdentifier($img),
                    'thumb_url'  => (string)($info['thumb_url'] ?? \LitePic\Service\Image\ImageUrl::forIdentifier($img)),
                    'size'       => $info['size'] ?? 0,
                    'dimensions' => $info['dimensions'] ?? '',
                    'time'       => $info['time'] ?? 0,
                    'original_name' => $info['original_name'] ?? $img,
                    'format' => $info['format'] ?? '',
                ];
            }
        }
    }

    echo json_encode(['status' => 'success', 'total' => $total, 'images' => $images], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证 API 密钥（支持后台登录或第三方 API Key）
if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    error_log("Unauthorized action attempt from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    \LitePic\Core\Response::error('权限不足', 403);
}

// 状态变更操作仅允许 POST，并校验 CSRF Token
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST 请求', 405);
}

// 从 POST / GET 中读取参数（兼容前端混合传参）
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$file = (string)($_POST['file'] ?? $_GET['file'] ?? '');

if ($action === '') {
    \LitePic\Core\Response::error('未指定操作');
}

// 管理员操作需要 CSRF 校验（第三方 API Key 仅允许上传/读取，不允许删除/压缩等管理操作）
if ((new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    $csrf = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if (!\LitePic\Core\Csrf::verify($csrf)) {
        \LitePic\Core\Response::error('CSRF Token 无效或已过期', 403);
    }
}

if ($action === 'queue_avif') {
    if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
        \LitePic\Core\Response::error('权限不足', 403);
    }

    $raw_files = $_POST['files'] ?? '';
    $files = [];
    if (is_array($raw_files)) {
        $files = $raw_files;
    } elseif (is_string($raw_files) && trim($raw_files) !== '') {
        $decoded = json_decode($raw_files, true);
        if (is_array($decoded)) {
            $files = $decoded;
        }
    }

    if (empty($files)) {
        \LitePic\Core\Response::error('未指定要加入任务队列的图片', 400);
    }

    $result = [
        'queued' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ];
    $seen = [];

    foreach ($files as $raw_file) {
        $filename = \LitePic\Service\Image\PathService::normalizeIdentifier((string)$raw_file);
        if ($filename === '' || isset($seen[$filename])) {
            $result['skipped']++;
            continue;
        }
        $seen[$filename] = true;

        $source_path = \LitePic\Service\Image\PathService::resolveFilePath($filename);
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!is_file($source_path)) {
            $result['failed']++;
            $result['errors'][] = '图片不存在: ' . $filename;
            continue;
        }
        if (!\LitePic\Service\Image\ImageFormat::canConvertAvif($ext)) {
            $result['skipped']++;
            continue;
        }

        $queued = (new \LitePic\Service\Importer\Importer())->enqueue($filename, [
            'create_thumbnail' => true,
            'auto_avif' => true,
            'watermark' => defined('WATERMARK_ENABLED') && WATERMARK_ENABLED,
            'remote_sync' => (new \LitePic\Service\Storage\RemoteStorage())->isEnabled() && (new \LitePic\Service\Storage\RemoteStorage())->isConfigValid(),
        ]);
        if ($queued) {
            $result['queued']++;
        } else {
            $result['failed']++;
            $result['errors'][] = '任务入队失败: ' . $filename;
        }
    }

    if ($result['queued'] === 0 && $result['failed'] > 0) {
        \LitePic\Core\Response::error(implode('；', array_slice($result['errors'], 0, 3)), 500);
    }
    if ($result['queued'] === 0) {
        \LitePic\Core\Response::error('没有可转换为 AVIF 的图片', 400);
    }

    $status = (new \LitePic\Service\Importer\Importer())->queueStatus();
    \LitePic\Core\Response::success([
        'message' => sprintf('已加入 AVIF 异步任务队列：%d 张', $result['queued']),
        'queued' => $result['queued'],
        'skipped' => $result['skipped'],
        'failed' => $result['failed'],
        'errors' => array_slice($result['errors'], 0, 5),
        'task_status' => $status,
    ]);
}

if ($file === '') {
    \LitePic\Core\Response::error('未指定文件');
}

// 获取文件路径
$path = \LitePic\Service\Image\PathService::resolveFilePath($file);

if (!file_exists($path)) {
    // 删除允许清理数据库中的孤儿记录；其他动作仍然需要真实文件。
    if ($action !== 'delete') {
        \LitePic\Core\Response::error('文件不存在', 404);
    }
}

$handle_convert_action = static function (string $targetExt) use ($file, $path): void {
    try {
        $targetExt = \LitePic\Service\Image\ImageFormat::normalizeTarget($targetExt);
        if ($targetExt === '') {
            \LitePic\Core\Response::error('不支持的转换目标格式', 400);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!\LitePic\Service\Image\ImageFormat::canConvertTo($ext, $targetExt)) {
            \LitePic\Core\Response::error('该文件类型不支持转换为 ' . \LitePic\Service\Image\ImageFormat::targetLabel($targetExt), 400);
        }

        $targetPath = preg_replace('/\.(jpg|jpeg|png|gif|webp|avif|heic|heif|bmp|tiff|tif|ico)$/i', '.' . $targetExt, $path);
        if (!is_string($targetPath) || $targetPath === '' || $targetPath === $path) {
            throw new Exception('输出路径无效');
        }
        $beforeSize = filesize($path);
        if ($beforeSize === false || $beforeSize <= 0) {
            throw new Exception('原文件大小不可读');
        }

        if (!(new \LitePic\Service\Image\ConversionService())->toFormat($path, $targetExt)) {
            throw new Exception(\LitePic\Service\Image\ImageFormat::targetLabel($targetExt) . ' 转换失败');
        }
        if (!is_file($targetPath)) {
            throw new Exception(\LitePic\Service\Image\ImageFormat::targetLabel($targetExt) . ' 文件生成失败');
        }

        $targetFilename = \LitePic\Service\Image\PathService::identifierFromPath($targetPath) ?? basename($targetPath);
        $watermark = (new \LitePic\Service\Image\WatermarkService())->apply($targetFilename);
        clearstatcache(true, $targetPath);
        $afterSize = filesize($targetPath);
        if ($afterSize === false || $afterSize <= 0) {
            throw new Exception('转换后文件大小不可读');
        }
        $savedSize = max(0, $beforeSize - $afterSize);
        $savedPercent = $beforeSize > 0 ? round(($savedSize / $beforeSize) * 100, 1) : 0;

        $thumbnailUrl = \LitePic\Service\Image\ImageUrl::forIdentifier($targetFilename);
        if ((new \LitePic\Service\Image\ThumbnailService())->create($targetFilename, true)) {
            $thumbnailUrl = \LitePic\Service\Image\ImageUrl::thumbnailUrl($targetFilename);
        }
        $remoteSync = (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($targetFilename);
        \LitePic\Core\Response::success([
            'message' => \LitePic\Service\Image\ImageFormat::targetLabel($targetExt) . ' 转换成功',
            'filename' => $targetFilename,
            'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($targetFilename),
            'size' => $afterSize,
            'size_text' => \LitePic\Core\Format::filesize($afterSize),
            'before_size' => $beforeSize,
            'after_size' => $afterSize,
            'before_size_text' => \LitePic\Core\Format::filesize($beforeSize),
            'after_size_text' => \LitePic\Core\Format::filesize($afterSize),
            'saved_size' => $savedSize,
            'saved_size_text' => \LitePic\Core\Format::filesize($savedSize),
            'saved_percent' => $savedPercent,
            'thumbnail_url' => $thumbnailUrl,
            'watermark' => $watermark,
            'remote_storage' => $remoteSync,
        ]);
    } catch (Exception $e) {
        error_log(strtoupper($targetExt) . " conversion failed for {$file}: " . $e->getMessage());
        \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
    }
};

switch ($action) {
    case 'compress':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) {
                \LitePic\Core\Response::error('该文件类型不支持压缩（仅支持 JPG/JPEG/PNG）', 400);
            }

            $before_size = filesize($path);
            if ($before_size === false || $before_size <= 0) {
                throw new Exception('无法获取文件大小');
            }

            $compress_result = (new \LitePic\Service\Image\CompressionService())->compress($path, 85);
            $used = $compress_result['method'];

            if ($used === null) {
                // 压缩没成功通常是上游压缩服务(TinyPNG)不可用或超时 —— 大图尤其
                // 容易超时(TinyPNG 对大图常常没有响应)。这不是服务器内部 bug,
                // 所以返回一个明确可读的 502,而不是被 safeMessage 脱敏成通用 500。
                $sizeMb = round($before_size / 1048576, 1);
                $mode = \LitePic\Service\Image\ImageFormat::compressionMode();
                error_log("Compression failed for {$file}: mode={$mode} size={$sizeMb}MB (service unavailable/timeout)");
                \LitePic\Core\Response::error(
                    "压缩未成功:压缩服务（{$mode}）暂时不可用或超时"
                    . ($sizeMb >= 5 ? "，该图约 {$sizeMb} MB 偏大,TinyPNG 处理大图容易超时" : "")
                    . "。请稍后重试。",
                    502
                );
            }

            clearstatcache(true, $path);
            $after_size = filesize($path);
            $saved_size = max(0, $before_size - $after_size);
            $saved_percent = $before_size > 0 ? round(($saved_size / $before_size) * 100, 1) : 0;

            (new \LitePic\Service\Image\ThumbnailService())->create($file, true);
            $remote_sync = (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($file);
            \LitePic\Core\Response::success([
                'message' => '压缩成功',
                'method' => $used,
                'mode' => \LitePic\Service\Image\ImageFormat::compressionMode(),
                'original_size' => \LitePic\Core\Format::filesize($before_size),
                'compressed_size' => \LitePic\Core\Format::filesize($after_size),
                'saved_size' => \LitePic\Core\Format::filesize($saved_size),
                'saved_percent' => $saved_percent,
                'size_text' => \LitePic\Core\Format::filesize($after_size),
                'remote_storage' => $remote_sync,
            ]);
        } catch (Exception $e) {
            error_log("Compression failed for {$file}: " . $e->getMessage());
            \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
        }
        break;

    case 'webp':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!\LitePic\Service\Image\ImageFormat::canConvertTo($ext, 'webp')) {
                \LitePic\Core\Response::error('该文件类型不支持转换 WebP', 400);
            }

            $webp_path = preg_replace('/\.(jpg|jpeg|png|gif|heic|heif)$/i', '.webp', $path);
            if (!is_string($webp_path) || $webp_path === '') {
                throw new Exception('WebP 输出路径无效');
            }
            $before_size = filesize($path);
            if ($before_size === false || $before_size <= 0) {
                throw new Exception('原文件大小不可读');
            }
            
            if ((new \LitePic\Service\Image\ConversionService())->toWebp($path)) {
                if (!file_exists($webp_path)) {
                    throw new Exception('WebP 文件生成失败');
                }

                $webp_filename = \LitePic\Service\Image\PathService::identifierFromPath($webp_path) ?? basename($webp_path);
                $watermark = (new \LitePic\Service\Image\WatermarkService())->apply($webp_filename);
                clearstatcache(true, $webp_path);
                $webp_size = filesize($webp_path);
                if ($webp_size === false || $webp_size <= 0) {
                    throw new Exception('WebP 文件大小不可读');
                }
                $saved_size = max(0, $before_size - $webp_size);
                $saved_percent = $before_size > 0 ? round(($saved_size / $before_size) * 100, 1) : 0;
                
                $thumbnail_url = \LitePic\Service\Image\ImageUrl::forIdentifier($webp_filename);
                if ((new \LitePic\Service\Image\ThumbnailService())->create($webp_filename, true)) {
                    $thumbnail_url = \LitePic\Service\Image\ImageUrl::thumbnailUrl($webp_filename);
                }
                $remote_sync = (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($webp_filename);
                \LitePic\Core\Response::success([
                    'message' => 'WebP 转换成功',
                    'filename' => $webp_filename,
                    'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($webp_filename),
                    'size' => $webp_size,
                    'size_text' => \LitePic\Core\Format::filesize($webp_size),
                    'before_size' => $before_size,
                    'after_size' => $webp_size,
                    'before_size_text' => \LitePic\Core\Format::filesize($before_size),
                    'after_size_text' => \LitePic\Core\Format::filesize($webp_size),
                    'saved_size' => $saved_size,
                    'saved_size_text' => \LitePic\Core\Format::filesize($saved_size),
                    'saved_percent' => $saved_percent,
                    'thumbnail_url' => $thumbnail_url,
                    'watermark' => $watermark,
                    'remote_storage' => $remote_sync,
                ]);
            } else {
                throw new Exception('WebP 转换失败');
            }
        } catch (Exception $e) {
            error_log("WebP conversion failed for {$file}: " . $e->getMessage());
            \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
        }
        break;

    case 'avif':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!\LitePic\Service\Image\ImageFormat::canConvertTo($ext, 'avif')) {
                \LitePic\Core\Response::error('该文件类型不支持转换 AVIF', 400);
            }

            $avif_path = preg_replace('/\.(jpg|jpeg|png|gif|heic|heif)$/i', '.avif', $path);
            if (!is_string($avif_path) || $avif_path === '') {
                throw new Exception('AVIF 输出路径无效');
            }
            $before_size = filesize($path);
            if ($before_size === false || $before_size <= 0) {
                throw new Exception('原文件大小不可读');
            }
            
            if ((new \LitePic\Service\Image\ConversionService())->toAvif($path)) {
                if (!file_exists($avif_path)) {
                    throw new Exception('AVIF 文件生成失败');
                }

                $avif_filename = \LitePic\Service\Image\PathService::identifierFromPath($avif_path) ?? basename($avif_path);
                $watermark = (new \LitePic\Service\Image\WatermarkService())->apply($avif_filename);
                clearstatcache(true, $avif_path);
                $avif_size = filesize($avif_path);
                if ($avif_size === false || $avif_size <= 0) {
                    throw new Exception('AVIF 文件大小不可读');
                }
                $saved_size = max(0, $before_size - $avif_size);
                $saved_percent = $before_size > 0 ? round(($saved_size / $before_size) * 100, 1) : 0;
                
                $thumbnail_url = \LitePic\Service\Image\ImageUrl::forIdentifier($avif_filename);
                if ((new \LitePic\Service\Image\ThumbnailService())->create($avif_filename, true)) {
                    $thumbnail_url = \LitePic\Service\Image\ImageUrl::thumbnailUrl($avif_filename);
                }
                $remote_sync = (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($avif_filename);
                \LitePic\Core\Response::success([
                    'message' => 'AVIF 转换成功',
                    'filename' => $avif_filename,
                    'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($avif_filename),
                    'size' => $avif_size,
                    'size_text' => \LitePic\Core\Format::filesize($avif_size),
                    'before_size' => $before_size,
                    'after_size' => $avif_size,
                    'before_size_text' => \LitePic\Core\Format::filesize($before_size),
                    'after_size_text' => \LitePic\Core\Format::filesize($avif_size),
                    'saved_size' => $saved_size,
                    'saved_size_text' => \LitePic\Core\Format::filesize($saved_size),
                    'saved_percent' => $saved_percent,
                    'thumbnail_url' => $thumbnail_url,
                    'watermark' => $watermark,
                    'remote_storage' => $remote_sync,
                ]);
            } else {
                throw new Exception('AVIF 转换失败');
            }
        } catch (Exception $e) {
            error_log("AVIF conversion failed for {$file}: " . $e->getMessage());
            \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
        }
        break;

    case 'jpg':
    case 'png':
        $handle_convert_action($action);
        break;

    case 'rename':
        // 图库卡片双击文件名 → 输入新名 → Enter 触发。改的是 images.original_name
        // 列(对外显示名),磁盘上的实际文件名(随机 hash)不动。这样老链接 / 缩略图 /
        // 远程同步的 key 都不需要重写。扩展名永远保留原始的 — 用户输入的后缀会被丢掉,
        // 防止把 cat.jpg 改成 cat.php 或类似破坏 MIME 一致性。
        try {
            $new_name = trim((string)($_POST['new_name'] ?? ''));
            if ($new_name === '') {
                \LitePic\Core\Response::error('新文件名不能为空', 400);
            }
            // basename() 剥目录段,然后去掉 control 字符 / 路径分隔符 / shell 元字符
            // (跟 TelegramHandler::guessFilename 用同一套白名单)。
            $new_name = basename($new_name);
            $new_name = preg_replace('/[\x00-\x1f\x7f\\\\\/<>:"|?*]+/u', '', $new_name) ?? '';
            $new_name = trim($new_name);
            if ($new_name === '' || $new_name === '.' || $new_name === '..') {
                \LitePic\Core\Response::error('新文件名不合法', 400);
            }
            if (mb_strlen($new_name) > 120) {
                $new_name = mb_substr($new_name, 0, 120);
            }
            // 始终用原始磁盘文件的扩展名 — 用户输入的后缀全部丢掉。
            // 既防 MIME 漂移,也保证回放下载时浏览器识别正确。
            $orig_ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            $stem = (string)pathinfo($new_name, PATHINFO_FILENAME);
            if ($stem === '') {
                \LitePic\Core\Response::error('新文件名去掉扩展名后为空', 400);
            }
            $final_name = $orig_ext !== '' ? $stem . '.' . $orig_ext : $stem;

            (new \LitePic\Repository\ImageRepository())->recordOriginalName($file, $final_name);

            \LitePic\Core\Response::success([
                'message'       => '已重命名',
                'filename'      => $file,
                'original_name' => $final_name,
                // 给前端用的"卡片上显示的那个字符串"(已去掉扩展名)。
                'display_name'  => $stem,
            ]);
        } catch (Exception $e) {
            error_log("Rename failed for {$file}: " . $e->getMessage());
            \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
        }
        break;

    case 'regenerate_thumbnail':
        // 从图库卡片右键菜单触发：强制重新生成缩略图（覆盖旧的）。
        // 同步执行，立即返回新缩略图 URL 供前端 cache-bust 刷新。
        try {
            $thumbService = new \LitePic\Service\Image\ThumbnailService();
            if (!\LitePic\Service\Image\ThumbnailService::canGenerate($file)) {
                \LitePic\Core\Response::error('该图片格式不支持生成缩略图', 400);
            }
            $ok = $thumbService->create($file, true); // force=true 覆盖旧缩略图
            if (!$ok) {
                throw new Exception('缩略图生成失败（可能 GD 扩展或源文件有问题）');
            }
            // 顺便同步缩略图到远程（如启用）
            (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($file);
            \LitePic\Core\Response::success([
                'message' => '缩略图已重新生成',
                'thumb_url' => \LitePic\Service\Image\ImageUrl::thumbnailUrl($file),
            ]);
        } catch (Exception $e) {
            error_log("Thumbnail regeneration failed for {$file}: " . $e->getMessage());
            \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
        }
        break;

    case 'delete':
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_TYPES, true)) {
            \LitePic\Core\Response::error('只能删除允许的图片类型', 400);
        }
        (new \LitePic\Service\Storage\RemoteStorage())->deleteFileAndThumbnail($file);
        $webp = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
        $avif = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $path);
        if (is_string($webp) && file_exists($webp)) {
            (new \LitePic\Service\Storage\RemoteStorage())->deleteFileAndThumbnail((string)(\LitePic\Service\Image\PathService::identifierFromPath($webp) ?? basename($webp)));
        }
        if (is_string($avif) && file_exists($avif)) {
            (new \LitePic\Service\Storage\RemoteStorage())->deleteFileAndThumbnail((string)(\LitePic\Service\Image\PathService::identifierFromPath($avif) ?? basename($avif)));
        }

        // 无论原图删除是否成功，都尝试清理缩略图，避免残留
        (new \LitePic\Service\Image\ThumbnailService())->delete($file);
        if (file_exists($path) && !@unlink($path)) {
            \LitePic\Core\Response::error('删除失败', 500);
        }

        // 删除对应的 WebP / AVIF 文件（如果存在）
        if (is_string($webp) && file_exists($webp)) {
            @unlink($webp);
            (new \LitePic\Service\Image\ThumbnailService())->delete((string)(\LitePic\Service\Image\PathService::identifierFromPath($webp) ?? basename($webp)));
        }
        if (is_string($avif) && file_exists($avif)) {
            @unlink($avif);
            (new \LitePic\Service\Image\ThumbnailService())->delete((string)(\LitePic\Service\Image\PathService::identifierFromPath($avif) ?? basename($avif)));
        }
        $imageRepo = new \LitePic\Repository\ImageRepository();
        $imageRepo->delete($file);
        $message = (new \LitePic\Service\Storage\RemoteStorage())->credentialsValid() ? '删除成功，远程对象将在 24 小时后删除' : '删除成功';
        \LitePic\Core\Response::success(['message' => $message]);
        break;

    default:
        \LitePic\Core\Response::error('无效操作');
        break;
}

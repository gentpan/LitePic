<?php
declare(strict_types=1);

// API 响应必须保持 JSON，避免 warning/notices 混入响应体
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once 'config.php';
require_once 'functions.php';

// 在任何通用参数校验之前，先处理特殊动作
$action = (string)($_REQUEST['action'] ?? '');
if ($action === 'render_card') {
    header('Content-Type: text/html; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo '<b>Error</b> Method Not Allowed';
        exit;
    }

    require_once __DIR__ . '/lib/ImageCard.php';
    if (!is_api_request_authorized()) {
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

    $info = array_merge([
        'filename' => $img,
        'size' => 0,
        'time' => time(),
        'url' => get_img_url($img),
        'dimensions' => '',
    ], $info);

    $type = (string)($_POST['type'] ?? 'gallery');
    $isGallery = $type === 'gallery';

    try {
        $card = new ImageCard($info, $isGallery, $isGallery, $isGallery);
        echo $card->render();
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<b>Error</b> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($action === 'get_next_image') {
    if (!is_api_request_authorized()) {
        error_response('权限不足', 403);
    }

    $current_count = (int)($_GET['current_count'] ?? $_POST['current_count'] ?? 0);
    $count = (int)($_GET['count'] ?? $_POST['count'] ?? 1);
    if ($current_count < 0) $current_count = 0;
    if ($count <= 0) $count = 1;

    // 获取图片列表（确保 get_uploaded_images() 可用并返回正确排序）
    $all_images = get_uploaded_images();
    $total = count($all_images);

    $images = [];
    if ($current_count < $total) {
        $slice = array_slice($all_images, $current_count, $count);
        foreach ($slice as $img) {
            $info = get_image_info($img);
            if ($info) {
                $images[] = [
                    'filename'   => $img,
                    'url'        => get_img_url($img),
                    'thumb_url'  => (string)($info['thumb_url'] ?? get_img_url($img)),
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
if (!is_api_request_authorized()) {
    error_log("Unauthorized action attempt from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    error_response('权限不足', 403);
}

// 状态变更操作仅允许 POST，并校验 CSRF Token
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    error_response('仅支持 POST 请求', 405);
}

// 从 POST / GET 中读取参数（兼容前端混合传参）
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$file = (string)($_POST['file'] ?? $_GET['file'] ?? '');

if ($action === '') {
    error_response('未指定操作');
}

if ($file === '') {
    error_response('未指定文件');
}

// 管理员操作需要 CSRF 校验（第三方 API Key 仅允许上传/读取，不允许删除/压缩等管理操作）
if (is_admin()) {
    $csrf = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if (!csrf_token_verify($csrf)) {
        error_response('CSRF Token 无效或已过期', 403);
    }
}

// 获取文件路径
$path = get_file_path($file);

if (!file_exists($path)) {
    // 改进: 使用 404 状态码
    error_response('文件不存在', 404);
}

switch ($action) {
    case 'compress':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) {
                error_response('该文件类型不支持压缩（仅支持 JPG/JPEG/PNG）', 400);
            }

            $before_size = filesize($path);
            if ($before_size === false || $before_size <= 0) {
                throw new Exception('无法获取文件大小');
            }

            $compress_result = compress_image_by_mode($path, 85);
            $used = $compress_result['method'];

            if ($used === null) {
                throw new Exception('压缩失败（当前压缩模式未成功）');
            }

            clearstatcache(true, $path);
            $after_size = filesize($path);
            $saved_size = max(0, $before_size - $after_size);
            $saved_percent = $before_size > 0 ? round(($saved_size / $before_size) * 100, 1) : 0;

            create_thumbnail($file, true);
            $remote_sync = remote_storage_sync_file_and_thumbnail($file);
            success_response([
                'message' => '压缩成功',
                'method' => $used,
                'mode' => get_compression_mode(),
                'original_size' => format_filesize($before_size),
                'compressed_size' => format_filesize($after_size),
                'saved_size' => format_filesize($saved_size),
                'saved_percent' => $saved_percent,
                'size_text' => format_filesize($after_size),
                'remote_storage' => $remote_sync,
            ]);
        } catch (Exception $e) {
            error_log("Compression failed for {$file}: " . $e->getMessage());
            error_response(safe_error_message($e), 500);
        }
        break;

    case 'webp':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                error_response('该文件类型不支持转换 WebP（仅支持 JPG/JPEG/PNG/GIF）', 400);
            }

            $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
            if (!is_string($webp_path) || $webp_path === '') {
                throw new Exception('WebP 输出路径无效');
            }
            
            if (convert_to_webp($path)) {
                if (!file_exists($webp_path)) {
                    throw new Exception('WebP 文件生成失败');
                }

                $webp_filename = get_image_identifier_from_path($webp_path) ?? basename($webp_path);
                $webp_size = filesize($webp_path);
                
                create_thumbnail($webp_filename, true);
                $remote_sync = remote_storage_sync_file_and_thumbnail($webp_filename);
                success_response([
                    'message' => 'WebP 转换成功',
                    'filename' => $webp_filename,
                    'url' => get_img_url($webp_filename),
                    'size' => $webp_size,
                    'size_text' => format_filesize($webp_size),
                    'remote_storage' => $remote_sync,
                ]);
            } else {
                throw new Exception('WebP 转换失败');
            }
        } catch (Exception $e) {
            error_log("WebP conversion failed for {$file}: " . $e->getMessage());
            error_response(safe_error_message($e), 500);
        }
        break;

    case 'avif':
        try {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                error_response('该文件类型不支持转换 AVIF（仅支持 JPG/JPEG/PNG/GIF）', 400);
            }

            $avif_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $path);
            if (!is_string($avif_path) || $avif_path === '') {
                throw new Exception('AVIF 输出路径无效');
            }
            
            if (convert_to_avif($path)) {
                if (!file_exists($avif_path)) {
                    throw new Exception('AVIF 文件生成失败');
                }

                $avif_filename = get_image_identifier_from_path($avif_path) ?? basename($avif_path);
                $avif_size = filesize($avif_path);
                
                create_thumbnail($avif_filename, true);
                $remote_sync = remote_storage_sync_file_and_thumbnail($avif_filename);
                success_response([
                    'message' => 'AVIF 转换成功',
                    'filename' => $avif_filename,
                    'url' => get_img_url($avif_filename),
                    'size' => $avif_size,
                    'size_text' => format_filesize($avif_size),
                    'remote_storage' => $remote_sync,
                ]);
            } else {
                throw new Exception('AVIF 转换失败');
            }
        } catch (Exception $e) {
            error_log("AVIF conversion failed for {$file}: " . $e->getMessage());
            error_response(safe_error_message($e), 500);
        }
        break;

    case 'delete':
        remote_storage_delete_file_and_thumbnail($file);
        $webp = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
        $avif = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $path);
        if (is_string($webp) && file_exists($webp)) {
            remote_storage_delete_file_and_thumbnail((string)(get_image_identifier_from_path($webp) ?? basename($webp)));
        }
        if (is_string($avif) && file_exists($avif)) {
            remote_storage_delete_file_and_thumbnail((string)(get_image_identifier_from_path($avif) ?? basename($avif)));
        }

        // 无论原图删除是否成功，都尝试清理缩略图，避免残留
        delete_thumbnail($file);
        if (@unlink($path)) {
            // 删除对应的 WebP / AVIF 文件（如果存在）
            if (is_string($webp) && file_exists($webp)) {
                @unlink($webp);
                delete_thumbnail((string)(get_image_identifier_from_path($webp) ?? basename($webp)));
            }
            if (is_string($avif) && file_exists($avif)) {
                @unlink($avif);
                delete_thumbnail((string)(get_image_identifier_from_path($avif) ?? basename($avif)));
            }
            success_response(['message' => '删除成功']);
        } else {
            // 改进: 使用 500 状态码
            error_response('删除失败', 500);
        }
        break;

    case 'fix_open_basedir':
        // 仅 admin 可调用——is_api_request_authorized() 已覆盖此前置校验。
        $result = fix_open_basedir_for_proc();
        if (!empty($result['ok'])) {
            success_response(['message' => $result['note'] ?? '已修复']);
        } else {
            error_response($result['reason'] ?? '修复失败', 500);
        }
        break;

    default:
        error_response('无效操作');
        break;
}

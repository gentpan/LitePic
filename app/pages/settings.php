<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/Core/bootstrap.php';
}


if (!is_admin()) {
    header('Location: /upload');
    exit;
}

const SETTINGS_FLASH_COOKIE = 'settings_flash_once';
const SETTINGS_FLASH_TTL = 120;
const SETTINGS_DEFAULT_HOME_BACKGROUND = '/static/images/background.jpg';

$page_title = '系统设置';
$message = '';
$message_type = 'success';
$created_token = '';
$updated_home_background_url = '';
$updated_home_background_path = '';
$saved_settings = [];
if (!empty($_COOKIE[SETTINGS_FLASH_COOKIE])) {
    $raw = base64_decode((string)$_COOKIE[SETTINGS_FLASH_COOKIE], true);
    if (is_string($raw) && $raw !== '') {
        $flash = json_decode($raw, true);
        if (is_array($flash)) {
            $message = trim((string)($flash['message'] ?? ''));
            $message_type = ((string)($flash['type'] ?? 'success') === 'error') ? 'error' : 'success';
            $created_token = trim((string)($flash['created_token'] ?? ''));
        }
    }
    // 读取后立即清理，确保只显示一次
    setcookie(SETTINGS_FLASH_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bool_from_post(string $key): bool {
    return isset($_POST[$key]) && $_POST[$key] === '1';
}

function settings_compression_capability(): array {
    $metrics = get_server_runtime_metrics();
    $capability = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [];

    return [
        'gd' => !empty($capability['gd']),
        'imagick' => !empty($capability['imagick']),
        'avif' => !empty($capability['avif']),
        'webp' => !empty($capability['webp']),
    ];
}

function env_encode_value(string $value): string {
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
}

function write_env_values(string $env_path, array $updates): bool {
    $lines = [];
    if (is_file($env_path)) {
        $existing = file($env_path, FILE_IGNORE_NEW_LINES);
        if ($existing !== false) {
            $lines = $existing;
        }
    }

    $remaining = $updates;
    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches)) {
            continue;
        }
        $key = $matches[1];
        if (!array_key_exists($key, $remaining)) {
            continue;
        }
        $lines[$index] = $key . '=' . (string)$remaining[$key];
        unset($remaining[$key]);
    }

    if (!empty($remaining)) {
        if (!empty($lines) && trim((string)end($lines)) !== '') {
            $lines[] = '';
        }
        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . (string)$value;
        }
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    return file_put_contents($env_path, $content, LOCK_EX) !== false;
}

function write_user_ini_values(string $ini_path, array $updates): bool {
    $lines = [];
    if (is_file($ini_path)) {
        $existing = file($ini_path, FILE_IGNORE_NEW_LINES);
        if ($existing !== false) {
            $lines = $existing;
        }
    }

    $remaining = $updates;
    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }
        if (!preg_match('/^\s*([a-zA-Z0-9_.]+)\s*=/', $line, $matches)) {
            continue;
        }
        $key = trim((string)$matches[1]);
        if (!array_key_exists($key, $remaining)) {
            continue;
        }
        $lines[$index] = $key . '=' . (string)$remaining[$key];
        unset($remaining[$key]);
    }

    if (!empty($remaining)) {
        if (!empty($lines) && trim((string)end($lines)) !== '') {
            $lines[] = '';
        }
        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . (string)$value;
        }
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    return file_put_contents($ini_path, $content, LOCK_EX) !== false;
}

function settings_store_watermark_upload(string $field, array $allowed_extensions, ?string &$error = null): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    $error_code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error_code !== UPLOAD_ERR_OK) {
        $error = '上传文件失败，请检查 PHP 上传限制';
        return null;
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? '');
    $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions, true)) {
        $error = '上传格式不支持';
        return null;
    }
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        $error = '上传临时文件无效';
        return null;
    }
    if ($extension === 'png') {
        $info = @getimagesize($tmp_name);
        if (!is_array($info) || (string)($info['mime'] ?? '') !== 'image/png') {
            $error = 'PNG 水印文件无效';
            return null;
        }
    }

    $dir = APP_ROOT . '/data/watermarks';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = '水印资源目录不可写';
        return null;
    }

    $prefix = $extension === 'png' ? 'image' : 'font';
    $target = $dir . '/' . $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    if (!move_uploaded_file($tmp_name, $target)) {
        $error = '保存上传文件失败';
        return null;
    }

    return $target;
}

function settings_home_background_url(string $web_path): string {
    $path = parse_url($web_path, PHP_URL_PATH);
    $file = is_string($path) && str_starts_with($path, '/') ? APP_ROOT . $path : '';
    $version = $file !== '' && is_file($file) ? (string)filemtime($file) : (string)time();
    return $web_path . (str_contains($web_path, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

function settings_store_home_background_upload(string $field, ?string &$error = null): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    $error_code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error_code !== UPLOAD_ERR_OK) {
        $error = '上传文件失败，请检查 PHP 上传限制';
        return null;
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        $error = '上传临时文件无效';
        return null;
    }

    $info = @getimagesize($tmp_name);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $error = '首页背景图仅支持 JPG/JPEG/PNG/WebP';
        return null;
    }

    $dir = APP_ROOT . '/static/images';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = '背景图目录不可写';
        return null;
    }
    if (!is_writable($dir)) {
        $error = '背景图目录不可写';
        return null;
    }

    $filename = 'background-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $target = $dir . '/' . $filename;
    $temp = $dir . '/.' . $filename . '.tmp';

    if ($mime === 'image/jpeg') {
        if (!move_uploaded_file($tmp_name, $temp)) {
            $error = '写入首页背景失败';
            return null;
        }
    } else {
        if (!extension_loaded('gd')) {
            $error = 'PNG/WebP 转 JPG 需要启用 GD；请改传 JPG/JPEG';
            return null;
        }
        $create = $mime === 'image/png' ? 'imagecreatefrompng' : 'imagecreatefromwebp';
        if (!function_exists($create)) {
            $error = '当前 PHP GD 不支持该图片格式；请改传 JPG/JPEG';
            return null;
        }
        $source = @$create($tmp_name);
        if (!$source) {
            $error = '读取背景图失败';
            return null;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas) {
            imagedestroy($source);
            $error = '处理背景图失败';
            return null;
        }
        $fill = imagecolorallocate($canvas, 12, 12, 12);
        imagefill($canvas, 0, 0, $fill);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        $saved = imagejpeg($canvas, $temp, 90);
        imagedestroy($canvas);
        imagedestroy($source);
        if (!$saved) {
            $error = '转换背景图失败';
            return null;
        }
    }

    if (!rename($temp, $target)) {
        @unlink($temp);
        $error = '写入首页背景失败';
        return null;
    }

    @chmod($target, 0644);
    clearstatcache(true, $target);
    return '/static/images/' . $filename;
}

function settings_open_basedir_value(string $ini_path): string {
    $paths = [
        rtrim(APP_ROOT, '/') . '/',
        '/tmp/',
        '/proc/cpuinfo',
        '/proc/meminfo',
        '/proc/uptime',
        '/etc/os-release',
    ];

    if (is_file($ini_path)) {
        $lines = file($ini_path, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                if (!is_string($line) || !preg_match('/^\s*open_basedir\s*=\s*(.+)\s*$/', $line, $matches)) {
                    continue;
                }
                foreach (explode(PATH_SEPARATOR, trim((string)$matches[1])) as $path) {
                    $path = trim($path);
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
                break;
            }
        }
    }

    $normalized = [];
    foreach ($paths as $path) {
        $path = trim($path);
        if ($path === '') {
            continue;
        }
        if ($path === APP_ROOT) {
            $path = rtrim(APP_ROOT, '/') . '/';
        }
        $normalized[$path] = true;
    }

    return implode(PATH_SEPARATOR, array_keys($normalized));
}

function settings_normalize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        return '';
    }
    $host = parse_url(str_contains($domain, '://') ? $domain : ('https://' . $domain), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $domain = $host;
    }
    if (str_starts_with($domain, '*.')) {
        $domain = substr($domain, 2);
    }
    $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
    $domain = trim($domain, '.');
    return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : '';
}

function settings_hotlink_domains_from_input(string $domains): array {
    $items = [];
    $site_host = parse_url((string)SITE_URL, PHP_URL_HOST);
    if (is_string($site_host)) {
        $items[] = $site_host;
    }
    $request_host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($request_host !== '') {
        $items[] = $request_host;
    }
    foreach (explode(',', $domains) as $domain) {
        $items[] = $domain;
    }

    $normalized = [];
    foreach ($items as $item) {
        $domain = settings_normalize_domain((string)$item);
        if ($domain !== '') {
            $normalized[$domain] = true;
        }
    }

    return array_keys($normalized);
}

function settings_apache_hotlink_rules_block(string $domains, bool $allow_empty_referer): string {
    $allowed_domains = settings_hotlink_domains_from_input($domains);
    if (empty($allowed_domains)) {
        $allowed_domains = ['localhost'];
    }
    $domain_pattern = implode('|', array_map(static function (string $domain): string {
        return preg_quote($domain, '/');
    }, $allowed_domains));

    $lines = [
        '# BEGIN LitePic Hotlink Protection',
        '<IfModule mod_rewrite.c>',
        '    RewriteEngine On',
    ];
    if ($allow_empty_referer) {
        $lines[] = '    RewriteCond %{HTTP_REFERER} !^$';
    }
    $lines[] = '    RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?(' . $domain_pattern . ')(:[0-9]+)?(/|$) [NC]';
    $lines[] = '    RewriteRule ^uploads/.*\.(jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|tif)$ - [F,L]';
    $lines[] = '</IfModule>';
    $lines[] = '# END LitePic Hotlink Protection';

    return implode(PHP_EOL, $lines);
}

function settings_write_apache_hotlink_rules(string $htaccess_path, bool $enabled, string $domains, bool $allow_empty_referer): bool {
    if (!$enabled && !is_file($htaccess_path)) {
        return true;
    }

    $content = is_file($htaccess_path) ? file_get_contents($htaccess_path) : '';
    if (!is_string($content)) {
        return false;
    }

    $pattern = '/\R?# BEGIN LitePic Hotlink Protection\R.*?# END LitePic Hotlink Protection\R?/s';
    $content = preg_replace($pattern, PHP_EOL, $content) ?? $content;
    $content = rtrim($content) . PHP_EOL;

    if ($enabled) {
        $block = settings_apache_hotlink_rules_block($domains, $allow_empty_referer);
        $anchor = '# 真实文件/目录直接访问';
        if (str_contains($content, $anchor)) {
            $content = str_replace($anchor, $block . PHP_EOL . PHP_EOL . $anchor, $content);
        } else {
            $content .= PHP_EOL . $block . PHP_EOL;
        }
    }

    return file_put_contents($htaccess_path, $content, LOCK_EX) !== false;
}

function settings_apache_hotlink_rules_enabled(string $htaccess_path): bool {
    if (!is_file($htaccess_path)) {
        return false;
    }
    $content = file_get_contents($htaccess_path);
    return is_string($content) && str_contains($content, '# BEGIN LitePic Hotlink Protection');
}

function settings_is_ajax_request(): bool {
    $requested_with = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requested_with === 'xmlhttprequest'
        || str_contains($accept, 'application/json')
        || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');
}

function settings_json_response(array $payload, int $status_code = 200): void {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function settings_remote_storage_env_from_post(): array {
    $usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? REMOTE_STORAGE_USAGE)));
    if (!in_array($usage, ['backup', 'storage'], true)) {
        $usage = 'backup';
    }

    return [
        'REMOTE_STORAGE_MODE' => 'sync',
        'REMOTE_STORAGE_USAGE' => $usage,
        'S3_PROVIDER' => 's3',
        'S3_BUCKET' => env_encode_value(trim((string)($_POST['s3_bucket'] ?? S3_BUCKET))),
        'S3_REGION' => env_encode_value(trim((string)($_POST['s3_region'] ?? S3_REGION))),
        'S3_ENDPOINT' => env_encode_value(trim((string)($_POST['s3_endpoint'] ?? S3_ENDPOINT))),
        'S3_KEY' => env_encode_value(trim((string)($_POST['s3_key'] ?? S3_KEY))),
        'S3_SECRET' => env_encode_value(trim((string)($_POST['s3_secret'] ?? S3_SECRET))),
        'S3_PATH_PREFIX' => env_encode_value(trim((string)($_POST['s3_path_prefix'] ?? S3_PATH_PREFIX), '/')),
        'S3_PUBLIC_BASE_URL' => env_encode_value(trim((string)($_POST['s3_public_base_url'] ?? S3_PUBLIC_BASE_URL))),
    ];
}

function settings_remote_storage_required_complete(): bool {
    $usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? REMOTE_STORAGE_USAGE)));
    $base_complete = trim((string)($_POST['s3_bucket'] ?? S3_BUCKET)) !== ''
        && trim((string)($_POST['s3_endpoint'] ?? S3_ENDPOINT)) !== ''
        && trim((string)($_POST['s3_key'] ?? S3_KEY)) !== ''
        && trim((string)($_POST['s3_secret'] ?? S3_SECRET)) !== '';
    if (!$base_complete) {
        return false;
    }

    return $usage !== 'storage' || trim((string)($_POST['s3_public_base_url'] ?? S3_PUBLIC_BASE_URL)) !== '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF 校验
    $csrf_token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_token_verify($csrf_token)) {
        $message = '安全令牌无效或已过期，请刷新页面后重试';
        $message_type = 'error';
        // 阻止后续处理
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $form_action = '';
    } else {
        $form_action = (string)($_POST['form_action'] ?? 'save_settings');
    }

    if ($form_action !== '') {

    if ($form_action === 'create_token') {
        $token_name = trim((string)($_POST['token_name'] ?? ''));
        $created = create_managed_api_token($token_name);
        if ($created === null) {
            $message = '创建 API Token 失败';
            $message_type = 'error';
        } else {
            $message = 'API Token 已创建，请立即复制保存。';
            $message_type = 'success';
            $created_token = $created;
        }
    } elseif ($form_action === 'revoke_token') {
        $token_id = trim((string)($_POST['token_id'] ?? ''));
        if ($token_id === '' || !revoke_managed_api_token($token_id)) {
            $message = '撤销 Token 失败';
            $message_type = 'error';
        } else {
            $message = 'Token 已撤销';
            $message_type = 'success';
        }
    } elseif ($form_action === 'add_compression_api') {
        $api_name = '';
        $api_key = trim((string)($_POST['compression_api_key'] ?? ''));
        if (!add_compression_api_key($api_name, $api_key)) {
            $message = '添加压缩 API Key 失败';
            $message_type = 'error';
        } else {
            $message = '压缩 API Key 已添加';
            $message_type = 'success';
        }
    } elseif ($form_action === 'toggle_compression_api') {
        $api_id = trim((string)($_POST['compression_api_id'] ?? ''));
        $enable = ((string)($_POST['enable'] ?? '0')) === '1';
        if ($api_id === '' || !set_compression_api_enabled($api_id, $enable)) {
            $message = '更新压缩 API 状态失败';
            $message_type = 'error';
        } else {
            $message = $enable ? '压缩 API 已启用' : '压缩 API 已禁用';
            $message_type = 'success';
        }
    } elseif ($form_action === 'delete_compression_api') {
        $api_id = trim((string)($_POST['compression_api_id'] ?? ''));
        if ($api_id === '' || !delete_compression_api_key($api_id)) {
            $message = '删除压缩 API Key 失败';
            $message_type = 'error';
        } else {
            $message = '压缩 API Key 已删除';
            $message_type = 'success';
        }
    } elseif ($form_action === 'save_remote_storage') {
        $usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? REMOTE_STORAGE_USAGE)));
        if (!in_array($usage, ['backup', 'storage'], true)) {
            $usage = 'backup';
        }
        $updated = write_env_values(APP_ROOT . '/.env', settings_remote_storage_env_from_post());
        if (!$updated) {
            $message = '保存 R2/S3 设置失败，请检查 .env 写入权限';
            $message_type = 'error';
        } else {
            $message = settings_remote_storage_required_complete()
                ? ($usage === 'storage'
                    ? 'R2/S3 设置已保存，云端存储已启用'
                    : 'R2/S3 设置已保存，远程备份已启用')
                : ($usage === 'storage'
                    ? 'R2/S3 设置已保存；云端存储需要填写公网访问域名和所有必填项'
                    : 'R2/S3 设置已保存；必填项未完整，远程备份已停用');
            $message_type = 'success';
        }
        $saved_settings = ['remote_storage_usage' => $usage];
    } elseif ($form_action === 'test_remote_storage') {
        $test = remote_storage_test_connection();
        if (!empty($test['success'])) {
            $message = '测试成功';
            $message_type = 'success';
        } else {
            $message = '测试失败';
            $message_type = 'error';
        }
    } elseif ($form_action === 'scan_import_uploads') {
        $scan_source_path = trim((string)($_POST['scan_source_path'] ?? ''));
        $scan_create_thumbnail = bool_from_post('scan_create_thumbnail');
        $scan_auto_compress = bool_from_post('scan_auto_compress');
        $scan_convert_format = strtolower(trim((string)($_POST['scan_convert_format'] ?? 'webp')));
        if (!in_array($scan_convert_format, ['webp', 'avif'], true)) {
            $scan_convert_format = 'webp';
        }
        $has_combined_scan_convert = array_key_exists('scan_auto_convert', $_POST);
        $scan_auto_convert = $has_combined_scan_convert
            ? bool_from_post('scan_auto_convert')
            : (bool_from_post('scan_auto_webp') || bool_from_post('scan_auto_avif'));
        if (!$has_combined_scan_convert) {
            if (bool_from_post('scan_auto_avif')) {
                $scan_convert_format = 'avif';
            } elseif (bool_from_post('scan_auto_webp')) {
                $scan_convert_format = 'webp';
            }
        }
        $scan_auto_webp = $scan_auto_convert && $scan_convert_format === 'webp';
        $scan_auto_avif = $scan_auto_convert && $scan_convert_format === 'avif';
        $scan_warnings = [];
        $runtime_capability = settings_compression_capability();
        if ($scan_auto_webp && empty($runtime_capability['webp'])) {
            $scan_auto_webp = false;
            $scan_warnings[] = 'WebP 支持未启用，已跳过导入时自动转 WebP';
        }
        if ($scan_auto_avif && empty($runtime_capability['avif'])) {
            $scan_auto_avif = false;
            $scan_warnings[] = 'AVIF 支持未启用，已跳过导入时自动转 AVIF';
        }
        $report = scan_and_import_uploads([
            'create_thumbnail' => $scan_create_thumbnail,
            'auto_compress' => $scan_auto_compress,
            'auto_webp' => $scan_auto_webp,
            'auto_avif' => $scan_auto_avif,
            'source_path' => $scan_source_path,
        ]);
        $message = sprintf(
            '扫描完成：扫描 %d，导入 %d，重复 %d，失败 %d，导入任务 %d',
            (int)($report['scanned'] ?? 0),
            (int)($report['imported'] ?? 0),
            (int)($report['duplicates'] ?? 0),
            (int)($report['failed'] ?? 0),
            (int)($report['tasks_queued'] ?? 0)
        );
        if ((int)($report['tasks_queued'] ?? 0) > 0) {
            $message .= '；缩略图、压缩、转换等后处理已进入任务队列，请分批处理';
        }
        if (!empty($report['errors']) && is_array($report['errors'])) {
            $message .= '；错误：' . implode(' | ', array_slice($report['errors'], 0, 3));
        }
        if (!empty($scan_warnings)) {
            $message .= '；提示：' . implode('；', $scan_warnings);
        }
        $message_type = ((int)($report['failed'] ?? 0) > 0 || !empty($scan_warnings)) ? 'error' : 'success';
    } elseif ($form_action === 'process_import_tasks') {
        $report = import_task_process_queue(8);
        $message = sprintf(
            '导入任务处理完成：处理 %d，成功 %d，失败 %d，剩余 %d，缩略图 %d，压缩 %d，转 WebP %d，转 AVIF %d，水印 %d',
            (int)($report['processed'] ?? 0),
            (int)($report['succeeded'] ?? 0),
            (int)($report['failed'] ?? 0),
            (int)($report['pending'] ?? 0),
            (int)($report['thumb_created'] ?? 0),
            (int)($report['compressed'] ?? 0),
            (int)($report['webp_created'] ?? 0),
            (int)($report['avif_created'] ?? 0),
            (int)($report['watermark_applied'] ?? 0)
        );
        if (!empty($report['errors']) && is_array($report['errors'])) {
            $message .= '；错误：' . implode(' | ', array_slice($report['errors'], 0, 3));
        }
        $message_type = ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success';
    } elseif ($form_action === 'generate_all_thumbnails') {
        $report = generate_all_thumbnails(true);
        $message = sprintf(
            '缩略图生成完成：总计 %d，成功 %d，跳过 %d，失败 %d',
            (int)($report['total'] ?? 0),
            (int)($report['created'] ?? 0),
            (int)($report['skipped'] ?? 0),
            (int)($report['failed'] ?? 0)
        );
        $message_type = ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success';
    } elseif ($form_action === 'sync_remote_storage_all') {
        $report = remote_storage_sync_all_local_images();
        $message = (string)($report['message'] ?? '远程同步失败');
        $message_type = !empty($report['success']) ? 'success' : 'error';
    } elseif ($form_action === 'restore_remote_storage_all') {
        $report = remote_storage_restore_all_to_local();
        $message = (string)($report['message'] ?? '远程恢复失败');
        $message_type = !empty($report['success']) ? 'success' : 'error';
    } elseif ($form_action === 'purge_remote_storage') {
        $result = remote_storage_delete_all_objects();
        $message = (string)($result['message'] ?? '远程清理失败');
        $message_type = !empty($result['success']) ? 'success' : 'error';
    }
    }

    if ($form_action === 'save_settings') {
        $site_name = trim((string)($_POST['site_name'] ?? SITE_NAME));
        $site_description = trim((string)($_POST['site_description'] ?? SITE_DESCRIPTION));
        $max_file_size_mb = max(1, min(50, (int)($_POST['max_file_size_mb'] ?? (int)round(MAX_FILE_SIZE / 1024 / 1024))));
        $upload_allowed_types_raw = $_POST['upload_allowed_types'] ?? ALLOWED_UPLOAD_TYPES;
        if (!is_array($upload_allowed_types_raw)) {
            $upload_allowed_types_raw = explode(',', (string)$upload_allowed_types_raw);
        }
        $upload_allowed_types = [];
        foreach ($upload_allowed_types_raw as $type) {
            $type = strtolower(ltrim(trim((string)$type), '.'));
            if ($type !== '' && in_array($type, SUPPORTED_IMAGE_TYPES, true) && !in_array($type, $upload_allowed_types, true)) {
                $upload_allowed_types[] = $type;
            }
        }
        $is_https = (
            (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        $admin_api_key = trim((string)($_POST['admin_api_key'] ?? ADMIN_API_KEY));
        $auto_compress_on_upload = bool_from_post('auto_compress_on_upload');
        $home_background_image = HOME_BACKGROUND_IMAGE;
        $home_background_reset = bool_from_post('home_background_reset');
        $settings_warnings = [];
        $settings_notes = [];
        $background_upload_error = null;
        if (empty($upload_allowed_types)) {
            $upload_allowed_types = ALLOWED_UPLOAD_TYPES;
            if (empty($upload_allowed_types)) {
                $upload_allowed_types = SUPPORTED_IMAGE_TYPES;
            }
            $settings_warnings[] = '至少需要保留一种允许上传格式，已保留原配置';
        }
        if ($home_background_reset) {
            $home_background_image = SETTINGS_DEFAULT_HOME_BACKGROUND;
            $updated_home_background_url = settings_home_background_url($home_background_image);
            $updated_home_background_path = ltrim($home_background_image, '/');
            $settings_notes[] = '首页背景图已恢复默认';
        } else {
            $uploaded_home_background = settings_store_home_background_upload('home_background_upload', $background_upload_error);
            if ($uploaded_home_background !== null) {
                $home_background_image = $uploaded_home_background;
                $updated_home_background_url = settings_home_background_url($home_background_image);
                $updated_home_background_path = ltrim($home_background_image, '/');
                $settings_notes[] = '首页背景图已更新';
            } elseif ($background_upload_error !== null) {
                $settings_warnings[] = '首页背景图上传失败：' . $background_upload_error;
            }
        }
        $runtime_capability = settings_compression_capability();
        $convert_preferred_format = trim((string)($_POST['convert_preferred_format'] ?? CONVERT_PREFERRED_FORMAT));
        if (!in_array($convert_preferred_format, ['webp', 'avif'], true)) {
            $convert_preferred_format = 'webp';
        }
        $has_combined_convert_input = array_key_exists('auto_convert_on_upload', $_POST);
        $auto_convert_on_upload = $has_combined_convert_input
            ? bool_from_post('auto_convert_on_upload')
            : (bool_from_post('auto_convert_webp_on_upload') || bool_from_post('auto_convert_avif_on_upload'));
        if (!$has_combined_convert_input) {
            if (bool_from_post('auto_convert_avif_on_upload')) {
                $convert_preferred_format = 'avif';
            } elseif (bool_from_post('auto_convert_webp_on_upload')) {
                $convert_preferred_format = 'webp';
            }
        }
        $auto_convert_webp_on_upload = $auto_convert_on_upload && $convert_preferred_format === 'webp';
        $auto_convert_avif_on_upload = $auto_convert_on_upload && $convert_preferred_format === 'avif';
        if ($auto_convert_webp_on_upload && empty($runtime_capability['webp'])) {
            $auto_convert_webp_on_upload = false;
            $settings_warnings[] = 'WebP 支持未启用，已关闭上传后自动转换 WebP';
        }
        if ($auto_convert_avif_on_upload && empty($runtime_capability['avif'])) {
            $auto_convert_avif_on_upload = false;
            $settings_warnings[] = 'AVIF 支持未启用，已关闭上传后自动转换 AVIF';
        }
        $keep_original_after_process = bool_from_post('keep_original_after_process');
        $watermark_enabled = bool_from_post('watermark_enabled');
        $watermark_type = strtolower(trim((string)($_POST['watermark_type'] ?? WATERMARK_TYPE)));
        if (!in_array($watermark_type, ['text', 'image'], true)) {
            $watermark_type = 'text';
        }
        $watermark_text = trim((string)($_POST['watermark_text'] ?? WATERMARK_TEXT));
        $watermark_position = trim((string)($_POST['watermark_position'] ?? WATERMARK_POSITION));
        if (!in_array($watermark_position, ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center'], true)) {
            $watermark_position = 'bottom-right';
        }
        $watermark_opacity = max(1, min(100, (int)($_POST['watermark_opacity'] ?? WATERMARK_OPACITY)));
        $watermark_font_size = max(8, min(72, (int)($_POST['watermark_font_size'] ?? WATERMARK_FONT_SIZE)));
        $watermark_margin = max(0, min(240, (int)($_POST['watermark_margin'] ?? WATERMARK_MARGIN)));
        $watermark_color = trim((string)($_POST['watermark_color'] ?? WATERMARK_COLOR));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $watermark_color)) {
            $watermark_color = '#ffffff';
        }
        $watermark_font_path = trim((string)($_POST['watermark_font_path'] ?? WATERMARK_FONT_PATH));
        $watermark_image_path = trim((string)($_POST['watermark_image_path'] ?? WATERMARK_IMAGE_PATH));
        $watermark_image_width = max(24, min(800, (int)($_POST['watermark_image_width'] ?? WATERMARK_IMAGE_WIDTH)));
        $watermark_panel_enabled = bool_from_post('watermark_panel_enabled');
        $watermark_panel_opacity = max(1, min(100, (int)($_POST['watermark_panel_opacity'] ?? WATERMARK_PANEL_OPACITY)));
        $watermark_panel_padding = max(0, min(80, (int)($_POST['watermark_panel_padding'] ?? WATERMARK_PANEL_PADDING)));
        $watermark_panel_radius = max(0, min(80, (int)($_POST['watermark_panel_radius'] ?? WATERMARK_PANEL_RADIUS)));
        $upload_error = null;
        $uploaded_font_path = settings_store_watermark_upload('watermark_font_upload', ['ttf', 'otf'], $upload_error);
        if ($uploaded_font_path !== null) {
            $watermark_font_path = $uploaded_font_path;
        } elseif ($upload_error !== null) {
            $settings_warnings[] = '字体上传失败：' . $upload_error;
        }
        $upload_error = null;
        $uploaded_image_path = settings_store_watermark_upload('watermark_image_upload', ['png'], $upload_error);
        if ($uploaded_image_path !== null) {
            $watermark_image_path = $uploaded_image_path;
        } elseif ($upload_error !== null) {
            $settings_warnings[] = 'PNG 水印上传失败：' . $upload_error;
        }
        if (bool_from_post('watermark_image_clear')) {
            $watermark_image_path = '';
        }
        $hotlink_protection_enabled = false;
        $apache_hotlink_protection_enabled = bool_from_post('apache_hotlink_protection_enabled');
        $hotlink_allowed_domains = trim((string)($_POST['hotlink_allowed_domains'] ?? implode(',', HOTLINK_ALLOWED_DOMAINS)));
        $hotlink_allow_empty_referer = bool_from_post('hotlink_allow_empty_referer');
        $access_log_stats_enabled = bool_from_post('access_log_stats_enabled');
        $access_log_paths = trim((string)($_POST['access_log_paths'] ?? implode(',', ACCESS_LOG_PATHS)));
        $access_log_cache_ttl = max(30, min(86400, (int)($_POST['access_log_cache_ttl'] ?? ACCESS_LOG_CACHE_TTL)));
        $access_log_max_mb = max(1, min(500, (int)($_POST['access_log_max_mb'] ?? (int)ceil(ACCESS_LOG_MAX_BYTES / 1024 / 1024))));
        $access_log_max_bytes = $access_log_max_mb * 1024 * 1024;
        $remote_storage_usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? REMOTE_STORAGE_USAGE)));
        if (!in_array($remote_storage_usage, ['backup', 'storage'], true)) {
            $remote_storage_usage = 'backup';
        }
        if (
            $remote_storage_usage === 'storage'
            && trim((string)($_POST['s3_public_base_url'] ?? S3_PUBLIC_BASE_URL)) === ''
        ) {
            $settings_warnings[] = '云端存储模式需要填写公网访问域名，否则图片链接会回退为本站本地地址';
        }

        if ($watermark_enabled && $watermark_type === 'text' && $watermark_text === '') {
            $watermark_enabled = false;
            $settings_warnings[] = '水印文字为空，已关闭自动水印';
        }
        if ($watermark_enabled && $watermark_type === 'text' && preg_match('/[^\x20-\x7E]/', $watermark_text) && $watermark_font_path === '') {
            $settings_warnings[] = '水印包含中文或其他非 ASCII 字符，建议配置字体文件路径，否则会跳过写入';
        }
        if ($watermark_image_path !== '' && (!is_file($watermark_image_path) || strtolower((string)pathinfo($watermark_image_path, PATHINFO_EXTENSION)) !== 'png')) {
            $watermark_image_path = '';
            $settings_warnings[] = 'PNG 水印路径无效，已清空图片水印';
        }
        if ($watermark_enabled && $watermark_type === 'image' && $watermark_image_path === '') {
            $watermark_enabled = false;
            $settings_warnings[] = '图片水印未配置 PNG 路径，已关闭自动水印';
        }
        // 互斥策略：开启自动转换时，自动压缩强制关闭，避免流程冲突
        if (($auto_convert_webp_on_upload || $auto_convert_avif_on_upload) && $auto_compress_on_upload) {
            $auto_compress_on_upload = false;
        }
        $compression_mode = trim((string)($_POST['compression_mode'] ?? COMPRESSION_MODE));
        $allowed_modes = ['tinypng', 'gd', 'imagemagick'];
        if (!in_array($compression_mode, $allowed_modes, true)) {
            $compression_mode = 'imagemagick';
        }
        $env_path = APP_ROOT . '/.env';
        $updated = write_env_values($env_path, array_merge([
            'SITE_NAME' => env_encode_value($site_name),
            'SITE_DESCRIPTION' => env_encode_value($site_description),
            'MAX_FILE_SIZE_MB' => (string)$max_file_size_mb,
            'UPLOAD_ALLOWED_TYPES' => implode(',', $upload_allowed_types),
            'COOKIE_SECURE' => $is_https ? 'true' : 'false',
            'ADMIN_API_KEY' => env_encode_value($admin_api_key),
            'HOME_BACKGROUND_IMAGE' => env_encode_value($home_background_image),
            'AUTO_COMPRESS_ON_UPLOAD' => $auto_compress_on_upload ? 'true' : 'false',
            'AUTO_CONVERT_WEBP_ON_UPLOAD' => $auto_convert_webp_on_upload ? 'true' : 'false',
            'AUTO_CONVERT_AVIF_ON_UPLOAD' => $auto_convert_avif_on_upload ? 'true' : 'false',
            'CONVERT_PREFERRED_FORMAT' => $convert_preferred_format,
            'KEEP_ORIGINAL_AFTER_PROCESS' => $keep_original_after_process ? 'true' : 'false',
            'COMPRESSION_MODE' => $compression_mode,
            'WATERMARK_ENABLED' => $watermark_enabled ? 'true' : 'false',
            'WATERMARK_TYPE' => $watermark_type,
            'WATERMARK_TEXT' => env_encode_value($watermark_text),
            'WATERMARK_POSITION' => $watermark_position,
            'WATERMARK_OPACITY' => (string)$watermark_opacity,
            'WATERMARK_FONT_SIZE' => (string)$watermark_font_size,
            'WATERMARK_MARGIN' => (string)$watermark_margin,
            'WATERMARK_COLOR' => env_encode_value($watermark_color),
            'WATERMARK_FONT_PATH' => env_encode_value($watermark_font_path),
            'WATERMARK_IMAGE_PATH' => env_encode_value($watermark_image_path),
            'WATERMARK_IMAGE_WIDTH' => (string)$watermark_image_width,
            'WATERMARK_PANEL_ENABLED' => $watermark_panel_enabled ? 'true' : 'false',
            'WATERMARK_PANEL_OPACITY' => (string)$watermark_panel_opacity,
            'WATERMARK_PANEL_PADDING' => (string)$watermark_panel_padding,
            'WATERMARK_PANEL_RADIUS' => (string)$watermark_panel_radius,
            'HOTLINK_PROTECTION_ENABLED' => $hotlink_protection_enabled ? 'true' : 'false',
            'HOTLINK_ALLOWED_DOMAINS' => env_encode_value($hotlink_allowed_domains),
            'HOTLINK_ALLOW_EMPTY_REFERER' => $hotlink_allow_empty_referer ? 'true' : 'false',
            'ACCESS_LOG_STATS_ENABLED' => $access_log_stats_enabled ? 'true' : 'false',
            'ACCESS_LOG_PATHS' => env_encode_value($access_log_paths),
            'ACCESS_LOG_CACHE_TTL' => (string)$access_log_cache_ttl,
            'ACCESS_LOG_MAX_BYTES' => (string)$access_log_max_bytes,
        ], settings_remote_storage_env_from_post()));

        $ini_path = APP_ROOT . '/.user.ini';
        // post_max_size 稍大于 upload_max_filesize，避免 multipart 头导致被 post_max_size 拒绝
        $post_max_size_mb = min(52, $max_file_size_mb + 2);
        $ini_updated = write_user_ini_values($ini_path, [
            'open_basedir' => settings_open_basedir_value($ini_path),
            'upload_max_filesize' => $max_file_size_mb . 'M',
            'post_max_size' => $post_max_size_mb . 'M',
            'max_file_uploads' => '50',
            'memory_limit' => '256M',
        ]);

        $htaccess_path = APP_ROOT . '/.htaccess';
        $htaccess_updated = settings_write_apache_hotlink_rules(
            $htaccess_path,
            $apache_hotlink_protection_enabled,
            $hotlink_allowed_domains,
            $hotlink_allow_empty_referer
        );
        if (!$htaccess_updated) {
            $settings_warnings[] = $apache_hotlink_protection_enabled
                ? '防盗链规则写入 .htaccess 失败，请检查站点根目录写入权限'
                : '防盗链规则从 .htaccess 移除失败，请检查站点根目录写入权限';
        } elseif ($apache_hotlink_protection_enabled) {
            $web_server = detect_web_server_software();
            if (empty($web_server['uses_htaccess'])) {
                $settings_warnings[] = sprintf(
                    '当前检测为 %s，.htaccess 通常不会生效，请按使用说明添加对应服务器规则',
                    (string)$web_server['label']
                );
            }
        }

        if (!$updated) {
            $message = '写入 .env 失败，请检查文件权限';
            $message_type = 'error';
        } elseif (!$ini_updated) {
            $message = '设置已写入 .env，但写入 .user.ini 失败，请检查文件权限';
            $message_type = 'error';
        } else {
            $settings_details = array_merge($settings_notes, $settings_warnings);
            $message = empty($settings_details) ? '保存成功' : '保存成功；' . implode('；', $settings_details);
            $message_type = empty($settings_warnings) ? 'success' : 'error';
        }

        $saved_settings = [
            'auto_compress_on_upload' => $auto_compress_on_upload,
            'auto_convert_on_upload' => $auto_convert_webp_on_upload || $auto_convert_avif_on_upload,
            'convert_preferred_format' => $convert_preferred_format,
            'upload_allowed_types' => $upload_allowed_types,
            'remote_storage_usage' => $remote_storage_usage,
            'keep_original_after_process' => $keep_original_after_process,
            'watermark_enabled' => $watermark_enabled,
            'watermark_type' => $watermark_type,
            'apache_hotlink_protection_enabled' => settings_apache_hotlink_rules_enabled($htaccess_path),
            'hotlink_allow_empty_referer' => $hotlink_allow_empty_referer,
            'watermark_panel_enabled' => $watermark_panel_enabled,
            'access_log_stats_enabled' => $access_log_stats_enabled,
        ];
    }

    if (settings_is_ajax_request()) {
        settings_json_response([
            'status' => $message_type === 'success' ? 'success' : 'error',
            'type' => $message_type === 'success' ? 'success' : 'error',
            'message' => $message,
            'action' => $form_action,
            'created_token' => $created_token,
            'home_background_url' => $updated_home_background_url,
            'home_background_path' => $updated_home_background_path,
            'saved_settings' => $saved_settings,
            'import_task_status' => import_task_queue_status(),
        ]);
    }

    // PRG: 非 AJAX 提交后重定向为 GET，避免浏览器刷新时重复提交表单
    $flash_payload = base64_encode((string)json_encode([
        'message' => $message,
        'type' => $message_type,
        'created_token' => $created_token,
    ], JSON_UNESCAPED_UNICODE));
    setcookie(SETTINGS_FLASH_COOKIE, $flash_payload, [
        'expires' => time() + SETTINGS_FLASH_TTL,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: /settings');
    exit;
}

$managed_tokens = get_managed_api_tokens();
$active_tokens = array_values(array_filter($managed_tokens, static function ($token) {
    return empty($token['revoked_at']);
}));
$compression_api_keys = get_compression_api_keys();
$compression_api_active_count = count(array_filter($compression_api_keys, static function (array $row): bool {
    return !empty($row['enabled']);
}));
$current_compression_mode = get_compression_mode();
$upload_format_labels = [
    'jpg' => 'JPG',
    'jpeg' => 'JPEG',
    'png' => 'PNG',
    'gif' => 'GIF',
    'webp' => 'WebP',
    'avif' => 'AVIF',
    'ico' => 'ICO',
    'svg' => 'SVG',
    'bmp' => 'BMP',
    'tiff' => 'TIFF',
    'tif' => 'TIF',
];
$import_task_status = import_task_queue_status();
$configured_upload_limit_bytes = (int)MAX_FILE_SIZE;
$runtime_upload_limit_bytes = get_php_upload_limit_bytes();
$metrics = get_server_runtime_metrics();

$current_php_sapi = (string)($metrics['php_sapi'] ?? php_sapi_name());
$server_ip = (string)($metrics['server_ip'] ?? '-');
$server_os = (string)($metrics['distro']['pretty'] ?? $metrics['os'] ?? '-');
$server_distro_id = strtolower((string)($metrics['distro']['id'] ?? ''));
// FontAwesome Brands icon for the detected distro; fallback to fa-linux for anything we don't have a brand mark for.
$distro_icon_map = [
    'debian'     => 'fa-debian',
    'ubuntu'     => 'fa-ubuntu',
    'fedora'     => 'fa-fedora',
    'centos'     => 'fa-centos',
    'rhel'       => 'fa-redhat',
    'redhat'     => 'fa-redhat',
    'opensuse'   => 'fa-suse',
    'suse'       => 'fa-suse',
    'sles'       => 'fa-suse',
];
$server_distro_icon = $distro_icon_map[$server_distro_id] ?? 'fa-linux';
$server_uptime = (string)($metrics['uptime_text'] ?? '-');
$availability_24h_percent = isset($metrics['availability_24h_percent']) && is_numeric($metrics['availability_24h_percent'])
    ? max(0.0, min(100.0, (float)$metrics['availability_24h_percent']))
    : 0.0;
$compression_capability = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [
    'gd' => false,
    'imagick' => false,
    'avif' => false,
    'webp' => false,
];
$memory_text = (string)($metrics['memory']['text'] ?? '-');
$memory_peak_text = (string)($metrics['memory']['peak_text'] ?? '-');
$cpu_load_text = (string)($metrics['cpu_load']['text'] ?? '不可用');
$cpu_cores_text = isset($metrics['cpu_cores']) && is_numeric($metrics['cpu_cores']) ? (string)$metrics['cpu_cores'] : '不可用';
$disk_text = (string)($metrics['disk']['text'] ?? '-');
$disk_free_text = (string)($metrics['disk']['free_text'] ?? '-');
$memory_usage_percent = max(0.0, min(100.0, (float)($metrics['memory']['usage_percent'] ?? 0)));
$cpu_load_1 = isset($metrics['cpu_load']['load_1']) && is_numeric($metrics['cpu_load']['load_1']) ? (float)$metrics['cpu_load']['load_1'] : null;
$cpu_cores_num = isset($metrics['cpu_cores']) && is_numeric($metrics['cpu_cores']) ? (int)$metrics['cpu_cores'] : 0;
$cpu_load_percent = 0.0;
if ($cpu_load_1 !== null && $cpu_cores_num > 0) {
    $cpu_load_percent = max(0.0, min(100.0, round(($cpu_load_1 / $cpu_cores_num) * 100, 2)));
}
$disk_usage_percent = max(0.0, min(100.0, (float)($metrics['disk']['usage_percent'] ?? 0)));
$htaccess_path = APP_ROOT . '/.htaccess';
$apache_hotlink_rules_enabled = settings_apache_hotlink_rules_enabled($htaccess_path);
$htaccess_writable = is_file($htaccess_path) ? is_writable($htaccess_path) : is_writable(APP_ROOT);
$web_server = detect_web_server_software();
$server_software = (string)$web_server['raw'];
$server_label = (string)$web_server['label'];
$server_uses_htaccess = !empty($web_server['uses_htaccess']);
$server_uses_nginx_rules = !empty($web_server['uses_nginx_rules']);
$server_uses_caddyfile = !empty($web_server['uses_caddyfile']);
$home_background_path = parse_url(HOME_BACKGROUND_IMAGE, PHP_URL_PATH);
if (!is_string($home_background_path) || !str_starts_with($home_background_path, '/')) {
    $home_background_path = SETTINGS_DEFAULT_HOME_BACKGROUND;
}
$home_background_file = APP_ROOT . $home_background_path;
if (!is_file($home_background_file)) {
    $home_background_path = SETTINGS_DEFAULT_HOME_BACKGROUND;
    $home_background_file = APP_ROOT . $home_background_path;
}
$home_background_url = settings_home_background_url($home_background_path);
$home_background_label = ltrim($home_background_path, '/');
$default_home_background_url = settings_home_background_url(SETTINGS_DEFAULT_HOME_BACKGROUND);
$default_home_background_label = ltrim(SETTINGS_DEFAULT_HOME_BACKGROUND, '/');

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main settings-page settings-layout">
                <form method="post" enctype="multipart/form-data" class="settings-panel" id="settingsForm">
                    <?= csrf_token_input() ?>
                    <input type="hidden" name="form_action" value="save_settings">

                    <section class="settings-block-runtime">
                        <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-server" aria-hidden="true"></i>
                                <span>服务器信息</span>
                            </h3>
                            <p>运行环境与压缩能力检测</p>
                        </div>
                        <div class="runtime-section">
                            <h4 class="runtime-section-label">资源占用</h4>
<?php $gauge_r = 52; $gauge_c = 2 * M_PI * $gauge_r; ?>
                            <div class="runtime-resource-grid runtime-resource-gauges">
                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-memory" id="metricMemoryCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $memory_usage_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricMemoryPercent"><?= htmlspecialchars(number_format($memory_usage_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">内存占用</div>
                                    <div class="runtime-gauge-detail" id="metricMemoryDetail"><?= htmlspecialchars($memory_text) ?></div>
                                </div>

                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-cpu" id="metricCpuLoadCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $cpu_load_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricCpuLoadPercent"><?= htmlspecialchars(number_format($cpu_load_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">CPU 负载</div>
                                    <div class="runtime-gauge-detail" id="metricCpuLoadDetail"><?= htmlspecialchars($cpu_load_text) ?> · <?= htmlspecialchars($cpu_cores_text) ?> 核</div>
                                </div>

                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-disk" id="metricDiskCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $disk_usage_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricDiskPercent"><?= htmlspecialchars(number_format($disk_usage_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">磁盘占用</div>
                                    <div class="runtime-gauge-detail" id="metricDiskDetail">剩余 <?= htmlspecialchars($disk_free_text) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <h4 class="runtime-section-label">环境信息</h4>
                            <div class="runtime-meta-grid">
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-brands fa-php" aria-hidden="true"></i>
                                        <span>PHP 版本</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricPhpVersion"><?= htmlspecialchars((string)($metrics['php_version'] ?? PHP_VERSION)) ?></span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-light fa-desktop" aria-hidden="true"></i>
                                        <span>系统版本</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricOs" data-distro-id="<?= htmlspecialchars($server_distro_id) ?>">
                                        <?= htmlspecialchars($server_os) ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-light fa-network-wired" aria-hidden="true"></i>
                                        <span>服务器 IP</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricServerIp"><?= htmlspecialchars($server_ip) ?></span>
                                </article>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <h4 class="runtime-section-label">上传与能力</h4>
                            <?php $upload_ok = $runtime_upload_limit_bytes >= $configured_upload_limit_bytes; ?>
                            <div class="grid grid-cols-5 gap-3.5 runtime-capability-grid">
                                <article class="border border-border p-4 grid gap-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm text-gray">上传上限</span>
                                        <span class="text-sm text-dark whitespace-nowrap overflow-hidden text-ellipsis" id="metricUploadLimit">
                                            <?= htmlspecialchars(format_filesize($runtime_upload_limit_bytes) . ' / ' . format_filesize($configured_upload_limit_bytes)) ?>
                                        </span>
                                    </div>
                                    <span class="inline-flex items-center justify-center min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap <?= $upload_ok ? 'is-on' : 'is-warn' ?>" id="metricUploadStatus">
                                        <?= $upload_ok ? '一致' : '未生效' ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">GD 扩展</span>
                                    <span class="inline-flex items-center justify-center min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap <?= $compression_capability['gd'] ? 'is-on' : 'is-off' ?>" id="metricCapGd">
                                        <?= $compression_capability['gd'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">ImageMagick 扩展</span>
                                    <span class="inline-flex items-center justify-center min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap <?= $compression_capability['imagick'] ? 'is-on' : 'is-off' ?>" id="metricCapImagick">
                                        <?= $compression_capability['imagick'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">AVIF 支持</span>
                                    <span class="inline-flex items-center justify-center min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap <?= $compression_capability['avif'] ? 'is-on' : 'is-off' ?>" id="metricCapAvif">
                                        <?= $compression_capability['avif'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">WebP 支持</span>
                                    <span class="inline-flex items-center justify-center min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap <?= $compression_capability['webp'] ? 'is-on' : 'is-off' ?>" id="metricCapWebp">
                                        <?= $compression_capability['webp'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-sliders" aria-hidden="true"></i>
                                <span>基础设置</span>
                            </h3>
                            <p>站点信息、上传规则和压缩策略</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3.5">
                            <div class="grid gap-2">
                                <label for="siteName">站点名称</label>
                                <input id="siteName" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="siteDescription">站点描述</label>
                                <input id="siteDescription" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="site_description" value="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
                            </div>
                            <div class="grid gap-2 col-span-2">
                                <label for="homeBackgroundUpload">首页背景图替换</label>
                                <div class="settings-background-control">
                                    <div
                                        class="settings-background-preview"
                                        data-home-background-preview
                                        data-default-background-url="<?= htmlspecialchars($default_home_background_url, ENT_QUOTES, 'UTF-8') ?>"
                                        data-default-background-path="<?= htmlspecialchars($default_home_background_label, ENT_QUOTES, 'UTF-8') ?>"
                                        style="background-image: url('<?= htmlspecialchars($home_background_url, ENT_QUOTES, 'UTF-8') ?>');">
                                        <span data-home-background-label>当前背景：<?= htmlspecialchars($home_background_label) ?></span>
                                    </div>
                                    <div class="settings-background-upload grid gap-2">
                                        <input type="hidden" name="home_background_reset" value="0" data-home-background-reset-value>
                                        <div class="settings-background-file-row">
                                            <input
                                                id="homeBackgroundUpload"
                                                class="settings-file-input w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border"
                                                type="file"
                                                name="home_background_upload"
                                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                                data-home-background-input>
                                            <button
                                                type="button"
                                                class="settings-background-reset inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors"
                                                data-home-background-reset>
                                                <i class="fa-light fa-rotate-left"></i>
                                                恢复默认
                                            </button>
                                        </div>
                                        <p class="m-0 text-xs text-gray leading-relaxed" data-home-background-hint>选择文件后会先在左侧预览，点击页面底部“保存设置”后生效。上传后会保存为 <code>static/images/background-*.jpg</code>，不覆盖默认背景图。支持 JPG/JPEG；PNG/WebP 会尝试转换为 JPG 后保存到同一目录。</p>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <label for="maxFileSize">最大上传大小（MB）</label>
                                <input id="maxFileSize" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="1" max="50" name="max_file_size_mb" value="<?= (int)round(MAX_FILE_SIZE / 1024 / 1024) ?>">
                            </div>
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label for="compressionMode">压缩方式</label>
                                    <a class="inline-flex items-center gap-1 text-sm text-primary no-underline hover:underline" href="/docs#compression-modes">
                                        <span>了解更多</span>
                                        <i class="fa-light fa-arrow-up-right-from-square"></i>
                                    </a>
                                </div>
                                <select id="compressionMode" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" name="compression_mode">
                                    <option value="tinypng" <?= $current_compression_mode === 'tinypng' ? 'selected' : '' ?>>TinyPNG</option>
                                    <option value="gd" <?= $current_compression_mode === 'gd' ? 'selected' : '' ?>>GD</option>
                                    <option value="imagemagick" <?= $current_compression_mode === 'imagemagick' ? 'selected' : '' ?>>ImageMagick</option>
                                </select>
                            </div>
                            <div class="grid gap-2 col-span-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label>允许上传格式</label>
                                    <span class="settings-field-hint">未勾选的格式会在上传页隐藏，并被前端与后端同时拒绝</span>
                                </div>
                                <div class="settings-format-options" role="group" aria-label="允许上传格式">
                                    <?php foreach (SUPPORTED_IMAGE_TYPES as $type): ?>
                                        <?php $input_id = 'uploadAllowedType' . ucfirst($type); ?>
                                        <label class="settings-format-option" for="<?= htmlspecialchars($input_id) ?>">
                                            <input
                                                id="<?= htmlspecialchars($input_id) ?>"
                                                type="checkbox"
                                                name="upload_allowed_types[]"
                                                value="<?= htmlspecialchars($type) ?>"
                                                <?= in_array($type, ALLOWED_UPLOAD_TYPES, true) ? 'checked' : '' ?>>
                                            <span>.<?= htmlspecialchars($upload_format_labels[$type] ?? strtoupper($type)) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="settings-toggle-list">
                            <label class="settings-toggle-row" for="autoCompressOnUpload">
                                <span class="settings-toggle-copy">上传后自动压缩（支持 JPG/JPEG/PNG）</span>
                                <input id="autoCompressOnUpload" class="settings-switch-input" type="checkbox" name="auto_compress_on_upload" value="1" <?= AUTO_COMPRESS_ON_UPLOAD ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <div class="settings-toggle-row settings-toggle-row-control">
                                <span class="settings-toggle-copy">上传后自动转换（支持 JPG/JPEG/PNG/GIF）</span>
                                <div class="settings-toggle-controls">
                                    <div class="settings-radio-group" role="radiogroup" aria-label="上传转换格式">
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="webp" <?= CONVERT_PREFERRED_FORMAT === 'webp' ? 'checked' : '' ?>>
                                            <span>WebP</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="avif" <?= CONVERT_PREFERRED_FORMAT === 'avif' ? 'checked' : '' ?>>
                                            <span>AVIF</span>
                                        </label>
                                    </div>
                                    <label class="settings-switch-label" for="autoConvertOnUpload">
                                        <input id="autoConvertOnUpload" class="settings-switch-input" type="checkbox" name="auto_convert_on_upload" value="1" <?= (AUTO_CONVERT_WEBP_ON_UPLOAD || AUTO_CONVERT_AVIF_ON_UPLOAD) ? 'checked' : '' ?>>
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                </div>
                            </div>
                            <label class="settings-toggle-row" for="keepOriginalAfterProcess">
                                <span class="settings-toggle-copy">转换或压缩后保留原图</span>
                                <input id="keepOriginalAfterProcess" class="settings-switch-input" type="checkbox" name="keep_original_after_process" value="1" <?= KEEP_ORIGINAL_AFTER_PROCESS ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
                                <span>远程存储（R2 / S3）</span>
                            </h3>
                            <p>可作为远程备份，也可作为云端图片访问源</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3.5">
                            <div class="grid gap-2 col-span-2">
                                <label>远程用途</label>
                                <div class="settings-radio-group settings-remote-usage-group" role="radiogroup" aria-label="远程存储用途">
                                    <label class="settings-radio-option settings-radio-option-block">
                                        <input type="radio" name="remote_storage_usage" value="backup" <?= REMOTE_STORAGE_USAGE === 'backup' ? 'checked' : '' ?>>
                                        <span>
                                            <strong>远程备份</strong>
                                            <small>本地作为主存储，R2/S3 只保存备份副本，图片链接仍使用本站地址。</small>
                                        </span>
                                    </label>
                                    <label class="settings-radio-option settings-radio-option-block">
                                        <input type="radio" name="remote_storage_usage" value="storage" <?= REMOTE_STORAGE_USAGE === 'storage' ? 'checked' : '' ?>>
                                        <span>
                                            <strong>云端存储</strong>
                                            <small>LitePic 负责上传、压缩和转换，复制链接/API/图库优先使用 R2/S3 公网地址。</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Endpoint">Endpoint</label>
                                <input id="s3Endpoint" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_endpoint" value="<?= htmlspecialchars(S3_ENDPOINT) ?>" placeholder="https://<accountid>.r2.cloudflarestorage.com">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Bucket">Bucket</label>
                                <input id="s3Bucket" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_bucket" value="<?= htmlspecialchars(S3_BUCKET) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Key">Access Key</label>
                                <input id="s3Key" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_key" value="<?= htmlspecialchars(S3_KEY) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Secret">Secret Key</label>
                                <input id="s3Secret" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="password" name="s3_secret" value="<?= htmlspecialchars(S3_SECRET) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Region">Region</label>
                                <input id="s3Region" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_region" value="<?= htmlspecialchars(S3_REGION) ?>" placeholder="R2 建议 auto">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3PathPrefix">对象路径前缀</label>
                                <input id="s3PathPrefix" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_path_prefix" value="<?= htmlspecialchars(S3_PATH_PREFIX) ?>" placeholder="uploads">
                            </div>
                            <div class="grid gap-2 col-span-2" data-remote-public-url-field <?= REMOTE_STORAGE_USAGE === 'storage' ? '' : 'hidden' ?>>
                                <label for="s3PublicBaseUrl">公网访问域名<span data-remote-public-required><?= REMOTE_STORAGE_USAGE === 'storage' ? '（云端存储必填）' : '（云端存储时使用）' ?></span></label>
                                <input id="s3PublicBaseUrl" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="s3_public_base_url" value="<?= htmlspecialchars(S3_PUBLIC_BASE_URL) ?>" placeholder="https://cdn.example.com">
                            </div>
                        </div>

                        <p class="m-0 text-xs text-gray" data-remote-storage-note>说明：远程备份模式下，本地仍是主存储，R2/S3 只保存副本；云端存储模式下，公网访问域名必填，复制链接、API 返回和图库图片地址会优先使用云端地址。本地删除后，远程对象会进入 24 小时延迟删除队列。</p>

                        <div class="flex justify-start gap-2.5">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-primary text-white hover:bg-primary/90 transition-colors"
                                name="form_action"
                                value="save_remote_storage"
                                data-busy-text="正在保存 R2/S3 设置...">
                                <i class="fa-light fa-floppy-disk"></i>
                                保存 R2/S3 设置
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors" name="form_action" value="test_remote_storage">
                                <i class="fa-light fa-plug-circle-check"></i>
                                测试 R2/S3 连接
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors js-remote-sync-all-btn"
                                name="form_action"
                                value="sync_remote_storage_all"
                                data-confirm="确定要将所有本地图片同步到远程存储吗？此操作可能需要较长时间。"
                                data-confirm-title="全量同步确认"
                                data-busy-text="正在同步全部图片到远程存储，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-up"></i>
                                一键同步全部到 R2/S3
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors js-remote-restore-all-btn"
                                name="form_action"
                                value="restore_remote_storage_all"
                                data-confirm="确定要从远程存储恢复到本地吗？这会覆盖本地同名文件。"
                                data-confirm-title="全量恢复确认"
                                data-busy-text="正在从远程恢复到本地，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-down"></i>
                                一键恢复到本地
                            </button>
                            <button
                                type="submit"
                                data-busy-text="正在清空远程对象，请勿关闭页面..."
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-danger text-white hover:bg-danger/90 transition-colors js-remote-purge-btn"
                                name="form_action"
                                value="purge_remote_storage"
                                data-confirm="确认清空远程存储对象吗？将删除当前配置前缀下的所有对象，无法恢复。"
                                data-confirm-title="清空远程对象确认">
                                <i class="fa-light fa-trash-can-list"></i>
                                清空 R2/S3 远程对象
                            </button>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-folder-open" aria-hidden="true"></i>
                                <span>扫描导入</span>
                            </h3>
                            <p>扫描指定目录并导入图库，可选生成缩略图、压缩或转换</p>
                        </div>
                        <div class="grid gap-2">
                            <label for="scanSourcePath">扫描路径（留空默认 upload / uploads）</label>
                            <input
                                id="scanSourcePath"
                                class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border"
                                type="text"
                                name="scan_source_path"
                                value="<?= htmlspecialchars(trim((string)($_POST['scan_source_path'] ?? ''))) ?>"
                                placeholder="upload 或 /www/wwwroot/site/upload，多个路径用英文逗号分隔">
                            <p class="m-0 text-sm text-gray">会递归导入所选目录及所有子目录，导入到 uploads 时保留源目录内的相对路径。</p>
                        </div>
                        <div class="settings-toggle-list">
                            <label class="settings-toggle-row scan-option" for="scanCreateThumbnail">
                                <span class="settings-toggle-copy">导入时生成缩略图</span>
                                <input id="scanCreateThumbnail" class="settings-switch-input" type="checkbox" name="scan_create_thumbnail" value="1" checked>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <label class="settings-toggle-row scan-option" for="scanAutoCompress">
                                <span class="settings-toggle-copy">导入时自动压缩（JPG/JPEG/PNG）</span>
                                <input id="scanAutoCompress" class="settings-switch-input" type="checkbox" name="scan_auto_compress" value="1">
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <div class="settings-toggle-row settings-toggle-row-control scan-option">
                                <span class="settings-toggle-copy">导入时自动转换（JPG/JPEG/PNG/GIF）</span>
                                <div class="settings-toggle-controls">
                                    <div class="settings-radio-group" role="radiogroup" aria-label="导入转换格式">
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="webp" checked>
                                            <span>WebP</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="avif">
                                            <span>AVIF</span>
                                        </label>
                                    </div>
                                    <label class="settings-switch-label" for="scanAutoConvert">
                                        <input id="scanAutoConvert" class="settings-switch-input" type="checkbox" name="scan_auto_convert" value="1" checked>
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="settings-callout text-xs text-gray leading-relaxed">
                            导入后处理采用任务队列执行：扫描只负责导入，缩略图、压缩、转换、水印和远程同步会排队分批处理。当前待处理
                            <strong class="text-dark" data-import-task-pending><?= (int)($import_task_status['pending'] ?? 0) ?></strong>
                            个任务，曾处理失败
                            <strong class="text-danger" data-import-task-failed><?= (int)($import_task_status['failed'] ?? 0) ?></strong>
                            个；每次最多处理 8 个，避免一次请求占满服务器。
                        </div>
                        <div class="flex justify-start gap-2.5">
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors" name="form_action" value="scan_import_uploads">
                                <i class="fa-light fa-folder-open"></i>
                                扫描导入目录
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors" name="form_action" value="process_import_tasks">
                                <i class="fa-light fa-gears"></i>
                                处理导入任务
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors" name="form_action" value="generate_all_thumbnails">
                                <i class="fa-light fa-images"></i>
                                一键生成全部缩略图
                            </button>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-shield-halved" aria-hidden="true"></i>
                                <span>安全设置</span>
                            </h3>
                            <p>管理后台访问密钥（Cookie Secure 自动按 HTTPS 生效）</p>
                        </div>

                        <div class="grid gap-2">
                            <label for="adminApiKey">管理员 API Key</label>
                            <div class="relative">
                                <input id="adminApiKey" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border has-toggle pr-[50px]" type="password" name="admin_api_key" value="<?= htmlspecialchars(ADMIN_API_KEY) ?>" autocomplete="off">
                                <button
                                    type="button"
                                    class="secret-toggle-btn absolute right-px top-px bottom-px w-12 border-0 border-l border-border bg-transparent text-gray cursor-pointer"
                                    data-target="adminApiKey"
                                    aria-label="显示或隐藏 API Key"
                                    title="显示/隐藏 API Key">
                                    <i class="fa-light fa-eye"></i>
                                </button>
                            </div>
                        </div>

                    </section>

                </form>

                <section>
                    <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-key" aria-hidden="true"></i>
                            <span>API Token 管理（第三方上传）</span>
                        </h3>
                        <p>可创建、复制和撤销上传 Token</p>
                    </div>

                    <form method="post" class="settings-inline-form settings-token-form">
                        <?= csrf_token_input() ?>
                        <input type="hidden" name="form_action" value="create_token">
                        <input class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="token_name" placeholder="Token 名称（如：wordpress-prod）">
                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-primary text-white hover:bg-primary/90 transition-colors">
                            <i class="fa-light fa-key"></i>
                            创建 Token
                        </button>
                    </form>

                    <?php if ($created_token !== ''): ?>
                        <div class="settings-callout">
                            <strong>新 Token（仅显示一次）</strong>
                            <div class="settings-inline-form settings-token-form">
                                <input class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" readonly value="<?= htmlspecialchars($created_token) ?>">
                                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors copy-token-btn" data-copy="<?= htmlspecialchars($created_token) ?>">复制</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="m-0 text-sm text-gray">当前启用 Token：<?= count($active_tokens) ?> 个。出于安全原因，已创建 Token 不再长期明文展示。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>创建时间</th>
                                    <th>最近使用</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_tokens as $token): ?>
                                    <?php
                                    $name = (string)($token['name'] ?? 'token');
                                    $created_at = (string)($token['created_at'] ?? '-');
                                    $last_used_at = (string)($token['last_used_at'] ?? '-');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <td><?= htmlspecialchars($created_at) ?></td>
                                        <td><?= htmlspecialchars($last_used_at) ?></td>
                                        <td>启用中</td>
                                        <td>
                                            <form method="post" data-confirm="确定要撤销此 API Token 吗？使用此 Token 的应用将立即失效。" data-confirm-title="撤销 Token 确认">
                                                <?= csrf_token_input() ?>
                                                <input type="hidden" name="form_action" value="revoke_token">
                                                <input type="hidden" name="token_id" value="<?= htmlspecialchars((string)$token['id']) ?>">
                                                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-danger text-white hover:bg-danger/90 transition-colors">撤销</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($active_tokens)): ?>
                                    <tr class="settings-empty-row">
                                        <td colspan="5">
                                            <span class="settings-empty-state">
                                                <i class="fa-light fa-key" aria-hidden="true"></i>
                                                <span>暂无可用 Token，请创建新的 Token</span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-fingerprint" aria-hidden="true"></i>
                            <span>Passkey 管理</span>
                        </h3>
                        <p>无密码登录（生物识别 / 设备 PIN）</p>
                    </div>

                    <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-primary text-white hover:bg-primary/90 transition-colors settings-full-action" id="passkeyRegisterBtn">
                        <i class="fa-light fa-fingerprint"></i>
                        注册新 Passkey
                    </button>

                    <p class="m-0 text-sm text-gray">已注册 <span id="passkeyCount">0</span> 个 Passkey。支持系统 PIN、指纹、面容等生物识别方式登录。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse" id="passkeyTable">
                            <thead>
                                <tr>
                                    <th>凭证 ID</th>
                                    <th>注册时间</th>
                                    <th>最近使用</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="passkeyTableBody">
                                <tr>
                                    <td colspan="4">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-image" aria-hidden="true"></i>
                            <span>图片压缩 API 管理（<a class="settings-title-link" href="https://tinypng.com/" target="_blank" rel="noopener noreferrer"><span>TinyPNG</span><i class="fa-sharp fa-light fa-square-arrow-up-right" aria-hidden="true"></i></a>）</span>
                        </h3>
                        <p>多 Key 轮询与调用监控</p>
                    </div>

                    <form method="post" class="settings-inline-form settings-compression-form">
                        <?= csrf_token_input() ?>
                        <input type="hidden" name="form_action" value="add_compression_api">
                        <input class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="compression_api_key" placeholder="输入 TinyPNG API Key">
                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-primary text-white hover:bg-primary/90 transition-colors">
                            <i class="fa-light fa-plus"></i>
                            添加 Key
                        </button>
                    </form>

                    <p class="m-0 text-sm text-gray">已配置 <?= count($compression_api_keys) ?> 个，启用中 <?= $compression_api_active_count ?> 个。系统优先使用调用次数较少的 Key，并记录每个 Key 的调用统计。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr>
                                    <th>API Key</th>
                                    <th>状态</th>
                                    <th>总调用</th>
                                    <th>成功</th>
                                    <th>失败</th>
                                    <th>最近使用</th>
                                    <th>最近结果</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compression_api_keys as $row): ?>
                                    <?php
                                    $id = (string)($row['id'] ?? '');
                                    $api_key = (string)($row['api_key'] ?? '');
                                    $enabled = !empty($row['enabled']);
                                    $used_count = (int)($row['used_count'] ?? 0);
                                    $success_count = (int)($row['success_count'] ?? 0);
                                    $failed_count = (int)($row['failed_count'] ?? 0);
                                    $last_used_at = (string)($row['last_used_at'] ?? '-');
                                    $last_status_code = (int)($row['last_status_code'] ?? 0);
                                    $last_error = (string)($row['last_error'] ?? '');
                                    $masked = strlen($api_key) > 8
                                        ? substr($api_key, 0, 4) . str_repeat('*', max(0, strlen($api_key) - 8)) . substr($api_key, -4)
                                        : str_repeat('*', strlen($api_key));
                                    $last_result = $last_status_code > 0 ? 'HTTP ' . $last_status_code : '-';
                                    if ($last_error !== '') {
                                        $last_result .= ' / ' . $last_error;
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($masked) ?></td>
                                        <td><?= $enabled ? '启用中' : '已禁用' ?></td>
                                        <td><?= number_format($used_count) ?></td>
                                        <td><?= number_format($success_count) ?></td>
                                        <td><?= number_format($failed_count) ?></td>
                                        <td><?= htmlspecialchars($last_used_at) ?></td>
                                        <td><?= htmlspecialchars($last_result) ?></td>
                                        <td>
                                            <div class="flex gap-2 flex-wrap">
                                                <form method="post">
                                                    <?= csrf_token_input() ?>
                                                    <input type="hidden" name="form_action" value="toggle_compression_api">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
                                                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors"><?= $enabled ? '禁用' : '启用' ?></button>
                                                </form>
                                                <form method="post" data-confirm="确定要删除此压缩 API Key 吗？" data-confirm-title="删除 TinyPNG Key 确认">
                                                    <?= csrf_token_input() ?>
                                                    <input type="hidden" name="form_action" value="delete_compression_api">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-danger text-white hover:bg-danger/90 transition-colors">删除</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($compression_api_keys)): ?>
                                    <tr class="settings-empty-row">
                                        <td colspan="8">
                                            <span class="settings-empty-state">
                                                <i class="fa-light fa-image" aria-hidden="true"></i>
                                                <span>暂无压缩 API Key，请先添加</span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section>
                    <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-stamp" aria-hidden="true"></i>
                            <span>水印与防盗链</span>
                        </h3>
                        <p>上传后写入文字或图片水印，防盗链规则由后台同步</p>
                    </div>

                    <div class="settings-toggle-list">
                        <label class="settings-toggle-row" for="watermarkEnabled">
                            <span class="settings-toggle-copy">启用水印</span>
                            <input id="watermarkEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_enabled" value="1" <?= WATERMARK_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <label class="settings-toggle-row" for="hotlinkProtectionEnabled">
                            <span class="settings-toggle-copy">启用防盗链（保持 /uploads/... 原路径）</span>
                            <input id="hotlinkProtectionEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="apache_hotlink_protection_enabled" value="1" <?= $apache_hotlink_rules_enabled ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <label class="settings-toggle-row" for="hotlinkAllowEmptyReferer">
                            <span class="settings-toggle-copy">
                                允许无来源请求（直接打开图片 / 隐私浏览器不拦截）
                                <a class="settings-help-link" href="/docs#hotlink-empty-referer">说明</a>
                            </span>
                            <input id="hotlinkAllowEmptyReferer" form="settingsForm" class="settings-switch-input" type="checkbox" name="hotlink_allow_empty_referer" value="1" <?= HOTLINK_ALLOW_EMPTY_REFERER ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                    </div>

                    <div class="grid gap-3.5" data-watermark-config <?= WATERMARK_ENABLED ? '' : 'hidden' ?>>
                        <div class="settings-toggle-row settings-toggle-row-control">
                            <span class="settings-toggle-copy">水印类型</span>
                            <div class="settings-toggle-controls">
                                <div class="settings-radio-group" role="radiogroup" aria-label="水印类型">
                                    <label class="settings-radio-option">
                                        <input form="settingsForm" type="radio" name="watermark_type" value="text" <?= WATERMARK_TYPE === 'text' ? 'checked' : '' ?>>
                                        <span>文字水印</span>
                                    </label>
                                    <label class="settings-radio-option">
                                        <input form="settingsForm" type="radio" name="watermark_type" value="image" <?= WATERMARK_TYPE === 'image' ? 'checked' : '' ?>>
                                        <span>图片水印</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3.5">
                            <div class="grid gap-2">
                                <label for="watermarkPosition">水印位置</label>
                                <select id="watermarkPosition" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" name="watermark_position">
                                    <option value="bottom-right" <?= WATERMARK_POSITION === 'bottom-right' ? 'selected' : '' ?>>右下角</option>
                                    <option value="bottom-left" <?= WATERMARK_POSITION === 'bottom-left' ? 'selected' : '' ?>>左下角</option>
                                    <option value="top-right" <?= WATERMARK_POSITION === 'top-right' ? 'selected' : '' ?>>右上角</option>
                                    <option value="top-left" <?= WATERMARK_POSITION === 'top-left' ? 'selected' : '' ?>>左上角</option>
                                    <option value="center" <?= WATERMARK_POSITION === 'center' ? 'selected' : '' ?>>居中</option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkOpacity">水印透明度（1-100）</label>
                                <input id="watermarkOpacity" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="1" max="100" name="watermark_opacity" value="<?= (int)WATERMARK_OPACITY ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkMargin">水印边距</label>
                                <input id="watermarkMargin" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="0" max="240" name="watermark_margin" value="<?= (int)WATERMARK_MARGIN ?>">
                            </div>
                        </div>

                        <div class="grid gap-3.5" data-watermark-mode="text" <?= WATERMARK_TYPE === 'text' ? '' : 'hidden' ?>>
                            <strong class="text-dark text-sm">文字水印设置</strong>
                            <div class="grid grid-cols-2 gap-3.5">
                                <div class="grid gap-2">
                                    <label for="watermarkText">水印文字</label>
                                    <input id="watermarkText" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="watermark_text" value="<?= htmlspecialchars(WATERMARK_TEXT) ?>" placeholder="LitePic">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkColor">水印颜色</label>
                                    <input id="watermarkColor" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="watermark_color" value="<?= htmlspecialchars(WATERMARK_COLOR) ?>" placeholder="#ffffff">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkFontSize">水印字号</label>
                                    <input id="watermarkFontSize" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="8" max="72" name="watermark_font_size" value="<?= (int)WATERMARK_FONT_SIZE ?>">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkFontUpload">上传字体（TTF / OTF）</label>
                                    <input id="watermarkFontUpload" form="settingsForm" class="settings-file-input w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="file" name="watermark_font_upload" accept=".ttf,.otf,font/ttf,font/otf">
                                </div>
                                <div class="grid gap-2 col-span-2">
                                    <label for="watermarkFontPath">字体文件路径（默认尝试 Ubuntu）</label>
                                    <input id="watermarkFontPath" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="watermark_font_path" value="<?= htmlspecialchars(WATERMARK_FONT_PATH) ?>" placeholder="/path/to/font.ttf">
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3.5" data-watermark-mode="image" <?= WATERMARK_TYPE === 'image' ? '' : 'hidden' ?>>
                            <strong class="text-dark text-sm">图片水印设置</strong>
                            <div class="grid grid-cols-2 gap-3.5">
                                <div class="grid gap-2">
                                    <label for="watermarkImageUpload">上传 PNG 图片水印</label>
                                    <input id="watermarkImageUpload" form="settingsForm" class="settings-file-input w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="file" name="watermark_image_upload" accept="image/png,.png">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkImageWidth">PNG 水印最大宽度</label>
                                    <input id="watermarkImageWidth" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="24" max="800" name="watermark_image_width" value="<?= (int)WATERMARK_IMAGE_WIDTH ?>">
                                </div>
                                <div class="grid gap-2 col-span-2">
                                    <label for="watermarkImagePath">PNG 水印路径</label>
                                    <input id="watermarkImagePath" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="watermark_image_path" value="<?= htmlspecialchars(WATERMARK_IMAGE_PATH) ?>" placeholder="/path/to/watermark.png">
                                </div>
                                <?php if (WATERMARK_IMAGE_PATH !== ''): ?>
                                    <label class="settings-toggle-row col-span-2" for="watermarkImageClear">
                                        <span class="settings-toggle-copy">清空当前 PNG 图片水印</span>
                                        <input id="watermarkImageClear" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_image_clear" value="1">
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <label class="settings-toggle-row" for="watermarkPanelEnabled">
                            <span class="settings-toggle-copy">启用水印磨砂底层</span>
                            <input id="watermarkPanelEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_panel_enabled" value="1" <?= WATERMARK_PANEL_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <div class="grid grid-cols-3 gap-3.5" data-watermark-panel-settings <?= WATERMARK_PANEL_ENABLED ? '' : 'hidden' ?>>
                            <div class="grid gap-2">
                                <label for="watermarkPanelOpacity">磨砂层透明度（1-100）</label>
                                <input id="watermarkPanelOpacity" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="1" max="100" name="watermark_panel_opacity" value="<?= (int)WATERMARK_PANEL_OPACITY ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkPanelPadding">磨砂层内边距</label>
                                <input id="watermarkPanelPadding" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="0" max="80" name="watermark_panel_padding" value="<?= (int)WATERMARK_PANEL_PADDING ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkPanelRadius">磨砂层圆角</label>
                                <input id="watermarkPanelRadius" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="0" max="80" name="watermark_panel_radius" value="<?= (int)WATERMARK_PANEL_RADIUS ?>">
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3.5">
                        <div class="grid gap-2 col-span-2">
                            <label for="hotlinkAllowedDomains">防盗链允许域名</label>
                            <input id="hotlinkAllowedDomains" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="hotlink_allowed_domains" value="<?= htmlspecialchars(implode(',', HOTLINK_ALLOWED_DOMAINS)) ?>" placeholder="example.com,cdn.example.com">
                        </div>
                    </div>

                    <div class="settings-callout settings-callout-compact">
                        <strong>不改图片地址的服务器防盗链</strong>
                        <p class="m-0 text-xs text-gray">
                            当前检测：<?= htmlspecialchars($server_label) ?><?= $server_software !== '' ? '（' . htmlspecialchars($server_software) . '）' : '' ?>；
                            Apache/.htaccess 规则：<?= $apache_hotlink_rules_enabled ? '已写入' : '未写入' ?>；
                            写入权限：<?= $htaccess_writable ? '可写' : '不可写' ?>。
                            <?php if ($server_uses_htaccess): ?>
                                Apache 或支持 .htaccess 的面板环境可直接生效。
                            <?php elseif ($server_uses_nginx_rules): ?>
                                Nginx / OpenResty 需要在 Web 服务器配置中添加防盗链规则，详见 <a href="/docs#hotlink-protection">使用说明</a>。
                            <?php elseif ($server_uses_caddyfile): ?>
                                Caddy 需要在 Caddyfile 中添加防盗链规则，详见 <a href="/docs#hotlink-protection">使用说明</a>。
                            <?php else: ?>
                                未识别服务器类型，.htaccess 仅在 Apache / 兼容环境有效。
                            <?php endif; ?>
                        </p>
                    </div>

                    <p class="m-0 text-xs text-gray">说明：开启后保存设置会自动写入 .htaccess；关闭后保存设置会自动移除规则。允许无来源请求表示直接打开图片、浏览器隐藏 Referer 或部分隐私浏览器访问时不拦截；关闭后这类请求也会被拒绝。</p>
                </section>
                <section>
                    <div class="flex items-center justify-between gap-3 pb-2.5 border-b border-border">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-chart-line" aria-hidden="true"></i>
                            <span>访问日志统计</span>
                        </h3>
                        <p>读取 Web 服务器 access.log，统计图片请求次数</p>
                    </div>

                    <div class="settings-toggle-list">
                        <label class="settings-toggle-row" for="accessLogStatsEnabled">
                            <span class="settings-toggle-copy">启用 access.log 图片请求统计</span>
                            <input id="accessLogStatsEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="access_log_stats_enabled" value="1" <?= ACCESS_LOG_STATS_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                    </div>

                    <div class="grid grid-cols-2 gap-3.5">
                        <div class="grid gap-2">
                            <label for="accessLogPaths">access.log 路径（多个用英文逗号分隔）</label>
                            <input id="accessLogPaths" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" name="access_log_paths" value="<?= htmlspecialchars(implode(',', ACCESS_LOG_PATHS)) ?>" placeholder="/var/log/nginx/access.log,/var/log/apache2/access.log">
                        </div>
                        <div class="grid gap-2">
                            <label for="accessLogCacheTtl">统计缓存时间（秒）</label>
                            <input id="accessLogCacheTtl" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="30" max="86400" name="access_log_cache_ttl" value="<?= (int)ACCESS_LOG_CACHE_TTL ?>">
                        </div>
                        <div class="grid gap-2">
                            <label for="accessLogMaxMb">单个日志最多扫描（MB）</label>
                            <input id="accessLogMaxMb" form="settingsForm" class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="number" min="1" max="500" name="access_log_max_mb" value="<?= (int)ceil(ACCESS_LOG_MAX_BYTES / 1024 / 1024) ?>">
                        </div>
                    </div>

                    <p class="m-0 text-sm text-gray">说明：统计值来自当前可读取的 access.log；如果服务器做了日志轮转、CDN 缓存或浏览器缓存，数字只代表日志里记录到的请求次数。</p>
                </section>
                <div class="settings-save-actions">
                    <button type="submit" form="settingsForm" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-primary text-white hover:bg-primary/90 transition-colors">
                        <i class="fa-light fa-floppy-disk"></i>
                        保存设置
                    </button>
                </div>
</main>

<script>
(function () {
    const flashMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const flashType = <?= json_encode($message_type === 'success' ? 'success' : 'error', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let flashShown = false;

    const showFlash = () => {
        if (flashShown || !flashMessage) {
            return true;
        }
        if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
            window.ImgEt.Utils.showNotification(flashMessage, flashType);
            flashShown = true;
            return true;
        }
        return false;
    };

    // 立即尝试一次，避免 DOMContentLoaded 时序导致丢失
    showFlash();

    document.addEventListener('DOMContentLoaded', () => {
        if (!flashShown) {
            showFlash();
            if (!flashShown) {
                setTimeout(showFlash, 80);
            }
        }

        const toggles = document.querySelectorAll('.secret-toggle-btn[data-target], button[type="button"][data-target]');
        toggles.forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                if (!targetId) return;
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                const icon = btn.querySelector('i');
                if (!icon) return;
                icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
            });
        });

        const copyTokenValue = async (value) => {
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                notifySettings('Token 已复制', 'success');
            } catch (e) {
                notifySettings('复制失败，请手动复制', 'error');
            }
        };
        const bindCopyTokenButton = (btn) => {
            btn.addEventListener('click', async () => {
                const value = btn.getAttribute('data-copy') || '';
                await copyTokenValue(value);
            });
        };
        document.querySelectorAll('.copy-token-btn').forEach(bindCopyTokenButton);

        const notifySettings = (message, type = 'warning') => {
            if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                window.ImgEt.Utils.showNotification(message, type);
            }
        };

        const escapeAttr = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const updateImportTaskStatus = (status) => {
            if (!status || typeof status !== 'object') return;
            const pendingEl = document.querySelector('[data-import-task-pending]');
            const failedEl = document.querySelector('[data-import-task-failed]');
            if (pendingEl) pendingEl.textContent = String(Number(status.pending || 0));
            if (failedEl) failedEl.textContent = String(Number(status.failed || 0));
        };

        const showCreatedTokenPanel = (token) => {
            if (!token) return;
            const createForm = document.querySelector('input[name="form_action"][value="create_token"]')?.closest('form');
            if (!createForm) return;
            let panel = document.querySelector('[data-created-token-panel]');
            if (!panel) {
                panel = document.createElement('div');
                panel.className = 'settings-callout';
                panel.setAttribute('data-created-token-panel', '1');
                createForm.insertAdjacentElement('afterend', panel);
            }
            panel.innerHTML = `
                <strong>新 Token（仅显示一次）</strong>
                <div class="settings-inline-form settings-token-form">
                    <input class="w-full min-h-[50px] px-3 py-2.5 border border-border bg-surface text-dark rounded-md text-base leading-snug box-border" type="text" readonly value="${escapeAttr(token)}">
                    <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border border-border bg-light text-dark hover:bg-gray/10 transition-colors copy-token-btn" data-copy="${escapeAttr(token)}">复制</button>
                </div>
            `;
            const copyBtn = panel.querySelector('.copy-token-btn');
            if (copyBtn) bindCopyTokenButton(copyBtn);
        };

        const setButtonBusy = (button, busy) => {
            if (!button) return;
            if (busy) {
                button.dataset.originalDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
                button.classList.add('is-loading');
            } else {
                button.disabled = button.dataset.originalDisabled === '1';
                button.classList.remove('is-loading');
                delete button.dataset.originalDisabled;
            }
        };

        const bindHomeBackgroundPicker = () => {
            const input = document.querySelector('[data-home-background-input]');
            const preview = document.querySelector('[data-home-background-preview]');
            const label = document.querySelector('[data-home-background-label]');
            const hint = document.querySelector('[data-home-background-hint]');
            const resetInput = document.querySelector('[data-home-background-reset-value]');
            const resetButton = document.querySelector('[data-home-background-reset]');
            if (!input || !preview) return;

            let objectUrl = '';
            const defaultUrl = preview.getAttribute('data-default-background-url') || '/static/images/background.jpg';
            const defaultPath = preview.getAttribute('data-default-background-path') || 'static/images/background.jpg';
            const revokeObjectUrl = () => {
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = '';
                }
            };

            input.addEventListener('change', () => {
                const file = input.files && input.files[0] ? input.files[0] : null;
                revokeObjectUrl();

                if (!file) return;

                const filename = file.name || '';
                const isAllowedType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
                const isAllowedName = /\.(jpe?g|png|webp)$/i.test(filename);
                if (!isAllowedType && !isAllowedName) {
                    input.value = '';
                    notifySettings('首页背景图仅支持 JPG/JPEG/PNG/WebP', 'error');
                    return;
                }

                objectUrl = URL.createObjectURL(file);
                preview.style.backgroundImage = `url('${objectUrl.replace(/'/g, "\\'")}')`;
                if (resetInput) {
                    resetInput.value = '0';
                }
                if (label) {
                    label.textContent = `待保存背景：${filename}`;
                }
                if (hint) {
                    hint.textContent = '已选择新背景图，点击页面底部“保存设置”后写入配置。';
                }
            });

            resetButton?.addEventListener('click', () => {
                revokeObjectUrl();
                input.value = '';
                if (resetInput) {
                    resetInput.value = '1';
                }
                preview.style.backgroundImage = `url('${defaultUrl.replace(/'/g, "\\'")}')`;
                if (label) {
                    label.textContent = `待恢复默认：${defaultPath}`;
                }
                if (hint) {
                    hint.textContent = '已选择恢复默认背景，点击页面底部“保存设置”后写入配置。';
                }
            });

            window.addEventListener('pagehide', revokeObjectUrl);
        };

        bindHomeBackgroundPicker();

        function syncRemoteStorageUsage() {
            const usage = document.querySelector('input[name="remote_storage_usage"]:checked')?.value || 'backup';
            const publicField = document.querySelector('[data-remote-public-url-field]');
            const requiredLabel = document.querySelector('[data-remote-public-required]');
            const note = document.querySelector('[data-remote-storage-note]');
            if (publicField) {
                publicField.hidden = usage !== 'storage';
            }
            if (requiredLabel) {
                requiredLabel.textContent = usage === 'storage' ? '（云端存储必填）' : '（云端存储时使用）';
            }
            if (note) {
                note.textContent = usage === 'storage'
                    ? '说明：云端存储模式下，LitePic 本地保留处理缓存和图库索引，图片展示、复制链接和 API 返回会优先使用 R2/S3 公网地址。公网访问域名必填，远程上传失败时需要先处理失败原因。'
                    : '说明：远程备份模式下，本地仍是主存储，R2/S3 只保存副本；图片展示、复制链接和 API 返回仍使用本站 /uploads 地址。本地删除后，远程对象会进入 24 小时延迟删除队列。';
            }
        }

        document.querySelectorAll('input[name="remote_storage_usage"]').forEach((input) => {
            input.addEventListener('change', syncRemoteStorageUsage);
        });
        syncRemoteStorageUsage();

        const applySavedSettingsToDom = (settings) => {
            if (!settings || typeof settings !== 'object') {
                return;
            }

            const checkboxMap = {
                auto_compress_on_upload: 'autoCompressOnUpload',
                auto_convert_on_upload: 'autoConvertOnUpload',
                keep_original_after_process: 'keepOriginalAfterProcess',
                watermark_enabled: 'watermarkEnabled',
                apache_hotlink_protection_enabled: 'hotlinkProtectionEnabled',
                hotlink_allow_empty_referer: 'hotlinkAllowEmptyReferer',
                watermark_panel_enabled: 'watermarkPanelEnabled',
                access_log_stats_enabled: 'accessLogStatsEnabled',
            };
            Object.entries(checkboxMap).forEach(([key, id]) => {
                if (!Object.prototype.hasOwnProperty.call(settings, key)) {
                    return;
                }
                const input = document.getElementById(id);
                if (input instanceof HTMLInputElement && input.type === 'checkbox') {
                    input.checked = !!settings[key];
                }
            });

            const setRadioValue = (name, value) => {
                const input = document.querySelector(`input[type="radio"][name="${name}"][value="${String(value)}"]`);
                if (input instanceof HTMLInputElement) {
                    input.checked = true;
                }
            };
            if (Object.prototype.hasOwnProperty.call(settings, 'convert_preferred_format')) {
                setRadioValue('convert_preferred_format', settings.convert_preferred_format);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'watermark_type')) {
                setRadioValue('watermark_type', settings.watermark_type);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'remote_storage_usage')) {
                setRadioValue('remote_storage_usage', settings.remote_storage_usage);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'upload_allowed_types') && Array.isArray(settings.upload_allowed_types)) {
                const allowedUploadTypes = new Set(settings.upload_allowed_types.map((type) => String(type).toLowerCase()));
                document.querySelectorAll('input[name="upload_allowed_types[]"]').forEach((input) => {
                    if (input instanceof HTMLInputElement && input.type === 'checkbox') {
                        input.checked = allowedUploadTypes.has(String(input.value).toLowerCase());
                    }
                });
            }

            window.requestAnimationFrame(() => {
                if (typeof syncProcessToggles === 'function') {
                    syncProcessToggles();
                }
                if (typeof syncWatermarkConfig === 'function') {
                    syncWatermarkConfig();
                }
                if (typeof syncRemoteStorageUsage === 'function') {
                    syncRemoteStorageUsage();
                }
            });
        };

        const applyAjaxResultToDom = (form, submitter, data, action) => {
            updateImportTaskStatus(data.import_task_status);

            if (data.created_token) {
                showCreatedTokenPanel(data.created_token);
            }

            if (data.home_background_url) {
                const preview = document.querySelector('[data-home-background-preview]');
                if (preview) {
                    preview.style.backgroundImage = `url('${String(data.home_background_url).replace(/'/g, "\\'")}')`;
                }
                document.documentElement.style.setProperty(
                    '--home-background-image',
                    `url('${String(data.home_background_url).replace(/'/g, "\\'")}')`
                );
                const label = document.querySelector('[data-home-background-label]');
                if (label && data.home_background_path) {
                    label.textContent = `当前背景：${data.home_background_path}`;
                }
                const hint = document.querySelector('[data-home-background-hint]');
                if (hint) {
                    hint.innerHTML = '背景图已保存，首页下次打开会使用当前图片。上传文件保存为 <code>static/images/background-*.jpg</code>，不会覆盖默认背景图。';
                }
                const input = document.getElementById('homeBackgroundUpload');
                if (input) {
                    input.value = '';
                }
                const resetInput = document.querySelector('[data-home-background-reset-value]');
                if (resetInput) {
                    resetInput.value = '0';
                }
            }

            if (data.saved_settings) {
                applySavedSettingsToDom(data.saved_settings);
            }

            if (data.status !== 'success') {
                return;
            }

            if (action === 'create_token' || action === 'add_compression_api') {
                form.reset();
            }

            if (action === 'revoke_token' || action === 'delete_compression_api') {
                submitter?.closest('tr')?.remove();
            }

            if (action === 'toggle_compression_api') {
                const enableInput = form.querySelector('input[name="enable"]');
                const row = submitter?.closest('tr');
                const enabled = enableInput?.value === '1';
                if (enableInput) {
                    enableInput.value = enabled ? '0' : '1';
                }
                if (submitter) {
                    submitter.textContent = enabled ? '禁用' : '启用';
                }
                if (row && row.cells && row.cells[2]) {
                    row.cells[2].textContent = enabled ? '启用中' : '已禁用';
                }
            }
        };

        const submitSettingsAjax = async (form, submitter, options = {}) => {
            if (!form || form.dataset.ajaxPending === '1') return null;

            const formData = new FormData(form);
            if (Array.isArray(options.omitNames)) {
                options.omitNames.forEach((name) => formData.delete(name));
            }
            if (submitter?.name) {
                formData.set(submitter.name, submitter.value || '');
            }
            formData.set('ajax', '1');
            if (options.autosave) {
                formData.set('autosave', '1');
            }
            const action = String(formData.get('form_action') || 'save_settings');
            const busyText = submitter?.getAttribute('data-busy-text') || '';
            if (busyText && !options.silentSuccess) {
                notifySettings(busyText, 'info');
            }

            form.dataset.ajaxPending = '1';
            setButtonBusy(submitter, true);

            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json().catch(() => null);
                if (!data || typeof data !== 'object') {
                    throw new Error('服务器返回格式异常');
                }
                if (!options.silentSuccess || data.status !== 'success') {
                    notifySettings(data.message || (data.status === 'success' ? '操作完成' : '操作失败'), data.status === 'success' ? 'success' : 'error');
                }
                applyAjaxResultToDom(form, submitter, data, action);
                return data;
            } catch (error) {
                notifySettings(error && error.message ? error.message : '请求失败，请稍后重试', 'error');
                return null;
            } finally {
                setButtonBusy(submitter, false);
                delete form.dataset.ajaxPending;
            }
        };

        let lastSubmitter = null;
        document.addEventListener('click', (event) => {
            const button = event.target.closest('button[type="submit"], input[type="submit"]');
            if (button && document.querySelector('.settings-page')?.contains(button)) {
                lastSubmitter = button;
            }
        }, true);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !document.querySelector('.settings-page')?.contains(form)) {
                return;
            }
            event.preventDefault();

            const submitter = event.submitter || lastSubmitter || form.querySelector('button[type="submit"], input[type="submit"]');
            const confirmMessage = submitter?.getAttribute('data-confirm') || form.getAttribute('data-confirm') || '';
            const confirmTitle = submitter?.getAttribute('data-confirm-title') || form.getAttribute('data-confirm-title') || '操作确认';
            const run = () => submitSettingsAjax(form, submitter);

            if (confirmMessage) {
                if (window.ImgEt && window.ImgEt.DialogManager && typeof window.ImgEt.DialogManager.showConfirmDialog === 'function') {
                    window.ImgEt.DialogManager.showConfirmDialog(confirmTitle, confirmMessage, run);
                } else if (window.confirm(confirmMessage)) {
                    run();
                }
                return;
            }

            run();
        });

        const runtimeCapability = {
            webp: <?= !empty($compression_capability['webp']) ? 'true' : 'false' ?>,
            avif: <?= !empty($compression_capability['avif']) ? 'true' : 'false' ?>,
        };
        const capabilityInputs = Array.from(document.querySelectorAll('[data-requires-capability]'));
        const validateCapabilityToggle = (input, silent = false) => {
            if (!input || !input.checked) return true;
            const capability = input.getAttribute('data-requires-capability') || '';
            if (!capability || runtimeCapability[capability]) return true;

            input.checked = false;
            if (!silent) {
                const name = input.getAttribute('data-capability-name') || capability.toUpperCase() + ' 支持';
                notifySettings(name + '未启用，无法开启该自动转换选项', 'error');
            }
            return false;
        };
        capabilityInputs.forEach((input) => {
            validateCapabilityToggle(input, true);
            input.addEventListener('change', () => {
                validateCapabilityToggle(input);
                syncProcessToggles();
                syncScanProcessToggles();
            });
        });

        // 自动处理策略互斥：自动转换开启时禁用自动压缩
        const autoCompressInput = document.getElementById('autoCompressOnUpload');
        const autoConvertInput = document.getElementById('autoConvertOnUpload');
        const convertPreferredFormatInputs = Array.from(document.querySelectorAll('input[name="convert_preferred_format"]'));
        const getConvertPreferredFormat = () => {
            const checked = convertPreferredFormatInputs.find((input) => input.checked);
            return checked?.value === 'avif' ? 'avif' : 'webp';
        };
        const validateAutoConvertToggle = (silent = false) => {
            if (!autoConvertInput || !autoConvertInput.checked) return true;
            const format = getConvertPreferredFormat();
            if (runtimeCapability[format]) return true;

            autoConvertInput.checked = false;
            if (!silent) {
                notifySettings(format.toUpperCase() + ' 支持未启用，无法开启上传后自动转换', 'error');
            }
            return false;
        };
        const syncProcessToggles = () => {
            if (!autoCompressInput) return;
            const hasAutoConvert = !!autoConvertInput?.checked;
            if (hasAutoConvert) {
                autoCompressInput.checked = false;
                autoCompressInput.disabled = true;
            } else {
                autoCompressInput.disabled = false;
            }
        };
        if (autoCompressInput) {
            validateAutoConvertToggle(true);
            syncProcessToggles();
            autoConvertInput?.addEventListener('change', () => {
                validateAutoConvertToggle();
                syncProcessToggles();
            });
            convertPreferredFormatInputs.forEach((input) => input.addEventListener('change', () => {
                validateAutoConvertToggle();
                syncProcessToggles();
            }));
        }

        // 扫描导入后处理进入队列，压缩与转换可以同时入队
        const scanCompressInput = document.getElementById('scanAutoCompress');
        const scanConvertInput = document.getElementById('scanAutoConvert');
        const scanConvertFormatInputs = Array.from(document.querySelectorAll('input[name="scan_convert_format"]'));
        const getScanConvertFormat = () => {
            const checked = scanConvertFormatInputs.find((input) => input.checked);
            return checked?.value === 'avif' ? 'avif' : 'webp';
        };
        const validateScanConvertToggle = (silent = false) => {
            if (!scanConvertInput || !scanConvertInput.checked) return true;
            const format = getScanConvertFormat();
            if (runtimeCapability[format]) return true;

            scanConvertInput.checked = false;
            if (!silent) {
                notifySettings(format.toUpperCase() + ' 支持未启用，无法开启导入时自动转换', 'error');
            }
            return false;
        };
        const syncScanProcessToggles = () => {
            if (scanCompressInput) {
                scanCompressInput.disabled = false;
            }
        };
        if (scanCompressInput) {
            validateScanConvertToggle(true);
            syncScanProcessToggles();
            scanConvertInput?.addEventListener('change', () => {
                validateScanConvertToggle();
                syncScanProcessToggles();
            });
            scanConvertFormatInputs.forEach((input) => {
                input.addEventListener('change', () => {
                    validateScanConvertToggle();
                    syncScanProcessToggles();
                });
            });
        }

        const watermarkEnabledInput = document.getElementById('watermarkEnabled');
        const watermarkConfig = document.querySelector('[data-watermark-config]');
        const watermarkTypeInputs = Array.from(document.querySelectorAll('input[name="watermark_type"]'));
        const watermarkModeSections = Array.from(document.querySelectorAll('[data-watermark-mode]'));
        const watermarkPanelInput = document.getElementById('watermarkPanelEnabled');
        const watermarkPanelSettings = document.querySelector('[data-watermark-panel-settings]');
        const getWatermarkType = () => {
            const checked = watermarkTypeInputs.find((input) => input.checked);
            return checked?.value === 'image' ? 'image' : 'text';
        };
        const syncWatermarkConfig = () => {
            const enabled = !!watermarkEnabledInput?.checked;
            if (watermarkConfig) {
                watermarkConfig.hidden = !enabled;
            }

            const type = getWatermarkType();
            watermarkModeSections.forEach((section) => {
                section.hidden = !enabled || section.getAttribute('data-watermark-mode') !== type;
            });

            if (watermarkPanelSettings) {
                watermarkPanelSettings.hidden = !enabled || !watermarkPanelInput?.checked;
            }
        };
        if (watermarkEnabledInput) {
            syncWatermarkConfig();
            watermarkEnabledInput.addEventListener('change', syncWatermarkConfig);
            watermarkTypeInputs.forEach((input) => input.addEventListener('change', syncWatermarkConfig));
            watermarkPanelInput?.addEventListener('change', syncWatermarkConfig);
        }

        const settingsForm = document.getElementById('settingsForm');
        const autoSaveSettingNames = new Set([
            'auto_compress_on_upload',
            'auto_convert_on_upload',
            'convert_preferred_format',
            'upload_allowed_types[]',
            'keep_original_after_process',
            'watermark_enabled',
            'watermark_type',
            'apache_hotlink_protection_enabled',
            'hotlink_allow_empty_referer',
            'watermark_image_clear',
            'watermark_panel_enabled',
            'access_log_stats_enabled',
        ]);
        const autoSaveOmitNames = [
            'home_background_upload',
            'home_background_reset',
            'watermark_font_upload',
            'watermark_image_upload',
        ];
        const localToggleNoticeNames = new Set([
            'scan_create_thumbnail',
            'scan_auto_compress',
            'scan_auto_convert',
        ]);
        let autoSaveTimer = 0;
        let autoSaveSource = null;
        const getAutoSaveRow = () => autoSaveSource?.closest('.settings-toggle-row') || null;
        const getAutoSaveLabel = (source) => {
            const row = source?.closest?.('.settings-toggle-row');
            const copy = row?.querySelector?.('.settings-toggle-copy');
            const text = (copy?.textContent || source?.closest?.('label')?.textContent || source?.name || '设置')
                .replace(/\s+/g, ' ')
                .trim();
            return text || '设置';
        };
        const getAutoSaveSuccessMessage = (source) => {
            const label = getAutoSaveLabel(source);
            if (source instanceof HTMLInputElement && source.name === 'watermark_image_clear') {
                return source.checked ? '当前 PNG 图片水印已清空' : '清空图片水印已取消';
            }
            if (source instanceof HTMLInputElement && source.name === 'upload_allowed_types[]') {
                return '允许上传格式已保存';
            }
            if (source instanceof HTMLInputElement && source.type === 'checkbox') {
                return `${label}已${source.checked ? '开启' : '关闭'}`;
            }
            if (source instanceof HTMLInputElement && source.type === 'radio') {
                return `${label}已保存`;
            }
            return '设置已保存';
        };
        const runSettingsAutoSave = async () => {
            if (!settingsForm) return;
            if (settingsForm.dataset.ajaxPending === '1') {
                window.setTimeout(runSettingsAutoSave, 180);
                return;
            }

            const row = getAutoSaveRow();
            row?.classList.add('is-autosaving');
            const data = await submitSettingsAjax(settingsForm, null, {
                autosave: true,
                silentSuccess: true,
                omitNames: autoSaveOmitNames,
            });
            row?.classList.remove('is-autosaving');
            if (data && data.status === 'success') {
                row?.classList.add('is-autosaved');
                window.setTimeout(() => row?.classList.remove('is-autosaved'), 650);
                notifySettings(getAutoSaveSuccessMessage(autoSaveSource), 'success');
                if (autoSaveSource instanceof HTMLInputElement && autoSaveSource.name === 'watermark_image_clear') {
                    autoSaveSource.checked = false;
                    const imagePathInput = document.getElementById('watermarkImagePath');
                    if (imagePathInput instanceof HTMLInputElement) {
                        imagePathInput.value = '';
                    }
                }
            }
        };
        const scheduleSettingsAutoSave = (source) => {
            autoSaveSource = source;
            window.clearTimeout(autoSaveTimer);
            autoSaveTimer = window.setTimeout(runSettingsAutoSave, 220);
        };
        document.addEventListener('change', (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || !settingsForm || input.form !== settingsForm) {
                return;
            }
            if (localToggleNoticeNames.has(input.name)) {
                notifySettings(getAutoSaveSuccessMessage(input), 'success');
                return;
            }
            if (!autoSaveSettingNames.has(input.name)) {
                return;
            }
            if (input.type === 'radio' && !input.checked) {
                return;
            }
            scheduleSettingsAutoSave(input);
        }, true);

        // 服务器信息实时刷新
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el && typeof value === 'string') {
                el.textContent = value;
            }
        };
        const setCapability = (id, enabled) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('is-on', 'is-off', 'is-warn');
            el.classList.add(enabled ? 'is-on' : 'is-off');
            el.textContent = enabled ? '已启用' : '未启用';
        };
        const clampPercent = (value) => {
            const num = Number(value);
            if (!Number.isFinite(num)) return 0;
            return Math.max(0, Math.min(100, num));
        };
        const setMetricProgress = (barId, badgeId, percent, digits = 1) => {
            const normalized = clampPercent(percent);
            const bar = document.getElementById(barId);
            if (bar) {
                if (bar.tagName === 'circle' || bar.classList.contains('runtime-gauge-fill')) {
                    const r = parseFloat(bar.getAttribute('r') || '52');
                    const circumference = 2 * Math.PI * r;
                    bar.style.strokeDashoffset = String(circumference * (1 - normalized / 100));
                } else {
                    bar.style.width = normalized.toFixed(2) + '%';
                }
            }
            const badge = document.getElementById(badgeId);
            if (badge) {
                badge.textContent = normalized.toFixed(digits) + '%';
            }
        };
        const updateUptimeDisplay = (uptimeText) => {
            const raw = typeof uptimeText === 'string' ? uptimeText.trim() : '';
            setText('metricUptime', raw || '-');

            let duration = raw || '-';
            let users = '-';

            if (raw) {
                const upMatch = raw.match(/\bup\s+(.+?)(?:,\s+\d+\s+users?|\s+\d+\s+users?|\s+load averages?:)/i);
                if (upMatch && upMatch[1]) {
                    duration = upMatch[1].trim().replace(/,\s*$/, '');
                }
                const userMatch = raw.match(/,\s*(\d+)\s+users?/i);
                if (userMatch && userMatch[1]) {
                    users = userMatch[1] + ' 人';
                }
            }

            setText('metricUptimeDuration', duration);
            setText('metricUptimeUsers', users);
        };

        updateUptimeDisplay(<?= json_encode($server_uptime, JSON_UNESCAPED_UNICODE) ?>);
        setText('metricAvailability24h', <?= json_encode(number_format($availability_24h_percent, 1) . '%', JSON_UNESCAPED_UNICODE) ?>);

        const updateSystemStatus = async () => {
            try {
                const resp = await fetch('/api/system_status.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await resp.json();
                if (!resp.ok || !data || data.status !== 'success' || !data.data) {
                    throw new Error((data && data.message) ? data.message : '状态读取失败');
                }

                const s = data.data;
                setText('metricPhpVersion', String(s.php_version ?? '-'));
                setText('metricPhpSapi', String(s.php_sapi ?? '-'));
                (() => {
                    const el = document.getElementById('metricOs');
                    if (!el) return;
                    const id = String((s.distro && s.distro.id) || '').toLowerCase();
                    el.dataset.distroId = id;
                    const osText = String((s.distro && s.distro.pretty) || s.os || '-');
                    el.textContent = osText;
                })();
                setText('metricServerIp', String(s.server_ip ?? '-'));
                updateUptimeDisplay(String(s.uptime_text ?? '-'));
                const availability24h = Number(s.availability_24h_percent ?? NaN);
                setText('metricAvailability24h', Number.isFinite(availability24h) ? availability24h.toFixed(1) + '%' : '-');
                const memText = String((s.memory && s.memory.text) ? s.memory.text : '-');
                const cpuLoadText = String((s.cpu_load && s.cpu_load.text) ? s.cpu_load.text : '不可用');
                const cpuCoresVal = String((s.cpu_cores ?? '不可用'));
                const diskText = String((s.disk && s.disk.text) ? s.disk.text : '-');
                const diskFreeText = String((s.disk && s.disk.free_text) ? s.disk.free_text : '-');

                const memDetail = document.getElementById('metricMemoryDetail');
                if (memDetail) memDetail.textContent = memText;
                const cpuDetail = document.getElementById('metricCpuLoadDetail');
                if (cpuDetail) cpuDetail.textContent = cpuLoadText + ' · ' + cpuCoresVal + ' 核';
                const diskDetail = document.getElementById('metricDiskDetail');
                if (diskDetail) diskDetail.textContent = '剩余 ' + diskFreeText;

                setMetricProgress('metricMemoryCircle', 'metricMemoryPercent', s.memory && s.memory.usage_percent ? s.memory.usage_percent : 0);
                const cpuCores = Number(s.cpu_cores ?? 0);
                const load1 = Number((s.cpu_load && s.cpu_load.load_1) ?? NaN);
                const cpuLoadPercent = Number.isFinite(load1) && cpuCores > 0 ? (load1 / cpuCores) * 100 : 0;
                setMetricProgress('metricCpuLoadCircle', 'metricCpuLoadPercent', cpuLoadPercent);
                setMetricProgress('metricDiskCircle', 'metricDiskPercent', s.disk && s.disk.usage_percent ? s.disk.usage_percent : 0);

                const runtimeLimit = String((s.php_upload_limit_text ?? '') || '');
                const configuredLimit = String((s.config_upload_limit_text ?? '') || '');
                if (runtimeLimit && configuredLimit) {
                    setText('metricUploadLimit', runtimeLimit + ' / ' + configuredLimit);
                }
                const uploadStatusEl = document.getElementById('metricUploadStatus');
                if (uploadStatusEl) {
                    const uploadOk = !!s.upload_limit_ok;
                    uploadStatusEl.classList.remove('is-on', 'is-off', 'is-warn');
                    uploadStatusEl.classList.add(uploadOk ? 'is-on' : 'is-warn');
                    uploadStatusEl.textContent = uploadOk ? '一致' : '未生效';
                }

                const cap = s.capability || {};
                runtimeCapability.webp = !!cap.webp;
                runtimeCapability.avif = !!cap.avif;
                setCapability('metricCapGd', !!cap.gd);
                setCapability('metricCapImagick', !!cap.imagick);
                setCapability('metricCapAvif', !!cap.avif);
                setCapability('metricCapWebp', !!cap.webp);
                capabilityInputs.forEach((input) => validateCapabilityToggle(input, true));
                validateAutoConvertToggle(true);
                validateScanConvertToggle(true);
                syncProcessToggles();
                syncScanProcessToggles();
            } catch (err) {
                if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                    window.ImgEt.Utils.showNotification('服务器状态刷新失败', 'error');
                }
            }
        };
        setInterval(updateSystemStatus, 3000);

        // ==================== Passkey 管理 ====================
        const loadPasskeys = async () => {
            try {
                const res = await fetch('/api/passkey.php?action=list');
                const data = await res.json();
                const tbody = document.getElementById('passkeyTableBody');
                const countEl = document.getElementById('passkeyCount');
                if (!tbody || !countEl) return;

                if (data.status === 'success' && data.credentials && data.credentials.length > 0) {
                    countEl.textContent = data.credentials.length;
                    tbody.innerHTML = data.credentials.map(cred => `
                        <tr>
                            <td><code>${cred.credentialId.substring(0, 16)}...</code></td>
                            <td>${cred.createdAt}</td>
                            <td>${cred.lastUsedAt || '-'}</td>
                            <td>
                                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-sm text-sm font-medium cursor-pointer border-0 bg-danger text-white hover:bg-danger/90 transition-colors passkey-delete-btn" data-id="${escapeHtml(cred.credentialId)}">
                                    删除
                                </button>
                            </td>
                        </tr>
                    `).join('');

                    tbody.querySelectorAll('.passkey-delete-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const id = btn.getAttribute('data-id');
                            if (!id || !confirm('确定要删除此 Passkey 吗？')) return;
                            try {
                                const delRes = await fetch('/api/passkey.php?action=delete', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({ credentialId: id, csrf_token: window.CSRF_TOKEN || '' })
                                });
                                const delData = await delRes.json();
                                if (delData.status === 'success') {
                                    if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('Passkey 已删除', 'success');
                                    loadPasskeys();
                                } else {
                                    throw new Error(delData.message || '删除失败');
                                }
                            } catch (err) {
                                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification(err.message || '删除失败', 'error');
                            }
                        });
                    });
                } else {
                    countEl.textContent = '0';
                    tbody.innerHTML = '<tr class="settings-empty-row"><td colspan="4"><span class="settings-empty-state"><i class="fa-light fa-fingerprint" aria-hidden="true"></i><span>尚未注册 Passkey</span></span></td></tr>';
                }
            } catch (err) {
                console.error('Load passkeys error:', err);
            }
        };

        const registerPasskey = async () => {
            if (!window.PublicKeyCredential) {
                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('您的浏览器不支持 Passkey', 'error');
                return;
            }
            try {
                const res = await fetch('/api/passkey.php?action=register_options');
                const data = await res.json();
                if (data.status !== 'success') {
                    throw new Error(data.message || '获取注册选项失败');
                }
                const options = data;

                options.challenge = base64UrlToBuffer(options.challenge);
                options.user.id = base64UrlToBuffer(options.user.id);

                const credential = await navigator.credentials.create({ publicKey: options });
                if (!credential) {
                    throw new Error('用户取消了注册');
                }

                const verifyRes = await fetch('/api/passkey.php?action=register_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64Url(credential.response.attestationObject),
                        credentialId: bufferToBase64Url(credential.rawId)
                    })
                });

                const verifyData = await verifyRes.json();
                if (verifyData.status === 'success') {
                    if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('Passkey 注册成功', 'success');
                    loadPasskeys();
                } else {
                    throw new Error(verifyData.message || '注册验证失败');
                }
            } catch (error) {
                console.error('Passkey register error:', error);
                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification(error.message || 'Passkey 注册失败', 'error');
            }
        };

        const base64UrlToBuffer = (base64url) => {
            const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            const pad = 4 - (base64.length % 4);
            const padded = pad !== 4 ? base64 + '='.repeat(pad) : base64;
            const binary = atob(padded);
            const buffer = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                buffer[i] = binary.charCodeAt(i);
            }
            return buffer.buffer;
        };

        const bufferToBase64Url = (buffer) => {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        };

        const passkeyRegisterBtn = document.getElementById('passkeyRegisterBtn');
        if (passkeyRegisterBtn) {
            passkeyRegisterBtn.addEventListener('click', registerPasskey);
        }
        loadPasskeys();

    });

    window.addEventListener('load', () => {
        if (!flashShown) {
            showFlash();
        }
    }, { once: true });
})();
</script>

<?php require_once APP_ROOT . '/footer.php'; ?>

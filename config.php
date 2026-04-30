<?php
declare(strict_types=1);
/**
 * LitePic - 配置文件
 */

if (!function_exists('load_dotenv_file')) {
    /**
     * 轻量 .env 读取器（无外部依赖）
     */
    function load_dotenv_file(string $path): void {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false || $pos <= 0) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $value = str_replace(['\\n', '\\r', '\\"', "\\'"], ["\n", "\r", '"', "'"], $value);

            // 始终以 .env 文件为准覆盖当前进程环境，避免开关值在长驻进程中残留
            // 部分生产环境会禁用 putenv（disable_functions），需降级兼容
            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

load_dotenv_file(__DIR__ . '/.env');

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default): bool {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        if ($value === false || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_csv')) {
    function env_csv(string $key, array $default = []): array {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        $items = array_map('trim', explode(',', (string)$value));
        $items = array_filter($items, static function ($v) {
            return $v !== '';
        });

        return array_values($items);
    }
}

$is_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);
$default_host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$default_scheme = $is_https ? 'https' : 'http';

// 基础配置
define('SITE_NAME', env_value('SITE_NAME', 'LitePic'));
define('SITE_DESCRIPTION', env_value('SITE_DESCRIPTION', '轻量级图床程序'));
define('SITE_VERSION', '3.0.0');
$home_background_image = (string)env_value('HOME_BACKGROUND_IMAGE', '/static/images/background.jpg');
$home_background_image_path = parse_url($home_background_image, PHP_URL_PATH);
if (
    !is_string($home_background_image_path) ||
    !preg_match('#^/static/images/[A-Za-z0-9._/-]+$#', $home_background_image_path) ||
    str_contains($home_background_image_path, '..')
) {
    $home_background_image = '/static/images/background.jpg';
}
define('HOME_BACKGROUND_IMAGE', $home_background_image);

// 网站路径配置
define('SITE_URL', env_value('SITE_URL', $default_scheme . '://' . $default_host));
define('UPLOAD_PATH_WEB', '/uploads/');
define('UPLOAD_PATH_LOCAL', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('STATIC_PATH', '/static/');

// 图片相关配置
$supported_image_types = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
    'ico', 'svg', 'bmp', 'tiff', 'tif'
];
define('SUPPORTED_IMAGE_TYPES', $supported_image_types);
define('ALLOWED_TYPES', SUPPORTED_IMAGE_TYPES);

$allowed_upload_types = array_values(array_intersect(
    array_map('strtolower', env_csv('UPLOAD_ALLOWED_TYPES', SUPPORTED_IMAGE_TYPES)),
    SUPPORTED_IMAGE_TYPES
));
if (empty($allowed_upload_types)) {
    $allowed_upload_types = SUPPORTED_IMAGE_TYPES;
}
define('ALLOWED_UPLOAD_TYPES', $allowed_upload_types);
define('MAX_FILE_SIZE', max(1, (int)env_value('MAX_FILE_SIZE_MB', 20)) * 1024 * 1024);
define('MIN_IMAGE_WIDTH', 20);
define('MIN_IMAGE_HEIGHT', 20);
define('THUMBNAIL_MAX_WIDTH', 640);
define('THUMBNAIL_MAX_HEIGHT', 360);
define('THUMBNAIL_QUALITY', 82);

// 安全配置
define('API_KEY_COOKIE', 'img_api_key');
define('ADMIN_API_KEY', env_value('ADMIN_API_KEY', ''));
define('THIRD_PARTY_API_KEYS', env_csv('THIRD_PARTY_API_KEYS', []));
define('COOKIE_LIFETIME', 30 * 24 * 60 * 60); // 30天
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', env_bool('COOKIE_SECURE', $is_https));
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Strict');

// 显示配置
define('ITEMS_PER_PAGE', 20); // 固定每页显示 20 张
define('GALLERY_COLUMNS', 4); // 固定图库 4 列
define('ENABLE_LAZY_LOAD', true);
define('DEFAULT_SORT', 'date-desc');

// 功能开关
if (!defined('ENABLE_WEBP')) {
    define('ENABLE_WEBP', true);
}
if (!defined('ENABLE_COMPRESSION')) {
    define('ENABLE_COMPRESSION', true);
}
if (!defined('ENABLE_EXIF_CLEAN')) {
    define('ENABLE_EXIF_CLEAN', true);
}
define('AUTO_COMPRESS_ON_UPLOAD', env_bool('AUTO_COMPRESS_ON_UPLOAD', false));
define('AUTO_CONVERT_WEBP_ON_UPLOAD', env_bool('AUTO_CONVERT_WEBP_ON_UPLOAD', false));
define('AUTO_CONVERT_AVIF_ON_UPLOAD', env_bool('AUTO_CONVERT_AVIF_ON_UPLOAD', false));
define('CONVERT_PREFERRED_FORMAT', in_array(strtolower((string)env_value('CONVERT_PREFERRED_FORMAT', 'webp')), ['webp', 'avif'], true) ? strtolower((string)env_value('CONVERT_PREFERRED_FORMAT', 'webp')) : 'webp');
define('KEEP_ORIGINAL_AFTER_PROCESS', env_bool('KEEP_ORIGINAL_AFTER_PROCESS', false));
define('COMPRESSION_MODE', (string)env_value('COMPRESSION_MODE', 'imagemagick'));

// 水印与防盗链
$watermark_position = strtolower((string)env_value('WATERMARK_POSITION', 'bottom-right'));
if (!in_array($watermark_position, ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center'], true)) {
    $watermark_position = 'bottom-right';
}
$watermark_color = (string)env_value('WATERMARK_COLOR', '#ffffff');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $watermark_color)) {
    $watermark_color = '#ffffff';
}
define('WATERMARK_ENABLED', env_bool('WATERMARK_ENABLED', false));
define('WATERMARK_TEXT', (string)env_value('WATERMARK_TEXT', SITE_NAME));
define('WATERMARK_POSITION', $watermark_position);
define('WATERMARK_OPACITY', max(1, min(100, (int)env_value('WATERMARK_OPACITY', 100))));
define('WATERMARK_FONT_SIZE', max(8, min(72, (int)env_value('WATERMARK_FONT_SIZE', 18))));
define('WATERMARK_MARGIN', max(0, min(240, (int)env_value('WATERMARK_MARGIN', 18))));
define('WATERMARK_COLOR', $watermark_color);
define('WATERMARK_FONT_PATH', (string)env_value('WATERMARK_FONT_PATH', ''));
$watermark_image_path = (string)env_value('WATERMARK_IMAGE_PATH', '');
$watermark_type = strtolower((string)env_value('WATERMARK_TYPE', $watermark_image_path !== '' ? 'image' : 'text'));
if (!in_array($watermark_type, ['text', 'image'], true)) {
    $watermark_type = 'text';
}
define('WATERMARK_TYPE', $watermark_type);
define('WATERMARK_IMAGE_PATH', $watermark_image_path);
define('WATERMARK_IMAGE_WIDTH', max(24, min(800, (int)env_value('WATERMARK_IMAGE_WIDTH', 160))));
define('WATERMARK_PANEL_ENABLED', env_bool('WATERMARK_PANEL_ENABLED', true));
define('WATERMARK_PANEL_OPACITY', max(1, min(100, (int)env_value('WATERMARK_PANEL_OPACITY', 34))));
define('WATERMARK_PANEL_PADDING', max(0, min(80, (int)env_value('WATERMARK_PANEL_PADDING', 10))));
define('WATERMARK_PANEL_RADIUS', max(0, min(80, (int)env_value('WATERMARK_PANEL_RADIUS', 10))));
define('HOTLINK_PROTECTION_ENABLED', env_bool('HOTLINK_PROTECTION_ENABLED', false));
define('HOTLINK_ALLOWED_DOMAINS', env_csv('HOTLINK_ALLOWED_DOMAINS', []));
define('HOTLINK_ALLOW_EMPTY_REFERER', env_bool('HOTLINK_ALLOW_EMPTY_REFERER', true));

// Web 服务器访问日志统计（统计 /uploads/... 与 /i/... 图片请求）
define('ACCESS_LOG_STATS_ENABLED', env_bool('ACCESS_LOG_STATS_ENABLED', true));
define('ACCESS_LOG_PATHS', env_csv('ACCESS_LOG_PATHS', []));
define('ACCESS_LOG_CACHE_TTL', max(30, min(86400, (int)env_value('ACCESS_LOG_CACHE_TTL', 300))));
define('ACCESS_LOG_MAX_BYTES', max(1048576, min(524288000, (int)env_value('ACCESS_LOG_MAX_BYTES', 20971520))));

// 远程存储（S3 兼容协议，Cloudflare R2 / AWS S3 通用）
$remote_storage_usage = strtolower((string)env_value('REMOTE_STORAGE_USAGE', env_value('REMOTE_STORAGE_MODE', 'backup')));
if ($remote_storage_usage === 'sync') {
    $remote_storage_usage = 'backup';
}
if (!in_array($remote_storage_usage, ['backup', 'storage'], true)) {
    $remote_storage_usage = 'backup';
}
define('REMOTE_STORAGE_USAGE', $remote_storage_usage);
define('REMOTE_STORAGE_MODE', 'sync');
define('S3_PROVIDER', 's3');
define('S3_BUCKET', (string)env_value('S3_BUCKET', ''));
define('S3_REGION', (string)env_value('S3_REGION', 'auto'));
define('S3_ENDPOINT', (string)env_value('S3_ENDPOINT', ''));
define('S3_KEY', (string)env_value('S3_KEY', ''));
define('S3_SECRET', (string)env_value('S3_SECRET', ''));
define('S3_PATH_PREFIX', trim((string)env_value('S3_PATH_PREFIX', 'uploads'), '/'));
define('S3_PUBLIC_BASE_URL', rtrim((string)env_value('S3_PUBLIC_BASE_URL', ''), '/'));
define('REMOTE_STORAGE_DELETE_DELAY_SECONDS', max(0, (int)env_value('REMOTE_STORAGE_DELETE_DELAY_SECONDS', 86400)));

// TinyPNG API Key 列表（建议放在 .env: TINIFY_API_KEYS=key1,key2）
define('TINIFY_API_KEYS', env_csv('TINIFY_API_KEYS', []));

// 存储配置
define('STORAGE_TYPE', 'date');
define('STORAGE_DATE_FORMAT', 'Y/m/d');

// 外链格式
define('LINK_FORMATS', [
    'url' => '[URL]',
    'html' => '<img src="[URL]" alt="[FILENAME]">',
    'markdown' => '![图片]([URL])',
    'bbcode' => '[img][URL][/img]'
]);

// 调试配置
define('DEBUG', env_bool('DEBUG', false));
define('LOG_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'logs');
define('ERROR_REPORTING', E_ALL);
define('DISPLAY_ERRORS', env_bool('DISPLAY_ERRORS', false));

// 错误处理配置
if (DEBUG) {
    error_reporting(ERROR_REPORTING);
    ini_set('display_errors', DISPLAY_ERRORS);
    ini_set('log_errors', true);
    ini_set('error_log', LOG_PATH . DIRECTORY_SEPARATOR . 'error.log');
}

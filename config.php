<?php
declare(strict_types=1);
/**
 * LitePic - 配置文件
 */

// .env 加载由 bootstrap.php 中的 Config::init() 统一处理，这里不再重复加载。

/**
 * env_value / env_bool / env_csv resolve config values in this order:
 *
 *   1. SQLite `settings` table (warmed into Config's static cache by
 *      bootstrap.php BEFORE this file runs). This is the canonical
 *      source of truth for everything editable via the settings UI.
 *   2. $_ENV / $_SERVER (loaded from .env). First-boot fallback only —
 *      after the install path or first save copies values into the DB,
 *      .env can be deleted without effect.
 *   3. The default argument.
 */

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        $cached = \LitePic\Core\Config::settingsLookup($key);
        if ($cached !== null) {
            return $cached === '' ? $default : $cached;
        }
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default): bool {
        $cached = \LitePic\Core\Config::settingsLookup($key);
        $raw = $cached !== null
            ? $cached
            : ($_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false));
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        $normalized = strtolower(trim((string)$raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_csv')) {
    function env_csv(string $key, array $default = []): array {
        $cached = \LitePic\Core\Config::settingsLookup($key);
        $raw = $cached !== null
            ? $cached
            : ($_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false));
        if ($raw === false || $raw === null || trim((string)$raw) === '') {
            return $default;
        }
        $items = array_map('trim', explode(',', (string)$raw));
        $items = array_filter($items, static function ($v) {
            return $v !== '';
        });
        return $items === [] ? $default : array_values($items);
    }
}

$is_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);
$default_host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$default_scheme = $is_https ? 'https' : 'http';

// 基础配置
define('LITEPIC_VERSION', '3.3.7');
define('SITE_NAME', env_value('SITE_NAME', 'LitePic'));
define('SITE_DESCRIPTION', env_value('SITE_DESCRIPTION', '轻量级图床程序'));
define('SITE_VERSION', LITEPIC_VERSION);
// 首页背景固定从 static/images/background.jpg 读取。源码部署，要换背景
// 直接替换这个文件即可，无需通过设置后台上传 — 这跟整个项目的 "源码即配置"
// 哲学一致。常量保留是给 header.php 用，不再支持 .env / DB 覆盖。
define('HOME_BACKGROUND_IMAGE', '/static/images/background.jpg');

// 网站路径配置
define('SITE_URL', env_value('SITE_URL', $default_scheme . '://' . $default_host));

/*
 * 物理存储目录名 — 图片源文件实际落盘的目录（相对项目根）。
 *
 * 默认 "uploads"（保持向后兼容，不动存量站点）。管理员可改成
 * "files" / "images" / "storage" 等任意名字，但要避开 LitePic 自己
 * 的子目录（api / app / assets / static / data / logs / i）以免路由冲突。
 *
 * 数据库 images.filename 存的是相对路径（如 2026/04/abc.jpg），
 * 不含目录名，所以改这个值不需要迁移数据 —— 只要把磁盘上的旧
 * 目录 rename 成新名即可。后续会有后台 UI 一键完成改名。
 *
 * 命名规则：
 *   - 小写字母开头
 *   - 1-30 个字符，仅 a-z 0-9 _ -
 *   - 不能是 LitePic 保留路径
 * 不合法时静默回退 "uploads"，避免一个错误的 .env 让站点 404。
 */
$_storage_reserved = ['api', 'app', 'assets', 'static', 'data', 'logs', 'i', 'release', 'wordpress', 'node_modules', 'tmp', 'cache'];
$_storage_raw = strtolower(trim((string)env_value('STORAGE_DIR', 'uploads')));
$_storage_dir = preg_match('/^[a-z][a-z0-9_-]{0,29}$/', $_storage_raw) === 1 && !in_array($_storage_raw, $_storage_reserved, true)
    ? $_storage_raw
    : 'uploads';
define('STORAGE_DIR', $_storage_dir);
unset($_storage_reserved, $_storage_raw, $_storage_dir);

define('UPLOAD_PATH_WEB', '/' . STORAGE_DIR . '/');
define('UPLOAD_PATH_LOCAL', __DIR__ . DIRECTORY_SEPARATOR . STORAGE_DIR . DIRECTORY_SEPARATOR);

// 图片相关配置
$supported_image_types = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif',
    'ico', 'svg', 'bmp', 'tiff', 'tif'
];
define('SUPPORTED_IMAGE_TYPES', $supported_image_types);
define('ALLOWED_TYPES', SUPPORTED_IMAGE_TYPES);

// 用户在设置里能自填扩展名（heic / jxl / raw / dng 等），不再跟
// SUPPORTED_IMAGE_TYPES 取交集。安全方面：上传时 UploadService::validateMime
// 仍会拒绝任何非 image/* 内容（哪怕扩展名叫 php），SettingsController 也对
// 明确危险后缀做了黑名单。这里只做格式 sanitize：lowercase / 去 . / 字母数字
// 限定 / 最多 10 字符。
$_raw_allowed = env_csv('UPLOAD_ALLOWED_TYPES', SUPPORTED_IMAGE_TYPES);
$allowed_upload_types = [];
foreach ($_raw_allowed as $_ext) {
    $_ext = strtolower(ltrim(trim((string)$_ext), '.'));
    $_ext = preg_replace('/[^a-z0-9]/', '', $_ext) ?? '';
    if ($_ext === '' || strlen($_ext) > 10) continue;
    if (!in_array($_ext, $allowed_upload_types, true)) {
        $allowed_upload_types[] = $_ext;
    }
}
if (empty($allowed_upload_types)) {
    $allowed_upload_types = SUPPORTED_IMAGE_TYPES;
}
define('ALLOWED_UPLOAD_TYPES', $allowed_upload_types);
define('MAX_FILE_SIZE', max(1, (int)env_value('MAX_FILE_SIZE_MB', 20)) * 1024 * 1024);
define('MIN_IMAGE_WIDTH', 20);
define('MIN_IMAGE_HEIGHT', 20);
// 单张图最大像素数 — 转换 / 压缩流水线的安全阀。超过这个值的图直接跳过
// 处理（保留原图，不转 WebP/AVIF / 不生成缩略图），避免一张超大图把整个
// PHP 进程的内存吃光。默认 100MP，对常见手机拍 + 单反足够（35mm 全画幅
// 一般在 50MP 上下；6100 万像素哈苏才会超）。可用 .env 调或在 settings 关掉。
define('IMAGE_PROCESS_MAX_PIXELS', max(0, (int)env_value('IMAGE_PROCESS_MAX_PIXELS', 100_000_000)));
// WebP / AVIF 输出质量（1-100，与 GD imagewebp/imageavif、Imagick 都通用）
define('WEBP_QUALITY', max(1, min(100, (int)env_value('WEBP_QUALITY', 80))));
define('AVIF_QUALITY', max(1, min(100, (int)env_value('AVIF_QUALITY', 80))));
// 格式转换引擎：
//   auto     — 优先 Imagick（更省内存，能处理 10080×3716 这种大图），不可用则回退 GD
//   imagick  — 强制 Imagick，不可用则失败（用户在重视大图能力的服务器上设置）
//   gd       — 强制 GD（兼容性最好但内存占用大，不适合超过 30MP 的图）
define('CONVERSION_ENGINE', in_array(strtolower((string)env_value('CONVERSION_ENGINE', 'auto')),
    ['auto', 'imagick', 'gd'], true) ? strtolower((string)env_value('CONVERSION_ENGINE', 'auto')) : 'auto');
define('THUMBNAIL_MAX_WIDTH', 640);
define('THUMBNAIL_MAX_HEIGHT', 360);
define('THUMBNAIL_QUALITY', 82);

// 安全配置
define('API_KEY_COOKIE', 'img_api_key');
// 默认管理员密码（首次启动 / 安装向导用）。登录时如果发现密码仍是默认值，
// 前端会强制弹窗要求改密码（详见 api/auth.php 的 must_change_password 标记）。
define('DEFAULT_ADMIN_API_KEY', '12345678');
define('ADMIN_API_KEY', env_value('ADMIN_API_KEY', DEFAULT_ADMIN_API_KEY));
define('THIRD_PARTY_API_KEYS', env_csv('THIRD_PARTY_API_KEYS', []));
define('COOKIE_LIFETIME', 30 * 24 * 60 * 60); // 30天
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', env_bool('COOKIE_SECURE', $is_https));
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Strict');

// 显示配置
define('ITEMS_PER_PAGE', 20); // 固定每页显示 20 张

// 压缩开关 — CompressionService 检查后才走 TinyPNG 路径，留这一项
if (!defined('ENABLE_COMPRESSION')) {
    define('ENABLE_COMPRESSION', true);
}
define('AUTO_COMPRESS_ON_UPLOAD', env_bool('AUTO_COMPRESS_ON_UPLOAD', false));
$convert_preferred_format = strtolower((string)env_value('CONVERT_PREFERRED_FORMAT', 'webp'));
if (!in_array($convert_preferred_format, ['webp', 'avif', 'jpg', 'png'], true)) {
    $convert_preferred_format = 'webp';
}
$auto_convert_on_upload = env_bool(
    'AUTO_CONVERT_ON_UPLOAD',
    env_bool('AUTO_CONVERT_WEBP_ON_UPLOAD', false) || env_bool('AUTO_CONVERT_AVIF_ON_UPLOAD', false)
);
define('AUTO_CONVERT_ON_UPLOAD', $auto_convert_on_upload);
define('AUTO_CONVERT_WEBP_ON_UPLOAD', $auto_convert_on_upload && $convert_preferred_format === 'webp');
define('AUTO_CONVERT_AVIF_ON_UPLOAD', $auto_convert_on_upload && $convert_preferred_format === 'avif');
define('CONVERT_PREFERRED_FORMAT', $convert_preferred_format);
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

// 图片请求统计（PHP 直读：开启后图片公网链接走 /i/<file>，每次成功
// 提供时累加 images.view_count；不依赖 Web 服务器日志）
define('IMAGE_VIEW_COUNTER_ENABLED', env_bool('IMAGE_VIEW_COUNTER_ENABLED', true));

// 图片公网链接前缀 — ImageUrl 拿这个前缀拼出公网 URL。
//   /uploads/   — 默认，跟磁盘路径完全一致，Web server 直接 serve（最快）
//   /           — 根目录直链 /2026/05/abc.webp（短，仍由 Web server serve）
//   /i/         — PHP 代理前缀 /i/2026/05/abc.webp（每次走 image.php，可统计 view_count）
//   /img/       — 用户自定义短前缀，等同 default 但 URL 看起来不像默认安装
//   /<任意名>/  — 用户随便起 — .htaccess 里的 catch-all rewrite 会把任何
//                 单词前缀 + /yyyy/mm/file 路径自动 serve 出 uploads/yyyy/mm/file
//
// 物理文件永远在 uploads/yyyy/mm/，URL 前缀只是显示层皮肤。切换前缀**绝对安全**：
// 老的 /uploads/... 链接和新的 /<前缀>/... 链接都能 serve 同一个文件。
$_url_prefix_raw = trim((string)env_value('URL_PREFIX', '/uploads/'));
// 规范化：必须以 / 开头和结尾，只接受小写字母数字 _ - ；非法回退默认
if (!preg_match('#^/([a-z0-9][a-z0-9_-]*/)?$#', $_url_prefix_raw)) {
    $_url_prefix_raw = '/uploads/';
}
define('URL_PREFIX', $_url_prefix_raw);

// 远程存储（S3 兼容协议，Cloudflare R2 / AWS S3 通用）
$remote_storage_usage = strtolower((string)env_value('REMOTE_STORAGE_USAGE', env_value('REMOTE_STORAGE_MODE', 'backup')));
if ($remote_storage_usage === 'sync') {
    $remote_storage_usage = 'backup';
}
if (!in_array($remote_storage_usage, ['backup', 'storage'], true)) {
    $remote_storage_usage = 'backup';
}
define('REMOTE_STORAGE_USAGE', $remote_storage_usage);
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

// CORS 配置 — 逗号分隔的允许跨域的域名列表（空 = 禁止跨域，* = 允许所有）
// 例: "https://app.example.com,https://admin.example.com"
define('CORS_ALLOWED_ORIGINS', env_csv('CORS_ALLOWED_ORIGINS', ['*']));

if (!function_exists('cors_origin')) {
    /**
     * Return the Access-Control-Allow-Origin header value for the current
     * request, or '' when the Origin is not in the allowlist.
     *
     * When CORS_ALLOWED_ORIGINS contains only '*', returns '*' (legacy
     * permissive behaviour). Otherwise checks the request Origin header
     * against the allowlist using a suffix match so "example.com" also
     * covers "https://example.com".
     */
    function cors_origin(): string {
        $allowed = CORS_ALLOWED_ORIGINS;
        if (in_array('*', $allowed, true)) {
            return '*';
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') return '';
        foreach ($allowed as $entry) {
            $entry = trim((string)$entry);
            if ($entry === '') continue;
            // 精确匹配或后缀匹配（*.example.com）
            if ($origin === $entry || str_ends_with($origin, '.' . ltrim($entry, '*.'))) {
                return $origin;
            }
        }
        return '';
    }
}

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

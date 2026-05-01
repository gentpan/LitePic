<?php
declare(strict_types=1);

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($uriPath) ? $uriPath : '/';
$normalizedPath = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');

if ($normalizedPath === '/api/v1' || str_starts_with($normalizedPath, '/api/v1/')) {
    require __DIR__ . '/api/v1.php';
    return true;
}

if ($normalizedPath === '/i' || str_starts_with($normalizedPath, '/i/')) {
    require __DIR__ . '/image.php';
    return true;
}

// Image URL prefix fallback — same as index.php branch, mirrored here for the
// PHP built-in dev server (`php -S`) which uses this router instead of going
// through Apache .htaccess. Catches /<prefix>/yyyy/mm/file → image.php.
if (preg_match('#^/(?!uploads/|i/|api/|static/|assets/|data/|logs/)([a-z0-9][a-z0-9_-]*/)?([0-9]{4}/[0-9]{2}/[^/]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|tif|heic|jxl|raw|dng))$#i', $normalizedPath, $m)) {
    $_GET['file'] = $m[2];
    require __DIR__ . '/image.php';
    return true;
}

// 无后缀页面路由，统一走单入口
// /docs 和 /api 都已迁移到 https://litepic.io（litepic-landing 静态站），
// 所以这里不再列入。/api/v1/* 是 REST API endpoint，由专门的 api/v1.php
// 处理，不归这里管。
$pageRoutes = [
    '/',
    '/upload',
    '/gallery',
    '/settings',
    '/stats',
];

if (in_array($normalizedPath, $pageRoutes, true)) {
    $_SERVER['PHP_SELF'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// /settings/<tab> 路径化 URL — 提取 tab segment 塞到 $_GET，复用 settings 主入口
if (preg_match('#^/settings/([a-z]+)/?$#', $normalizedPath, $m)) {
    $_GET['tab'] = $m[1];
    $_SERVER['PHP_SELF'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// 真实文件交给内置服务器（静态资源 / API / 上传文件）
// 安全：禁止暴露敏感文件
$blocked_patterns = ['/^\/\.env/i', '/^\/\.git/i', '/^\/data\//i', '/^\/logs\//i', '/\.log$/i', '/\.ini$/i'];
foreach ($blocked_patterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        echo '403 Forbidden';
        return true;
    }
}

$fullPath = __DIR__ . $path;
if ($path !== '/' && is_file($fullPath)) {
    return false;
}

http_response_code(404);
echo '404 Not Found';
return true;

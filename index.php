<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/app/Http/router.php';

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/upload'), PHP_URL_PATH);
$requestPath = is_string($uriPath) && $uriPath !== '' ? $uriPath : '/upload';
$normalizedPath = rtrim($requestPath, '/') === '' ? '/' : rtrim($requestPath, '/');

if ($normalizedPath === '/api/v1' || str_starts_with($normalizedPath, '/api/v1/')) {
    require __DIR__ . '/api/v1.php';
    exit;
}

if ($normalizedPath === '/i' || str_starts_with($normalizedPath, '/i/')) {
    require __DIR__ . '/image.php';
    exit;
}

/*
 * 历史 /uploads/<path> 链接的 301 重定向 ——
 * 当管理员把物理目录从 uploads 改成别的名字（如 files）后，已发布的旧
 * 链接还是 /uploads/2026/04/abc.jpg。Web 服务器找不到该静态文件后会
 * try_files 兜底到 index.php，到这里识别 + 301 跳到当前 STORAGE_DIR。
 * 缓存友好，对搜索引擎也透明。
 */
$_storageDir = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads';
if ($_storageDir !== 'uploads' && str_starts_with($requestPath, '/uploads/')) {
    $rest = substr($requestPath, strlen('/uploads/'));
    $newUrl = '/' . $_storageDir . '/' . $rest;
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') $newUrl .= '?' . $qs;
    header('Location: ' . $newUrl, true, 301);
    exit;
}
unset($_storageDir);

/*
 * Image URL prefix fallback — 让 Web 服务器支持自定义 URL 前缀。
 *
 * nginx try_files / FrankenPHP php_server 会把未命中静态文件的请求兜底到
 * index.php，到这里识别 `/<prefix>/yyyy/mm/file` 并 dispatch 给 image.php。
 *
 * 排除前缀：STORAGE_DIR（物理目录直连）、i（已上面拦截）、
 * api / static / assets / data / logs（框架路径）。
 */
$_storageDir = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads';
if (preg_match('#^/(?!' . preg_quote($_storageDir, '#') . '/|i/|api/|static/|assets/|data/|logs/)([a-z0-9][a-z0-9_-]*/)?([0-9]{4}/[0-9]{2}/[^/]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|tif|heic|heif|jxl|raw|dng))$#i', $requestPath, $m)) {
    $_GET['file'] = $m[2];
    require __DIR__ . '/image.php';
    exit;
}
unset($_storageDir);

$page = resolve_page_for_path($requestPath);
if ($page === null) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$pageFile = APP_ROOT . '/app/pages/' . $page . '.php';
if (!is_file($pageFile)) {
    http_response_code(500);
    echo 'Page file not found';
    exit;
}

// Dynamic pages (and their auth redirects) must never be cached at the CDN edge.
\LitePic\Core\HttpCache::preventPrivateCaching();

require $pageFile;

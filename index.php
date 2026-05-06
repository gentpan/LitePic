<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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
 * Image URL prefix fallback — 让所有 web server 都支持自定义 URL 前缀。
 *
 * .htaccess 里有 catch-all rewrite 把 `/<prefix>/yyyy/mm/file` 重写到
 * image.php — 但这只在 Apache 上有效。Nginx / Caddy / php -S 不读
 * .htaccess，请求会按 try_files 兜底落到 index.php，到这里被识别 +
 * dispatch 给 image.php。
 *
 * 排除前缀：uploads（直连）、i（已上面拦截）、api / static / assets /
 * data / logs（框架路径）。
 */
if (preg_match('#^/(?!uploads/|i/|api/|static/|assets/|data/|logs/)([a-z0-9][a-z0-9_-]*/)?([0-9]{4}/[0-9]{2}/[^/]+\.(?:jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|tif|heic|heif|jxl|raw|dng))$#i', $requestPath, $m)) {
    $_GET['file'] = $m[2];
    require __DIR__ . '/image.php';
    exit;
}

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

require $pageFile;

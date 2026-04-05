<?php
declare(strict_types=1);

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($uriPath) ? $uriPath : '/';

// 无后缀页面路由，统一走单入口
$pageRoutes = [
    '/',
    '/upload',
    '/gallery',
    '/docs',
    '/settings',
    '/stats',
];

if (in_array(rtrim($path, '/') === '' ? '/' : rtrim($path, '/'), $pageRoutes, true)) {
    $_SERVER['PHP_SELF'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// 真实文件交给内置服务器（静态资源 / API / 上传文件）
$fullPath = __DIR__ . $path;
if ($path !== '/' && is_file($fullPath)) {
    return false;
}

http_response_code(404);
echo '404 Not Found';
return true;

<?php
declare(strict_types=1);

require_once __DIR__ . '/app/core/bootstrap.php';
require_once __DIR__ . '/app/http/router.php';

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/upload'), PHP_URL_PATH);
$requestPath = is_string($uriPath) && $uriPath !== '' ? $uriPath : '/upload';

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


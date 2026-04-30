<?php
declare(strict_types=1);

/**
 * Versioned API dispatcher.
 *
 * Public routes:
 * - POST /api/v1          Upload images
 * - GET  /api/v1/list     List images
 * - GET  /api/v1/export   Export image list
 * - POST /api/v1/action   Admin image operations
 */

define('LITEPIC_API_V1_DISPATCH', true);

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH);
$path = is_string($uriPath) && $uriPath !== '' ? rtrim($uriPath, '/') : '/api/v1';
if ($path === '') {
    $path = '/api/v1';
}

$route = substr($path, strlen('/api/v1'));
$route = $route === false || $route === '' ? '/' : '/' . trim($route, '/');

switch ($route) {
    case '/':
        require __DIR__ . '/upload.php';
        break;

    case '/list':
        require __DIR__ . '/list.php';
        break;

    case '/export':
        require __DIR__ . '/export.php';
        break;

    case '/action':
        require dirname(__DIR__) . '/action.php';
        break;

    default:
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'API route not found',
        ], JSON_UNESCAPED_UNICODE);
        break;
}

<?php
declare(strict_types=1);

/**
 * Router for `php -S` local development only. Not deployed.
 *
 * Mirrors what Caddy's `php_server` try_files does: serve a real file straight
 * from disk, otherwise hand the request to the front controller so custom image
 * prefixes behave the same as in production.
 */

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = is_string($path) ? $path : '';
$identifier = '';

if (str_starts_with($path, '/i/')) {
    $identifier = substr($path, 3);
} else {
    $identifier = (string)($_GET['file'] ?? '');
}

serve_protected_image($identifier);

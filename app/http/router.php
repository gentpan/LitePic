<?php
declare(strict_types=1);

/**
 * LitePic V2.2 Router
 * 统一无后缀页面路由
 */
function resolve_page_for_path(string $path): ?string
{
    $normalized = rtrim($path, '/');
    if ($normalized === '') {
        $normalized = '/';
    }

    $routes = [
        '/' => 'home',
        '/upload' => 'upload',
        '/gallery' => 'gallery',
        '/docs'    => 'usage',
        '/api'     => 'api',
        '/settings' => 'settings',
        '/stats' => 'stats',
    ];

    return $routes[$normalized] ?? null;
}


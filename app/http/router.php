<?php
declare(strict_types=1);

/**
 * LitePic V2 Router
 * 统一无后缀页面路由
 */
function resolve_page_for_path(string $path): ?string
{
    $normalized = rtrim($path, '/');
    if ($normalized === '') {
        $normalized = '/';
    }

    $routes = [
        '/' => 'upload',
        '/upload' => 'upload',
        '/gallery' => 'gallery',
        '/docs' => 'docs',
        '/settings' => 'settings',
        '/stats' => 'stats',
    ];

    return $routes[$normalized] ?? null;
}


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

    // Note: /docs is intentionally NOT routed here. The usage docs were
    // migrated to https://litepic.io/docs (the litepic-landing static
    // site) so the in-app /docs link in the footer redirects there
    // instead — see footer.php. This keeps the PHP app focused on the
    // self-hosted dashboard surface and avoids shipping a copy of the
    // long-form docs in every install.
    // 注：/docs 和 /api 都已迁移到 litepic.io，本地不再路由。
    // /api/v1/* 是 REST API 入口，跟这里的 /api 文档页是两件事，
    // 由 .htaccess / nginx 直接转给 api/v1.php，不走这个路由表。
    $routes = [
        '/' => 'home',
        '/upload' => 'upload',
        '/gallery' => 'gallery',
        '/settings' => 'settings',
        '/stats' => 'stats',
    ];

    if (isset($routes[$normalized])) {
        return $routes[$normalized];
    }

    // 设置子页面路径化 URL — /settings/<tab> 走同一份 settings.php，
    // 把 tab segment 提取塞进 $_GET，让模板按平时的 ?tab= 逻辑工作。
    if (preg_match('#^/settings/([a-z]+)$#', $normalized, $m)) {
        $_GET['tab'] = $m[1];
        return 'settings';
    }

    return null;
}


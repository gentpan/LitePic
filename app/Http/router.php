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
        '/albums' => 'albums',
        '/albums/new' => 'album_edit',
    ];

    if (isset($routes[$normalized])) {
        return $routes[$normalized];
    }

    // 图库分页路径化 URL：/gallery/page/4。
    // 页码仍塞回 $_GET，复用 gallery.php 里原有分页逻辑。
    if (preg_match('#^/gallery/page/([1-9][0-9]*)$#', $normalized, $m)) {
        $_GET['page'] = $m[1];
        return 'gallery';
    }

    // 设置子页面路径化 URL — /settings/<tab> 走同一份 settings.php，
    // 把 tab segment 提取塞进 $_GET，让模板按平时的 ?tab= 逻辑工作。
    if (preg_match('#^/settings/([a-z]+)$#', $normalized, $m)) {
        $_GET['tab'] = $m[1];
        return 'settings';
    }

    // 相册编辑：/albums/<id>/edit。id 是数字 PK，不是 slug。
    if (preg_match('#^/albums/(\d+)/edit$#', $normalized, $m)) {
        $_GET['album_id'] = $m[1];
        return 'album_edit';
    }

    // 公开相册页：/a/<key>。<key> 可以是数字 id（默认，新建无 slug 时）
    // 或 slug 字符串（管理员手动设置后）。访客视图，按 visibility 决定
    // 200 / 密码门 / 404。
    if (preg_match('#^/a/(\d+|[a-z][a-z0-9-]{0,49})$#', $normalized, $m)) {
        $_GET['album_key'] = $m[1];
        return 'public_album';
    }

    return null;
}

<?php
declare(strict_types=1);

/**
 * Admin file browser — a Finder-style column view over the WebDAV tree.
 *
 * Deliberately thin: the shell, the toolbar and an empty column strip. Every
 * column, row and preview is built by the FileBrowser block in main.js from
 * `/api/v1/files`, because the tree is navigated far more often than the page
 * is loaded and re-rendering it server-side per click would mean a full
 * page load to open a folder.
 *
 * Available regardless of whether the WebDAV protocol endpoint is switched on:
 * `WEBDAV_ENABLED` decides who may reach `/dav` from outside, not whether an
 * admin may look at their own library. The header shows the endpoint's state so
 * the distinction stays visible.
 */

if (!defined('APP_ROOT')) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
}

if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    \LitePic\Core\HttpCache::redirect('/upload');
}

$page_title = '文件浏览';
$body_class = 'files-page';
$html_title = $page_title . ' ｜ ' . SITE_NAME;

// usable() rather than enabled(): a mount with no working credential answers
// nothing, so reporting it as "on" here would be a lie.
$fb_webdav_enabled = \LitePic\Service\WebDav\DavConfig::usable();
$fb_webdav_readonly = \LitePic\Service\WebDav\DavConfig::readOnly();

$fb_config = [
    'dirUnfiled' => \LitePic\Service\WebDav\DavPath::DIR_UNFILED,
];

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main files-main" data-pjax-container>
    <section class="page-shell files-shell">
        <div class="page-shell-header files-shell-header">
            <h2 class="page-shell-title">
                <i class="fa-light fa-folder-tree"></i>
                <span>文件浏览</span>
            </h2>
            <div class="files-shell-header-actions">
                <a href="/settings/webdav"
                   class="fb-dav-pill <?= $fb_webdav_enabled ? 'is-on' : 'is-off' ?>"
                   title="WebDAV 挂载设置" data-pjax>
                    <i class="fa-light fa-network-wired" aria-hidden="true"></i>
                    <span>WebDAV <?= $fb_webdav_enabled ? ($fb_webdav_readonly ? '只读' : '已开启') : '未开启' ?></span>
                </a>
                <button type="button" class="btn btn--secondary" data-fb-new-folder>
                    <i class="fa-light fa-folder-plus" aria-hidden="true"></i>
                    <span>新建文件夹</span>
                </button>
                <button type="button" class="btn btn--secondary btn--icon" data-fb-refresh title="刷新">
                    <i class="fa-light fa-arrows-rotate" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="fb-bar">
            <nav class="fb-crumbs" data-fb-crumbs aria-label="当前路径"></nav>
            <div class="fb-bar-actions" data-fb-selection hidden>
                <span class="fb-bar-count" data-fb-selection-count></span>
                <button type="button" class="btn btn--danger btn--sm" data-fb-delete>
                    <i class="fa-light fa-trash" aria-hidden="true"></i>
                    <span>删除</span>
                </button>
                <button type="button" class="btn btn--secondary btn--sm" data-fb-clear>取消选择</button>
            </div>
        </div>

        <div class="fb-body" data-fb-body>
            <div class="fb-columns" data-fb-columns>
                <div class="fb-loading">
                    <i class="fa-light fa-spinner-third fa-spin" aria-hidden="true"></i>
                    <span>正在读取目录…</span>
                </div>
            </div>
            <aside class="fb-preview" data-fb-preview hidden></aside>
        </div>

        <p class="fb-hint">
            <i class="fa-light fa-circle-info" aria-hidden="true"></i>
            这就是 WebDAV 客户端挂载后看到的目录树。拖拽图片到文件夹是<strong>移动</strong>，按住
            <kbd>Alt</kbd> 拖拽是<strong>复制</strong>（同一张图同时属于两个相册）。
            <span class="fb-hint-sep">·</span>
            删除文件：若该图只在这一处会真正删图；若还在其他相册（经复制链接），只取消这一处引用。
            删除文件夹只删相册，图片回到「<?= htmlspecialchars(\LitePic\Service\WebDav\DavPath::DIR_UNFILED) ?>」。
            <span class="fb-hint-sep">·</span>
            「<?= htmlspecialchars(\LitePic\Service\WebDav\DavPath::DIR_DATE) ?>」是只读视图。
        </p>
    </section>
</main>

<script>
    window.LITEPIC_FB = <?= json_encode($fb_config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php
require_once APP_ROOT . '/footer.php';

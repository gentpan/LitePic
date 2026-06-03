<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}

if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    header('Location: /upload');
    exit;
}

$page_title = '相册管理';
$body_class = 'albums-page';
$html_title = $page_title . ' ｜ ' . SITE_NAME;

$albums = (new \LitePic\Repository\AlbumRepository())->all();

// Visibility i18n + style
$visibilityLabels = [
    'public'   => '公开',
    'unlisted' => '不公开',
    'password' => '密码保护',
    'private'  => '仅自己',
];
$visibilityClasses = [
    'public'   => 'is-on',
    'unlisted' => 'is-warn',
    'password' => 'is-warn',
    'private'  => 'is-off',
];

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main" data-pjax-container>
    <section class="page-shell albums-shell">
        <div class="page-shell-header albums-shell-header">
            <h2 class="page-shell-title">
                <i class="fa-light fa-rectangle-history"></i>
                <span>相册管理</span>
                <small class="total-count">(共 <?= number_format(count($albums)) ?> 个相册)</small>
            </h2>
            <div class="albums-shell-header-actions">
                <a href="/albums/new" class="btn btn--primary" data-pjax>
                    <i class="fa-light fa-plus" aria-hidden="true"></i>
                    <span>新建相册</span>
                </a>
            </div>
        </div>
        <div class="page-shell-body">

            <?php if (empty($albums)): ?>
                <div class="albums-empty">
                    <i class="fa-light fa-rectangle-history" aria-hidden="true"></i>
                    <h3>暂无相册</h3>
                    <p>把图片分组到不同相册，方便整理和分享。一张图可以同时属于多个相册。</p>
                    <a href="/albums/new" class="btn btn--primary" data-pjax>
                        <i class="fa-light fa-plus" aria-hidden="true"></i>
                        <span>创建第一个相册</span>
                    </a>
                </div>
            <?php else: ?>

            <div class="albums-grid">
                <?php foreach ($albums as $album): ?>
                    <?php
                    $coverUrl = '';
                    // 优先显式封面,未设置则用相册第一张图(cover_effective)
                    $coverSrc = (string)($album['cover_effective'] ?? $album['cover_filename'] ?? '');
                    if ($coverSrc !== '') {
                        $coverUrl = \LitePic\Service\Image\ImageUrl::thumbnailUrl($coverSrc);
                    }
                    $vis = (string)$album['visibility'];
                    $visLabel = $visibilityLabels[$vis] ?? $vis;
                    $visClass = $visibilityClasses[$vis] ?? '';
                    ?>
                    <article class="album-card" data-album-id="<?= (int)$album['id'] ?>">
                        <a class="album-card-cover" href="/albums/<?= (int)$album['id'] ?>/edit" data-pjax aria-label="编辑相册 <?= htmlspecialchars((string)$album['name']) ?>">
                            <?php if ($coverUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($coverUrl) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="album-card-cover-empty">
                                    <i class="fa-light fa-image" aria-hidden="true"></i>
                                </span>
                            <?php endif; ?>
                            <span class="album-card-vis status-pill <?= htmlspecialchars($visClass) ?>"><?= htmlspecialchars($visLabel) ?></span>
                        </a>

                        <div class="album-card-body">
                            <a class="album-card-name" href="/albums/<?= (int)$album['id'] ?>/edit" data-pjax>
                                <?= htmlspecialchars((string)$album['name']) ?>
                            </a>
                            <?php if ((string)$album['description'] !== ''): ?>
                                <p class="album-card-desc"><?= htmlspecialchars((string)$album['description']) ?></p>
                            <?php endif; ?>

                            <?php $albumKey = \LitePic\Service\Album\AlbumService::urlKey($album); ?>
                            <div class="album-card-meta">
                                <span><i class="fa-light fa-images"></i> <?= number_format((int)$album['image_count']) ?> 张</span>
                                <span><i class="fa-light fa-eye"></i> <?= number_format((int)$album['view_count']) ?> 次访问</span>
                                <span><i class="fa-light fa-link"></i> <code>/a/<?= htmlspecialchars($albumKey) ?></code></span>
                            </div>

                            <div class="album-card-actions">
                                <a href="/albums/<?= (int)$album['id'] ?>/edit" class="btn btn--secondary btn--sm" data-pjax>
                                    <i class="fa-light fa-pen-to-square"></i>
                                    <span>编辑</span>
                                </a>
                                <button type="button" class="btn btn--danger btn--sm"
                                        data-album-delete
                                        data-album-id="<?= (int)$album['id'] ?>"
                                        data-album-key="<?= htmlspecialchars($albumKey) ?>"
                                        data-album-name="<?= htmlspecialchars((string)$album['name']) ?>">
                                    <i class="fa-light fa-trash"></i>
                                    <span>删除</span>
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>

        </div>
    </section>

<!-- 脚本须放在 [data-pjax-container] 内 —— PJAX 只重跑容器内 <script>;
     放在 </main> 外会导致经 PJAX 进入列表页时删除等操作不绑定。 -->
<script>
(function () {
    const root = document.querySelector('[data-pjax-container]');
    if (!root || root._albumsListInited) return;
    root._albumsListInited = true;

    const csrf = window.CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value || '';

    const performDelete = async (btn, key, name) => {
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.set('form_action', 'delete');
            fd.set('csrf_token', csrf);
            const res = await fetch(`/api/v1/albums/${encodeURIComponent(key)}`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || `请求失败 (${res.status})`);
            }
            btn.closest('.album-card')?.remove();
            window.ImgEt?.Utils?.showNotification?.('相册「' + name + '」已删除', 'success');
            // 没卡片了 → 刷新进入空状态
            if (root.querySelectorAll('.album-card').length === 0) {
                window.location.reload();
            }
        } catch (err) {
            console.error('Album delete error:', err);
            btn.disabled = false;
            window.ImgEt?.Utils?.showNotification?.(err.message || '删除失败', 'error');
        }
    };

    root.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-album-delete]');
        if (!btn) return;
        e.preventDefault();
        // URL key — 优先 slug,其次数字 ID(albumKey 是后端算好的)
        const key = btn.dataset.albumKey || btn.dataset.albumId;
        const name = btn.dataset.albumName || key;

        // 跟图片删除走同一套自定义 dialog(.confirm-dialog),不再用浏览器原生 confirm()
        const title = '删除相册';
        const message = `确认删除相册「${name}」吗？相册本身会消失，但相册内的图片仍保留在图库中(不会被删除)。`;

        if (window.ImgEt?.DialogManager?.showConfirmDialog) {
            ImgEt.DialogManager.showConfirmDialog(
                title,
                message,
                () => performDelete(btn, key, name),
                { danger: true, confirmText: '删除' }
            );
        } else if (confirm(message)) {
            // 退路:DialogManager 没加载就用浏览器原生
            performDelete(btn, key, name);
        }
    });
})();
</script>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

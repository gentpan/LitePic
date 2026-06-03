<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}

if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    header('Location: /upload');
    exit;
}

/*
 * Two modes:
 *   - "new"  → no album_id in $_GET. Renders the create form. Image picker
 *     is hidden until the album is saved (we need an id to attach images).
 *   - "edit" → album_id in $_GET. Renders the edit form pre-filled, plus
 *     the image management panel below it.
 */
$albumId = isset($_GET['album_id']) ? (int)$_GET['album_id'] : 0;
$isNew = $albumId <= 0;

$album = null;
$albumImages = [];
if (!$isNew) {
    $repo = new \LitePic\Repository\AlbumRepository();
    $album = $repo->find($albumId);
    if ($album === null) {
        http_response_code(404);
        $page_title = '相册不存在';
        $body_class = 'albums-page';
        require_once APP_ROOT . '/header.php';
        echo '<main class="page-container page-main"><section class="settings-callout"><strong>相册不存在</strong><p class="m-0">该相册可能已被删除。<a href="/albums" data-pjax>返回相册列表</a></p></section></main>';
        require_once APP_ROOT . '/footer.php';
        exit;
    }
    $albumImageRepo = new \LitePic\Repository\AlbumImageRepository();
    $info = new \LitePic\Service\Image\ImageInfo();
    $filenames = $albumImageRepo->listFilenames($albumId);
    // 一次 IN (...) 把所有图片元数据拉回来,避免 500 张图 = 500 次 SELECT。
    $info->preload($filenames);
    foreach ($filenames as $filename) {
        $meta = $info->getSafe($filename);
        if ($meta === null) continue; // orphan
        $albumImages[] = [
            'filename'  => $filename,
            'thumb_url' => (string)($meta['thumb_url'] ?? \LitePic\Service\Image\ImageUrl::forIdentifier($filename)),
            'size'      => $meta['size'] ?? 0,
        ];
    }
}

$page_title = $isNew ? '新建相册' : '编辑相册';
$body_class = 'albums-page';
$html_title = $page_title . ' ｜ ' . SITE_NAME;

require_once APP_ROOT . '/header.php';
?>

<?php
// 公共 URL key — slug 优先,留空时降级到数字 id。所有 fetch() 都用它做
// /api/v1/albums/<key> 的 path 段。新建页 id=0 时为空字符串,JS 自己判断。
$albumUrlKey = '';
if (!$isNew && !empty($album)) {
    $albumUrlKey = \LitePic\Service\Album\AlbumService::urlKey($album);
}
?>
<main class="page-container page-main" data-pjax-container
      data-album-mode="<?= $isNew ? 'new' : 'edit' ?>"
      data-album-id="<?= (int)($album['id'] ?? 0) ?>"
      data-album-slug="<?= htmlspecialchars((string)($album['slug'] ?? '')) ?>"
      data-album-key="<?= htmlspecialchars($albumUrlKey) ?>">
    <section class="page-shell albums-shell">
        <div class="page-shell-header albums-shell-header">
            <h2 class="page-shell-title">
                <i class="fa-light fa-rectangle-history"></i>
                <span><?= htmlspecialchars($page_title) ?></span>
                <?php if (!$isNew): ?>
                    <small class="total-count">
                        URL <code>/a/<?= htmlspecialchars($albumUrlKey) ?></code> ·
                        <?= number_format((int)$album['image_count']) ?> 张 ·
                        <?= number_format((int)$album['view_count']) ?> 次访问
                    </small>
                <?php endif; ?>
            </h2>
            <div class="albums-shell-header-actions">
                <a href="/albums" class="btn btn--secondary" data-pjax>
                    <i class="fa-light fa-arrow-left" aria-hidden="true"></i>
                    <span>返回列表</span>
                </a>
            </div>
        </div>
        <div class="page-shell-body">

    <!-- ============ 基本信息 ============ -->
    <section class="settings-grid" style="margin-top:0;">
        <form id="albumMetaForm" class="settings-grid" style="grid-column:1 / -1;display:grid;gap:14px;" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\LitePic\Core\Csrf::token()) ?>">

            <div class="grid gap-2">
                <label for="albumName">相册名称 <span style="color:#d73a49;">*</span></label>
                <input id="albumName" name="name" type="text" maxlength="80" required
                       placeholder="例：夏日合集"
                       value="<?= htmlspecialchars((string)($album['name'] ?? '')) ?>">
            </div>

            <div class="grid gap-2">
                <label for="albumSlug">URL slug <span class="text-gray" style="font-weight:400;font-size:0.78rem;">(可选)</span></label>
                <input id="albumSlug" name="slug" type="text" maxlength="50"
                       pattern="^[a-z][a-z0-9-]{0,49}$"
                       placeholder="留空使用数字 ID — 例 /a/<?= (int)($album['id'] ?? 0) ?: '3' ?>"
                       value="<?= htmlspecialchars((string)($album['slug'] ?? '')) ?>">
                <p class="settings-field-hint">
                    a-z 0-9 - ，字母开头。留空时公开 URL 默认用数字 ID。当前公开页 URL：<a href="/a/<?= htmlspecialchars($albumUrlKey ?: '') ?>" target="_blank" rel="noopener" class="album-share-link" data-slug-link><code>/a/<span data-slug-preview><?= htmlspecialchars($albumUrlKey ?: '<待生成>') ?></span></code><i class="fa-light fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                </p>
            </div>

            <div class="grid gap-2">
                <label for="albumDesc">简介</label>
                <textarea id="albumDesc" name="description" rows="3" maxlength="500"
                          placeholder="可选，会显示在公开相册页头部"><?= htmlspecialchars((string)($album['description'] ?? '')) ?></textarea>
            </div>

            <div class="grid gap-2">
                <label for="albumVisibility">可见性</label>
                <select id="albumVisibility" name="visibility">
                    <?php
                    $visOptions = [
                        'public'   => '公开 — 任何人可访问，可被搜索引擎收录',
                        'unlisted' => '不公开 — 凭链接访问，搜索引擎不收录',
                        'password' => '密码保护 — 访问前需输入密码',
                        'private'  => '仅自己 — 只有登录的管理员能看',
                    ];
                    $currentVis = (string)($album['visibility'] ?? 'public');
                    foreach ($visOptions as $val => $label):
                    ?>
                        <option value="<?= $val ?>" <?= $currentVis === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid gap-2" data-album-password-field <?= $currentVis === 'password' ? '' : 'hidden' ?>>
                <label for="albumPassword">密码</label>
                <input id="albumPassword" name="password" type="password" maxlength="80"
                       autocomplete="new-password"
                       placeholder="<?= !$isNew && ($album['password_hash'] ?? null) !== null ? '已设置（留空保持不变；输入新值会覆盖）' : '至少 4 位' ?>">
                <p class="settings-field-hint">仅在「密码保护」可见性下生效。可见性切到其它选项时密码会自动失效。</p>
            </div>

            <div class="settings-save-actions" style="margin-top:6px;">
                <button type="submit" class="btn btn--primary btn--lg">
                    <i class="fa-light fa-floppy-disk" aria-hidden="true"></i>
                    <span><?= $isNew ? '创建相册' : '保存修改' ?></span>
                </button>
            </div>
        </form>
    </section>

    <!-- ============ 图片管理（编辑模式才有） ============ -->
    <?php if (!$isNew): ?>
    <section style="margin-top:24px;">
        <div class="settings-section-header">
            <h3 class="settings-card-title">
                <i class="fa-light fa-images" aria-hidden="true"></i>
                <span>相册图片</span>
                <small style="font-weight:400;color:var(--gray);"><span data-album-image-count><?= count($albumImages) ?></span> 张</small>
            </h3>
            <p>从图库挑选图片加入相册，或点击图片右上角「移除」从相册中拿掉(不会删除原图)。</p>
        </div>

        <div class="settings-grid" style="grid-template-columns:1fr;display:grid;gap:14px;">

            <!-- 当前相册图片列表 -->
            <div>
                <h4 style="margin:0 0 8px;font-size:0.85rem;color:var(--gray);">相册内</h4>
                <div class="upload-grid" data-album-current-images>
                    <?php if (empty($albumImages)): ?>
                        <div class="recent-empty" data-album-empty-state>
                            <i class="fa-light fa-image" aria-hidden="true"></i>
                            <span>相册还是空的，从下方图库挑几张加进来</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($albumImages as $img): ?>
                            <figure class="img-box" data-album-image data-filename="<?= htmlspecialchars($img['filename']) ?>">
                                <img src="<?= htmlspecialchars($img['thumb_url']) ?>" alt="" loading="lazy">
                                <button type="button" class="btn btn--danger btn--sm"
                                        data-remove-from-album
                                        data-filename="<?= htmlspecialchars($img['filename']) ?>"
                                        style="position:absolute;top:6px;right:6px;font-size:0.72rem;padding:4px 8px;">
                                    <i class="fa-light fa-xmark" aria-hidden="true"></i>
                                    <span>移除</span>
                                </button>
                            </figure>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 图库挑选 -->
            <div>
                <h4 style="margin:24px 0 8px;font-size:0.85rem;color:var(--gray);">从图库挑选</h4>
                <div class="settings-inline-form" style="margin-bottom:10px;">
                    <button type="button" class="btn btn--primary" data-album-add-selected disabled>
                        <i class="fa-light fa-plus" aria-hidden="true"></i>
                        <span>添加选中 (<span data-selected-count>0</span>)</span>
                    </button>
                    <button type="button" class="btn btn--secondary" data-album-clear-selection>清空选择</button>
                </div>
                <div class="upload-grid" data-album-library-picker>
                    <div class="recent-empty" data-album-library-loading>
                        <i class="fa-light fa-spinner fa-spin" aria-hidden="true"></i>
                        <span>载入图库中...</span>
                    </div>
                </div>
                <div style="margin-top:10px;text-align:center;">
                    <button type="button" class="btn btn--secondary" data-album-load-more hidden>
                        <span>加载更多</span>
                    </button>
                </div>
            </div>

        </div>
    </section>
    <?php endif; ?>

        </div><!-- /.page-shell-body -->
    </section><!-- /.page-shell -->

<!-- 脚本必须放在 [data-pjax-container] 内部 —— PJAX 只重跑容器内的 <script>;
     放到 </main> 外面会导致经 PJAX 进入本页时不执行,表单 submit 不绑定,
     退回原生 GET 提交(创建相册失效)。 -->
<script>
(function () {
    // 重构后 .albums-shell 是 <section>,<main> 只挂 [data-pjax-container]。
    // 选 <main> 做 root,所有子查询(visSelect / passwordField / picker 等)
    // 仍是它的 descendant — 不影响其它逻辑。
    const root = document.querySelector('main[data-pjax-container]');
    if (!root || !root.querySelector('.albums-shell')) return;
    if (root._albumEditInited) return;
    root._albumEditInited = true;

    const csrf = root.querySelector('input[name="csrf_token"]')?.value
              || window.CSRF_TOKEN || '';
    const isNew = root.dataset.albumMode === 'new';
    const albumId = parseInt(root.dataset.albumId || '0', 10);
    let albumSlug = root.dataset.albumSlug || '';
    // The URL key sent to /api/v1/albums/<key>/... — slug if set, else id.
    // Editing the slug rebuilds it; never edit albumKey directly elsewhere.
    let albumKey = root.dataset.albumKey || (albumSlug || (albumId ? String(albumId) : ''));

    const form = root.querySelector('#albumMetaForm');
    const visSelect = root.querySelector('#albumVisibility');
    const passwordField = root.querySelector('[data-album-password-field]');

    // ------ 可见性变化 → 显示/隐藏密码字段 ------
    visSelect?.addEventListener('change', () => {
        if (passwordField) {
            passwordField.hidden = visSelect.value !== 'password';
        }
    });

    // ------ slug 实时预览 ------
    // 用户在 slug 输入框打字时,公开页 URL 实时切换:
    //   有内容(合法 slug) → /a/<slug>
    //   留空              → /a/<id>(降级到数字 ID)
    const slugInput = root.querySelector('#albumSlug');
    const slugPreview = root.querySelector('[data-slug-preview]');
    const slugLink = root.querySelector('[data-slug-link]');
    const refreshSlugPreview = () => {
        if (!slugPreview) return;
        const typed = (slugInput?.value || '').trim();
        // 默认降级:有 id 用 id,否则空状态(新建未保存)
        const fallback = albumId ? String(albumId) : '<待生成>';
        const v = typed || fallback;
        slugPreview.textContent = v;
        if (slugLink) {
            // 只有 slug 形态或纯数字才是合法的公开 URL
            if (/^[a-z][a-z0-9-]{0,49}$|^\d+$/.test(v)) {
                slugLink.setAttribute('href', '/a/' + v);
            }
        }
    };
    slugInput?.addEventListener('input', refreshSlugPreview);

    // ------ 提交表单 ------
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const payload = {};
        for (const [k, v] of fd.entries()) {
            if (k === 'csrf_token') continue;
            // password 留空时,新建相册的时候明确不发(让后端用 null);编辑模式
            // 留空表示"保持不变"。
            if (k === 'password' && v === '' && !isNew) continue;
            payload[k] = v;
        }

        const url = isNew
            ? '/api/v1/albums'
            : `/api/v1/albums/${encodeURIComponent(albumKey)}`;
        if (!isNew) payload.form_action = 'update';

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || `请求失败 (${res.status})`);
            }
            window.ImgEt?.Utils?.showNotification?.(isNew ? '相册已创建' : '已保存', 'success');
            if (isNew) {
                // 跳到编辑页继续加图片
                setTimeout(() => {
                    window.location.href = `/albums/${data.album.id}/edit`;
                }, 400);
            } else {
                // 更新本地 slug + URL key —— 用户改了 slug 后下一次 fetch 用新 key
                const newSlug = data.album?.slug ?? null;
                albumSlug = newSlug || '';
                root.dataset.albumSlug = albumSlug;
                albumKey = albumSlug || String(albumId);
                root.dataset.albumKey = albumKey;
                // /albums/<id>/edit 路由本来就用数字 ID,不需要改地址栏
            }
        } catch (err) {
            console.error('Album save error:', err);
            window.ImgEt?.Utils?.showNotification?.(err.message || '保存失败', 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    // ------ 编辑模式：图片管理 ------
    if (isNew) return;

    const currentBox = root.querySelector('[data-album-current-images]');
    const pickerBox = root.querySelector('[data-album-library-picker]');
    const loadMoreBtn = root.querySelector('[data-album-load-more]');
    const addSelectedBtn = root.querySelector('[data-album-add-selected]');
    const clearSelBtn = root.querySelector('[data-album-clear-selection]');
    const selCountSpan = root.querySelector('[data-selected-count]');
    const imageCountSpan = root.querySelector('[data-album-image-count]');

    let pickerOffset = 0;
    const pickerPageSize = 30;
    let selected = new Set();
    const inAlbum = new Set(
        Array.from(currentBox?.querySelectorAll('[data-album-image]') || [])
             .map(el => el.dataset.filename)
    );

    const setSelectedCount = () => {
        if (selCountSpan) selCountSpan.textContent = String(selected.size);
        if (addSelectedBtn) addSelectedBtn.disabled = selected.size === 0;
    };
    const setImageCount = (n) => { if (imageCountSpan) imageCountSpan.textContent = String(n); };

    // ------ 移除图片(点 X) ------
    currentBox?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-remove-from-album]');
        if (!btn) return;
        const filename = btn.dataset.filename;
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.set('form_action', 'remove');
            fd.set('csrf_token', csrf);
            fd.set('filenames[]', filename);
            const res = await fetch(`/api/v1/albums/${encodeURIComponent(albumKey)}/images`, {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'success') throw new Error(data.message || `请求失败 (${res.status})`);
            btn.closest('[data-album-image]')?.remove();
            inAlbum.delete(filename);
            setImageCount(data.image_count ?? 0);
            // 如果没图片了,显示空状态
            if (currentBox.querySelectorAll('[data-album-image]').length === 0) {
                currentBox.innerHTML = `
                    <div class="recent-empty" data-album-empty-state>
                        <i class="fa-light fa-image"></i>
                        <span>相册还是空的，从下方图库挑几张加进来</span>
                    </div>`;
            }
            // 让图库 picker 里这张图重新可选
            const card = pickerBox?.querySelector(`[data-pick-filename="${CSS.escape(filename)}"]`);
            if (card) card.classList.remove('is-in-album');
        } catch (err) {
            console.error(err);
            btn.disabled = false;
            window.ImgEt?.Utils?.showNotification?.(err.message || '移除失败', 'error');
        }
    });

    // ------ 加载图库 ------
    async function loadLibraryPage() {
        try {
            const res = await fetch(`/api/v1/list?page=${Math.floor(pickerOffset / pickerPageSize) + 1}&per_page=${pickerPageSize}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            // /api/v1/list 返回 {status, data:{items:[...], pagination:{...}}} —— 列表在
            // data.data.items;保留旧的 data.images / data.data 兜底以防别处复用。
            const list = Array.isArray(data?.data?.items) ? data.data.items
                       : (Array.isArray(data?.images) ? data.images
                       : (Array.isArray(data?.data) ? data.data : []));
            // 去掉初次的 loading 占位
            if (pickerOffset === 0) pickerBox.innerHTML = '';
            for (const img of list) {
                const filename = img.filename || img.name || '';
                if (!filename) continue;
                const isMember = inAlbum.has(filename);
                const card = document.createElement('figure');
                card.className = 'img-box';
                card.dataset.pickFilename = filename;
                if (isMember) card.classList.add('is-in-album');
                card.innerHTML = `
                    <img src="${img.thumb_url || img.url || ''}" alt="" loading="lazy">
                    <div class="img-overlay" style="display:flex;align-items:center;justify-content:center;">
                        <button type="button" class="btn btn--secondary btn--sm" data-pick-toggle
                                style="position:absolute;inset:auto;${isMember ? 'background:#22c55e;color:#fff;border-color:#22c55e;' : ''}">
                            <i class="fa-light ${isMember ? 'fa-check' : 'fa-plus'}"></i>
                            <span>${isMember ? '已在相册' : '选中'}</span>
                        </button>
                    </div>`;
                pickerBox.appendChild(card);
            }
            pickerOffset += list.length;
            if (loadMoreBtn) {
                loadMoreBtn.hidden = list.length < pickerPageSize;
            }
        } catch (err) {
            console.error('library load error', err);
            if (pickerOffset === 0) {
                pickerBox.innerHTML = '<div class="recent-empty"><i class="fa-light fa-circle-exclamation"></i><span>图库加载失败</span></div>';
            }
        }
    }
    loadLibraryPage();
    loadMoreBtn?.addEventListener('click', () => loadLibraryPage());

    // ------ 选中切换 ------
    pickerBox?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-pick-toggle]');
        if (!btn) return;
        const card = btn.closest('[data-pick-filename]');
        if (!card) return;
        if (card.classList.contains('is-in-album')) return; // 已在相册不可点
        const filename = card.dataset.pickFilename;
        if (selected.has(filename)) {
            selected.delete(filename);
            card.classList.remove('is-selected');
            btn.style.background = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            btn.querySelector('i').className = 'fa-light fa-plus';
            btn.querySelector('span').textContent = '选中';
        } else {
            selected.add(filename);
            card.classList.add('is-selected');
            btn.style.background = 'var(--primary)';
            btn.style.color = '#fff';
            btn.style.borderColor = 'var(--primary)';
            btn.querySelector('i').className = 'fa-light fa-check';
            btn.querySelector('span').textContent = '已选';
        }
        setSelectedCount();
    });

    clearSelBtn?.addEventListener('click', () => {
        selected.forEach(f => {
            const c = pickerBox?.querySelector(`[data-pick-filename="${CSS.escape(f)}"]`);
            c?.classList.remove('is-selected');
            const btn = c?.querySelector('[data-pick-toggle]');
            if (btn) {
                btn.style.background = '';
                btn.style.color = '';
                btn.style.borderColor = '';
                btn.querySelector('i').className = 'fa-light fa-plus';
                btn.querySelector('span').textContent = '选中';
            }
        });
        selected.clear();
        setSelectedCount();
    });

    // ------ 添加选中 ------
    addSelectedBtn?.addEventListener('click', async () => {
        if (selected.size === 0) return;
        const filenames = Array.from(selected);
        addSelectedBtn.disabled = true;
        try {
            const fd = new FormData();
            fd.set('form_action', 'add');
            fd.set('csrf_token', csrf);
            for (const f of filenames) fd.append('filenames[]', f);
            const res = await fetch(`/api/v1/albums/${encodeURIComponent(albumKey)}/images`, {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'success') throw new Error(data.message || `请求失败 (${res.status})`);

            // 刷一下相册区(简单做法:reload)
            window.ImgEt?.Utils?.showNotification?.(`已添加 ${data.added} 张`, 'success');
            window.location.reload();
        } catch (err) {
            console.error(err);
            addSelectedBtn.disabled = false;
            window.ImgEt?.Utils?.showNotification?.(err.message || '添加失败', 'error');
        }
    });

})();
</script>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

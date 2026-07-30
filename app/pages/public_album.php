<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
}

/*
 * Public album view — /a/<key>
 *
 * <key> may be either:
 *   - a numeric id (default; new albums without a custom slug)
 *   - a slug string (admin-set, lower-case + dashes)
 *
 * 4 visibility tiers:
 *   public    任何人可访问,可被搜索引擎收录
 *   unlisted  凭链接访问,搜索引擎不收录(robots noindex)
 *   password  访问前需密码,bcrypt 校验,1 小时签名 cookie
 *   private   仅管理员或相册所有者,其他人 404
 *
 * Visit counter: every successful render bumps albums.view_count once
 * (refresh = +1; no cookie / IP throttle — matches product requirement).
 *
 * Password rate limit: reuses LoginAttemptRepository keyed by IP — 5 wrong
 * passwords in 5 min → 15-min lockout (matches admin login policy).
 */

$albumKey = trim((string)($_GET['album_key'] ?? $_GET['album_slug'] ?? ''));
// Router already constrained the regex to digits OR slug shape; this is a
// belt-and-braces sanity check for direct includes / tests.
if ($albumKey === '' || !preg_match('/^(\d+|[a-z][a-z0-9-]{0,49})$/', $albumKey)) {
    http_response_code(404);
    echo '相册不存在';
    exit;
}

$albumRepo = new \LitePic\Repository\AlbumRepository();
$album = $albumRepo->findByKey($albumKey);
// Stable per-album cookie/PRG key — slug if set, else id.
// We don't use $albumKey directly because /a/3 and /a/my-slug for the
// same album would otherwise produce different cookies.
$cookieKey = \LitePic\Service\Album\AlbumService::urlKey($album ?? ['id' => 0, 'slug' => null]);

$isAdmin = \LitePic\Service\Auth\UserContext::isAdmin();
$isOwner = $album !== null
    && \LitePic\Service\Auth\UserContext::enabled()
    && \LitePic\Service\Auth\UserContext::currentUserId() > 0
    && (int)($album['user_id'] ?? 0) === \LitePic\Service\Auth\UserContext::currentUserId();
$canBypassGates = $isAdmin || $isOwner;

// 不存在 / private 非管理员且非所有者 → 一律 404(不暴露相册存在与否)
if ($album === null || ((string)$album['visibility'] === 'private' && !$canBypassGates)) {
    http_response_code(404);
    require_once APP_ROOT . '/header.php';
    ?>
    <main class="page-container page-main">
        <section class="page-shell albums-shell">
            <div class="page-shell-body">
                <div class="albums-empty">
                    <i class="fa-light fa-rectangle-history" aria-hidden="true"></i>
                    <h3>相册不存在</h3>
                    <p>相册可能已被删除,或链接错误。</p>
                    <a href="/" class="btn btn--secondary">返回首页</a>
                </div>
            </div>
        </section>
    </main>
    <?php
    require_once APP_ROOT . '/footer.php';
    exit;
}

// ============== 密码门 ==============
$visibility = (string)$album['visibility'];
$service = new \LitePic\Service\Album\AlbumService();
$albumCookieName = 'lp_album_' . $cookieKey;
$cookieSecret = (string)\LitePic\Core\Config::get('ADMIN_SESSION_SECRET', '');

$passwordPassed = false;
if ($visibility !== 'password' || $canBypassGates) {
    $passwordPassed = true;
} else {
    // 验证已有 cookie(签名 + 1h 有效期)
    $existing = (string)($_COOKIE[$albumCookieName] ?? '');
    if ($existing !== '' && $cookieSecret !== '') {
        [$expBlob, $sig] = array_pad(explode('.', $existing, 2), 2, '');
        if ($sig !== ''
            && hash_equals(hash_hmac('sha256', $cookieKey . ':' . $expBlob, $cookieSecret), $sig)
            && ctype_digit($expBlob) && (int)$expBlob > time()
        ) {
            $passwordPassed = true;
        }
    }

    // 表单提交
    $passwordError = '';
    if (!$passwordPassed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $loginRepo = new \LitePic\Repository\LoginAttemptRepository();
        if (!$loginRepo->isAllowedForCurrentIp()) {
            $passwordError = '尝试过于频繁,请稍后再试';
        } else {
            $candidate = (string)($_POST['album_password'] ?? '');
            if ($candidate !== '' && $service->verifyPassword($album, $candidate)) {
                // 签发 1h 有效期 cookie
                $exp = time() + 3600;
                $sig = hash_hmac('sha256', $cookieKey . ':' . (string)$exp, $cookieSecret);
                setcookie(
                    $albumCookieName,
                    (string)$exp . '.' . $sig,
                    [
                        'expires'  => $exp,
                        'path'     => '/',
                        'secure'   => \LitePic\Core\RequestContext::isHttps(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]
                );
                // PRG 防刷新重提 — 跳回访客实际访问的 URL key（保留 /a/3 vs /a/slug 选择）
                \LitePic\Core\HttpCache::redirect('/a/' . rawurlencode($albumKey));
            }
            $loginRepo->recordFailureForCurrentIp();
            $passwordError = $candidate === '' ? '请输入密码' : '密码错误';
        }
    }
}

// 渲染密码门
if (!$passwordPassed) {
    $page_title = $album['name'] . ' · 密码保护';
    $body_class = 'public-album-page public-album-locked';
    // noindex — 密码相册不应被搜索引擎收录
    $extra_head = '<meta name="robots" content="noindex, nofollow">';
    require_once APP_ROOT . '/header.php';
    ?>
    <main class="page-container page-main">
        <section class="page-shell public-album-gate">
            <div class="page-shell-body">
                <div class="public-album-gate-card">
                    <i class="fa-light fa-lock public-album-gate-icon" aria-hidden="true"></i>
                    <h2><?= htmlspecialchars((string)$album['name']) ?></h2>
                    <p class="text-gray">此相册受密码保护,请输入访问密码继续。</p>
                    <form method="post" class="public-album-gate-form" autocomplete="off">
                        <input type="password" name="album_password" placeholder="访问密码" autofocus required>
                        <button type="submit" class="btn btn--primary">进入相册</button>
                    </form>
                    <?php if (!empty($passwordError)): ?>
                        <p class="public-album-gate-error">
                            <i class="fa-light fa-circle-exclamation"></i>
                            <span><?= htmlspecialchars($passwordError) ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
    <?php
    require_once APP_ROOT . '/footer.php';
    exit;
}

// ============== 访问计数 ==============
// 每次成功渲染 +1（含翻页 / 刷新）。防刷曾用 cookie + album_visit_log，
// 现按产品要求改为「刷新即计」。
try {
    $albumRepo->incrementViewCount((int)$album['id']);
    $album['view_count']++;
} catch (\Throwable $_) { /* best-effort */ }

// ============== 主视图 ==============
$albumImageRepo = new \LitePic\Repository\AlbumImageRepository();
$info = new \LitePic\Service\Image\ImageInfo();

$filenames = $albumImageRepo->listFilenames((int)$album['id']);

// ============== 分页 ==============
// 默认每页 12 张 —— 大相册一次性铺 500 张缩略图会拖垮首屏。
// ?n= 每页张数(白名单)；行列按约 4:3 铺满视口（12=4×3 / 24=6×4 / 48=8×6）。
$perPageOptions = [12, 24, 48];
$perPageDefault = $perPageOptions[0];
$perPageRaw = isset($_GET['n']) ? (int)$_GET['n'] : 0;
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : $perPageDefault;

$totalImages = count($filenames);
$totalPages = max(1, (int)ceil($totalImages / $perPage));
$currentPage = min(max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1), $totalPages);
$pageFilenames = array_slice($filenames, ($currentPage - 1) * $perPage, $perPage);

/** 保留分页参数的相册 URL；默认值省略,保持 /a/key 干净 */
$paUrl = static function (int $page, int $per) use ($albumKey, $perPageDefault): string {
    $params = [];
    if ($page > 1) $params['p'] = $page;
    if ($per !== $perPageDefault) $params['n'] = $per;
    return '/a/' . rawurlencode($albumKey) . ($params ? '?' . http_build_query($params) : '');
};

// 一次性 batch-load 当前页的 DB 行 — 之前每张图都要单独 SELECT,500 张相册
// 等于 500 次往返。preload 用一条 IN (...) 把它们全拉回来塞进 ImageInfo 的
// 进程内缓存,后续 getSafe() 都是命中缓存。
$info->preload($pageFilenames);

$images = [];
foreach ($pageFilenames as $filename) {
    $meta = $info->getSafe($filename);
    if ($meta === null) continue; // 跳过孤儿
    $title = (string)($meta['original_name'] ?? '');
    // 隐藏纯哈希文件名当标题(没意义),只在用户重命名过时才展示
    if ($title !== '' && preg_match('/^[0-9a-f]{16,}$/i', pathinfo($title, PATHINFO_FILENAME))) {
        $title = '';
    }
    $description = trim((string)($meta['description'] ?? ''));
    $caption = $description !== '' ? $description : $title;
    $takenAt = isset($meta['exif_taken_at']) ? (int)$meta['exif_taken_at'] : 0;
    $dateTs = $takenAt > 0 ? $takenAt : (int)($meta['time'] ?? 0);
    $lat = $meta['exif_lat'] ?? null;
    $lng = $meta['exif_lng'] ?? null;
    $hasGps = is_numeric($lat) && is_numeric($lng);
    $images[] = [
        'filename'   => $filename,
        'url'        => \LitePic\Service\Image\ImageUrl::forIdentifier($filename),
        'thumb_url'  => (string)($meta['thumb_url'] ?? \LitePic\Service\Image\ImageUrl::forIdentifier($filename)),
        'dimensions' => (string)($meta['dimensions'] ?? ''),
        'width'      => (int)($meta['width'] ?? 0),
        'height'     => (int)($meta['height'] ?? 0),
        'size'       => (int)($meta['size'] ?? 0),
        'views'      => (int)($meta['request_count'] ?? 0),
        'title'      => $caption,
        'date'       => $dateTs > 0 ? date('Y-m-d H:i', $dateTs) : '',
        'map_url'    => $hasGps
            ? ('https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat)
                . '&mlon=' . rawurlencode((string)$lng)
                . '#map=15/' . rawurlencode((string)$lat) . '/' . rawurlencode((string)$lng))
            : '',
    ];
}

$coverUrl = '';
// 优先显式封面,未设置则用相册第一张图(cover_effective)
$coverSrc = (string)($album['cover_effective'] ?? $album['cover_filename'] ?? '');
if ($coverSrc !== '') {
    $coverUrl = \LitePic\Service\Image\ImageUrl::thumbnailUrl($coverSrc);
}

$page_title = $album['name'];
$albumTheme = (string)($album['theme'] ?? 'grid');
if (!in_array($albumTheme, ['grid', 'masonry'], true)) {
    $albumTheme = 'grid';
}
$body_class = 'public-album-page pa-theme-' . $albumTheme;
$html_title = $album['name'] . ' ｜ ' . SITE_NAME;

// SEO 控制
$indexable = $visibility === 'public';
$extra_head = $indexable ? '' : '<meta name="robots" content="noindex, nofollow">';

// Visibility badge labels
$visBadge = match ($visibility) {
    'public'   => ['公开', 'is-on'],
    'unlisted' => ['不公开', 'is-warn'],
    'password' => ['密码保护', 'is-warn'],
    'private'  => ['仅自己', 'is-off'],
    default    => [$visibility, ''],
};

require_once APP_ROOT . '/header.php';
?>

<main class="pa-standalone">
    <article class="pa-album" data-album-slug="<?= htmlspecialchars((string)($album['slug'] ?? '')) ?>" data-album-id="<?= (int)$album['id'] ?>" data-album-key="<?= htmlspecialchars($cookieKey) ?>">
        <?php if (empty($images)): ?>
            <div class="pa-empty">
                <i class="fa-light fa-image" aria-hidden="true"></i>
                <p>这个相册还没有图片</p>
            </div>
        <?php else: ?>
            <div class="pa-grid<?= $albumTheme === 'masonry' ? ' is-masonry' : '' ?>" data-pa-grid data-n="<?= (int)$perPage ?>" data-theme="<?= htmlspecialchars($albumTheme) ?>">
                <?php foreach ($images as $i => $img): ?>
                    <?php
                        $w = (int)$img['width'];
                        $h = (int)$img['height'];
                        $ratio = ($h > 0) ? ($w / $h) : 1.0;
                        $mosaic = 'md';
                        if ($albumTheme === 'masonry') {
                            // sabi 式马赛克：按比例 + 节奏位，故意做出有大有小
                            $cycle = $i % 9;
                            if ($cycle === 0) {
                                $mosaic = $ratio >= 1 ? 'xl' : 'lg';
                            } elseif ($cycle === 4) {
                                $mosaic = 'wide';
                            } elseif ($ratio < 0.82) {
                                $mosaic = 'tall';
                            } elseif ($ratio > 1.55) {
                                $mosaic = ($cycle % 2 === 0) ? 'wide' : 'md';
                            } elseif ($cycle === 2 || $cycle === 7) {
                                $mosaic = 'sm';
                            } else {
                                $mosaic = 'md';
                            }
                        }
                        $imgAttrs = ($w > 0 && $h > 0) ? (' width="' . $w . '" height="' . $h . '"') : '';
                    ?>
                    <figure class="pa-tile" data-pa-index="<?= (int)$i ?>"
                            data-filename="<?= htmlspecialchars($img['filename']) ?>"
                            data-full="<?= htmlspecialchars($img['url']) ?>"
                            data-title="<?= htmlspecialchars($img['title']) ?>"
                            data-date="<?= htmlspecialchars($img['date']) ?>"
                            data-map="<?= htmlspecialchars($img['map_url']) ?>"
                            data-views="<?= (int)$img['views'] ?>"
                            <?php if ($albumTheme === 'masonry'): ?>data-mosaic="<?= htmlspecialchars($mosaic) ?>"<?php endif; ?>>
                        <img src="<?= htmlspecialchars($img['thumb_url']) ?>"
                             alt="<?= htmlspecialchars($img['title']) ?>"
                             loading="lazy"
                             decoding="async"<?= $imgAttrs ?>>
                        <span class="pa-tile-views" title="浏览量">
                            <i class="fa-light fa-eye" aria-hidden="true"></i>
                            <span data-pa-views><?= number_format((int)$img['views']) ?></span>
                        </span>
                        <?php if ($img['title'] !== ''): ?>
                            <figcaption class="pa-tile-cap"><?= htmlspecialchars($img['title']) ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="pa-foot-hotzone" aria-hidden="true"></div>
        <footer class="pa-foot">
            <div class="pa-foot-left">
                <span class="pa-foot-name"><?= htmlspecialchars((string)$album['name']) ?></span>
                <?php /* 计数用实际图片数,而不是可能漂移的 image_count */ ?>
                <span class="pa-foot-meta">
                    <span><?= number_format($totalImages) ?> 张</span>
                    <span title="创建时间 <?= htmlspecialchars(date('Y-m-d H:i', (int)$album['created_at'])) ?>">
                        <i class="fa-light fa-calendar-plus" aria-hidden="true"></i><?= htmlspecialchars(date('Y-m-d', (int)$album['created_at'])) ?>
                    </span>
                    <span title="最后更新 <?= htmlspecialchars(date('Y-m-d H:i', (int)$album['updated_at'])) ?>">
                        <i class="fa-light fa-clock-rotate-left" aria-hidden="true"></i><?= htmlspecialchars(date('Y-m-d', (int)$album['updated_at'])) ?>
                    </span>
                    <span title="浏览量">
                        <i class="fa-light fa-eye" aria-hidden="true"></i><?= number_format((int)$album['view_count']) ?>
                    </span>
                </span>
            </div>
            <?php if ($totalImages > 0): ?>
                <div class="pa-foot-mid">
                    <div class="pa-pager">
                        <?php if ($currentPage > 1): ?>
                            <a class="pa-pager-btn" href="<?= htmlspecialchars($paUrl($currentPage - 1, $perPage)) ?>" rel="prev" title="上一页" aria-label="上一页">
                                <i class="fa-light fa-angle-left" aria-hidden="true"></i>
                            </a>
                        <?php else: ?>
                            <span class="pa-pager-btn is-disabled" aria-hidden="true"><i class="fa-light fa-angle-left"></i></span>
                        <?php endif; ?>

                        <span class="pa-pager-pos"><?= $currentPage ?> / <?= $totalPages ?></span>

                        <?php if ($currentPage < $totalPages): ?>
                            <a class="pa-pager-btn" href="<?= htmlspecialchars($paUrl($currentPage + 1, $perPage)) ?>" rel="next" title="下一页" aria-label="下一页">
                                <i class="fa-light fa-angle-right" aria-hidden="true"></i>
                            </a>
                        <?php else: ?>
                            <span class="pa-pager-btn is-disabled" aria-hidden="true"><i class="fa-light fa-angle-right"></i></span>
                        <?php endif; ?>
                    </div>

                    <div class="pa-perpage" role="group" aria-label="每页张数">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <?php if ($opt === $perPage): ?>
                                <span class="pa-perpage-opt is-active" aria-current="true"><?= $opt ?></span>
                            <?php else: ?>
                                <?php // 换每页张数后停留在原来那张图所在的新页码
                                $keepPage = (int)floor((($currentPage - 1) * $perPage) / $opt) + 1; ?>
                                <a class="pa-perpage-opt" href="<?= htmlspecialchars($paUrl($keepPage, $opt)) ?>" title="每页 <?= $opt ?> 张"><?= $opt ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="pa-foot-right">
                <button type="button" class="pa-pager-btn" data-pa-fullscreen title="全屏浏览" aria-label="全屏浏览">
                    <i class="fa-light fa-expand" aria-hidden="true"></i>
                </button>
                <a href="https://litepic.io" target="_blank" rel="noopener noreferrer">由 LitePic 驱动</a>
            </div>
        </footer>
    </article>
</main>

<!-- 灯箱:大图 + 标题/日期 + 左右翻页 + 关闭,模糊深色背景 -->
<div class="pa-lb" data-pa-lb hidden>
    <button type="button" class="pa-lb-close" data-pa-close aria-label="关闭"><i class="fa-light fa-xmark"></i></button>
    <button type="button" class="pa-lb-nav pa-lb-prev" data-pa-prev aria-label="上一张"><i class="fa-light fa-angle-left"></i></button>
    <button type="button" class="pa-lb-nav pa-lb-next" data-pa-next aria-label="下一张"><i class="fa-light fa-angle-right"></i></button>
    <span class="pa-lb-spinner" data-pa-spinner aria-hidden="true"></span>
    <figure class="pa-lb-stage">
        <img class="pa-lb-img" alt="" data-pa-img>
        <figcaption class="pa-lb-cap">
            <span class="pa-lb-title" data-pa-title></span>
            <span class="pa-lb-date" data-pa-date></span>
            <span class="pa-lb-views" data-pa-lb-views hidden>
                <i class="fa-light fa-eye" aria-hidden="true"></i>
                <span data-pa-lb-views-n></span>
            </span>
            <a class="pa-lb-loc" data-pa-loc hidden target="_blank" rel="noopener noreferrer">
                <i class="fa-light fa-location-dot" aria-hidden="true"></i> 查看位置
            </a>
        </figcaption>
    </figure>
</div>

<script>
// 全屏浏览 + 灯箱关闭时用左右方向键翻页
(function () {
    const btn = document.querySelector('[data-pa-fullscreen]');
    const icon = btn ? btn.querySelector('i') : null;

    const syncIcon = () => {
        if (!icon) return;
        const on = !!document.fullscreenElement;
        icon.classList.toggle('fa-expand', !on);
        icon.classList.toggle('fa-compress', on);
        btn.title = on ? '退出全屏' : '全屏浏览';
    };

    if (btn) {
        btn.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                // Safari iOS 上 documentElement 没有此方法 —— 静默降级
                document.documentElement.requestFullscreen?.().catch(() => {});
            }
        });
        document.addEventListener('fullscreenchange', syncIcon);
    }

    document.addEventListener('keydown', (e) => {
        const lb = document.querySelector('[data-pa-lb]');
        if (lb && !lb.hidden) return; // 灯箱打开时方向键归灯箱
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key === 'f' || e.key === 'F') { btn?.click(); return; }
        const rel = e.key === 'ArrowLeft' ? 'prev' : (e.key === 'ArrowRight' ? 'next' : '');
        if (!rel) return;
        const link = document.querySelector('.pa-pager a[rel="' + rel + '"]');
        if (link) window.location.href = link.href;
    });
})();

(function () {
    const grid = document.querySelector('[data-pa-grid]');
    const lb = document.querySelector('[data-pa-lb]');
    if (!grid || !lb) return;

    const tiles = Array.from(grid.querySelectorAll('.pa-tile'));
    const img = lb.querySelector('[data-pa-img]');
    const titleEl = lb.querySelector('[data-pa-title]');
    const dateEl = lb.querySelector('[data-pa-date]');
    const locEl = lb.querySelector('[data-pa-loc]');
    const viewsEl = lb.querySelector('[data-pa-lb-views]');
    const viewsN = lb.querySelector('[data-pa-lb-views-n]');
    const spinner = lb.querySelector('[data-pa-spinner]');
    let cur = -1;

    const formatViews = (n) => {
        n = Math.max(0, parseInt(n, 10) || 0);
        return n.toLocaleString('zh-CN');
    };

    // 灯箱每次展示计 1 次；成功后同步网格角标与灯箱数字
    const recordView = (tile) => {
        const filename = tile?.dataset?.filename || '';
        if (!filename) return;
        const body = JSON.stringify({ filename });
        const apply = (views) => {
            if (typeof views !== 'number' || views < 0) return;
            tile.dataset.views = String(views);
            const badge = tile.querySelector('[data-pa-views]');
            if (badge) badge.textContent = formatViews(views);
            if (viewsN) viewsN.textContent = formatViews(views);
            if (viewsEl) viewsEl.hidden = false;
        };
        // 乐观 +1，接口返回权威值再校正
        apply((parseInt(tile.dataset.views || '0', 10) || 0) + 1);
        fetch('/api/v1/view', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).then((r) => r.ok ? r.json() : null).then((data) => {
            if (data && data.status === 'ok' && typeof data.views === 'number') apply(data.views);
        }).catch(() => {});
    };

    // 灯箱大图:加载时转圈,加载完淡入
    img.addEventListener('load', () => { spinner.hidden = true; img.classList.add('is-loaded'); });
    img.addEventListener('error', () => { spinner.hidden = true; });

    const show = (i) => {
        if (i < 0 || i >= tiles.length) return;
        cur = i;
        const t = tiles[i];
        spinner.hidden = false;
        img.classList.remove('is-loaded');
        img.src = t.dataset.full;
        const title = t.dataset.title || '';
        const date = t.dataset.date || '';
        const map = t.dataset.map || '';
        const views = parseInt(t.dataset.views || '0', 10) || 0;
        titleEl.textContent = title;
        titleEl.style.display = title ? '' : 'none';
        dateEl.textContent = date;
        dateEl.style.display = date ? '' : 'none';
        if (viewsN) viewsN.textContent = formatViews(views);
        if (viewsEl) viewsEl.hidden = false;
        if (locEl) {
            if (map) {
                locEl.href = map;
                locEl.hidden = false;
            } else {
                locEl.removeAttribute('href');
                locEl.hidden = true;
            }
        }
        recordView(t);
    };
    const open = (i) => { show(i); lb.hidden = false; document.body.style.overflow = 'hidden'; };
    const close = () => { lb.hidden = true; img.src = ''; document.body.style.overflow = ''; };
    const prev = () => show((cur - 1 + tiles.length) % tiles.length);
    const next = () => show((cur + 1) % tiles.length);

    grid.addEventListener('click', (e) => {
        const tile = e.target.closest('.pa-tile');
        if (!tile) return;
        e.preventDefault();
        open(tiles.indexOf(tile));
    });
    lb.querySelector('[data-pa-close]').addEventListener('click', close);
    lb.querySelector('[data-pa-prev]').addEventListener('click', (e) => { e.stopPropagation(); prev(); });
    lb.querySelector('[data-pa-next]').addEventListener('click', (e) => { e.stopPropagation(); next(); });
    lb.addEventListener('click', (e) => {
        if (e.target === lb || e.target.classList.contains('pa-lb-stage')) close();
    });
    document.addEventListener('keydown', (e) => {
        if (lb.hidden) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') prev();
        else if (e.key === 'ArrowRight') next();
    });

    // 网格：sabi 同款「不同时间淡入」——随机顺序错落，不整页齐刷
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const STAGGER_MS = 120; // 与 sabi each:.12 一致

    if (reduceMotion) {
        tiles.forEach((t) => t.classList.add('is-revealed'));
    } else {
        const order = tiles.map((_, i) => i);
        for (let i = order.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = order[i];
            order[i] = order[j];
            order[j] = tmp;
        }
        // 立刻开演，不等全部下载完，才能看出先后淡入
        order.forEach((idx, rank) => {
            window.setTimeout(() => {
                tiles[idx]?.classList.add('is-revealed');
            }, 40 + rank * STAGGER_MS);
        });
    }
})();
</script>
<?php
require_once APP_ROOT . '/footer.php';

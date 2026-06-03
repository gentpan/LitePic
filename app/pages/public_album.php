<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
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
 *   private   仅登录管理员,其他人 404
 *
 * Visit counter: every successful render bumps albums.view_count, but throttled
 * to once per (album,IP) per 30 min via a session cookie so refresh-spam doesn't
 * inflate it.
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

$isAdmin = (new \LitePic\Service\Auth\AuthService())->isAdmin();

// 不存在 / private 非管理员 → 一律 404(不暴露相册存在与否)
if ($album === null || ((string)$album['visibility'] === 'private' && !$isAdmin)) {
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
if ($visibility !== 'password' || $isAdmin) {
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
                        'secure'   => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]
                );
                // PRG 防刷新重提 — 跳回访客实际访问的 URL key（保留 /a/3 vs /a/slug 选择）
                header('Location: /a/' . rawurlencode($albumKey));
                exit;
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

// ============== 访问计数(防刷)==============
// 双层去重 — cookie 是 UX 快路径(避免连续刷新都打 DB),SQLite 表是
// authoritative。之前只有 cookie,导致隐身/清 cookie 后能无限刷计数。
// (album_id, ip_hash, 30min-bucket) 组合 PK + INSERT OR IGNORE 是单
// 语句原子检查。
$visitCookieName = 'lp_album_visit_' . $cookieKey;
$shouldCount = false;
if (!isset($_COOKIE[$visitCookieName])) {
    $visitLog = new \LitePic\Repository\AlbumVisitLogRepository();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    try {
        $shouldCount = $visitLog->recordVisitIfNew((int)$album['id'], $clientIp);
    } catch (\Throwable $_) {
        // best-effort — DB error → fall back to cookie-only behaviour
        $shouldCount = true;
    }

    // Cookie still set either way — it's the cheap "skip the DB lookup"
    // bypass for the same browser, not the security gate.
    setcookie($visitCookieName, '1', [
        'expires'  => time() + 1800,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if ($shouldCount) {
    try {
        $albumRepo->incrementViewCount((int)$album['id']);
    } catch (\Throwable $_) { /* best-effort */ }
    // 反映在当次渲染里
    $album['view_count']++;
}

// ============== 主视图 ==============
$albumImageRepo = new \LitePic\Repository\AlbumImageRepository();
$info = new \LitePic\Service\Image\ImageInfo();

$filenames = $albumImageRepo->listFilenames((int)$album['id']);
// 一次性 batch-load DB 行 — 之前每张图都要单独 SELECT,500 张相册等于 500
// 次往返。preload 用一条 IN (...) 把它们全拉回来塞进 ImageInfo 的进程内
// 缓存,后续 getSafe() 都是命中缓存。
$info->preload($filenames);

$images = [];
foreach ($filenames as $filename) {
    $meta = $info->getSafe($filename);
    if ($meta === null) continue; // 跳过孤儿
    $title = (string)($meta['original_name'] ?? '');
    // 隐藏纯哈希文件名当标题(没意义),只在用户重命名过时才展示
    if ($title !== '' && preg_match('/^[0-9a-f]{16,}$/i', pathinfo($title, PATHINFO_FILENAME))) {
        $title = '';
    }
    $images[] = [
        'filename'   => $filename,
        'url'        => \LitePic\Service\Image\ImageUrl::forIdentifier($filename),
        'thumb_url'  => (string)($meta['thumb_url'] ?? \LitePic\Service\Image\ImageUrl::forIdentifier($filename)),
        'dimensions' => (string)($meta['dimensions'] ?? ''),
        'size'       => (int)($meta['size'] ?? 0),
        'title'      => $title,
        'date'       => (int)($meta['time'] ?? 0) > 0 ? date('Y-m-d', (int)$meta['time']) : '',
    ];
}

$coverUrl = '';
// 优先显式封面,未设置则用相册第一张图(cover_effective)
$coverSrc = (string)($album['cover_effective'] ?? $album['cover_filename'] ?? '');
if ($coverSrc !== '') {
    $coverUrl = \LitePic\Service\Image\ImageUrl::thumbnailUrl($coverSrc);
}

$page_title = $album['name'];
$body_class = 'public-album-page';
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
            <div class="pa-grid" data-pa-grid>
                <?php foreach ($images as $i => $img): ?>
                    <figure class="pa-tile" data-pa-index="<?= (int)$i ?>"
                            data-full="<?= htmlspecialchars($img['url']) ?>"
                            data-title="<?= htmlspecialchars($img['title']) ?>"
                            data-date="<?= htmlspecialchars($img['date']) ?>">
                        <img src="<?= htmlspecialchars($img['thumb_url']) ?>" alt="<?= htmlspecialchars($img['title']) ?>" loading="lazy"
                             <?php if ($img['dimensions'] !== '' && preg_match('/^(\d+)x(\d+)/', $img['dimensions'], $d)): ?>
                             width="<?= (int)$d[1] ?>" height="<?= (int)$d[2] ?>"
                             <?php endif; ?>>
                        <?php if ($img['title'] !== ''): ?>
                            <figcaption class="pa-tile-cap"><?= htmlspecialchars($img['title']) ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <footer class="pa-foot">
            <div class="pa-foot-left">
                <span class="pa-foot-name"><?= htmlspecialchars((string)$album['name']) ?></span>
                <span class="pa-foot-meta"><?= number_format((int)$album['image_count']) ?> 张 · <?= htmlspecialchars(date('Y-m-d', (int)$album['created_at'])) ?></span>
            </div>
            <div class="pa-foot-right">
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
        </figcaption>
    </figure>
</div>

<script>
(function () {
    const grid = document.querySelector('[data-pa-grid]');
    const lb = document.querySelector('[data-pa-lb]');
    if (!grid || !lb) return;

    const tiles = Array.from(grid.querySelectorAll('.pa-tile'));
    const img = lb.querySelector('[data-pa-img]');
    const titleEl = lb.querySelector('[data-pa-title]');
    const dateEl = lb.querySelector('[data-pa-date]');
    const spinner = lb.querySelector('[data-pa-spinner]');
    let cur = -1;

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
        titleEl.textContent = title;
        titleEl.style.display = title ? '' : 'none';
        dateEl.textContent = date;
        dateEl.style.display = date ? '' : 'none';
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

    // 网格缩略图:加载时淡入(已缓存的直接显示)
    tiles.forEach((t) => {
        const im = t.querySelector('img');
        if (!im) return;
        if (im.complete && im.naturalWidth) { im.classList.add('is-loaded'); return; }
        im.addEventListener('load', () => im.classList.add('is-loaded'), { once: true });
        im.addEventListener('error', () => im.classList.add('is-loaded'), { once: true });
    });
})();
</script>

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
// 用 session cookie 跟踪同一 IP+album 的 30 分钟去重窗口。
$visitCookieName = 'lp_album_visit_' . $cookieKey;
if (!isset($_COOKIE[$visitCookieName])) {
    try {
        $albumRepo->incrementViewCount((int)$album['id']);
    } catch (\Throwable $_) { /* best-effort */ }
    setcookie($visitCookieName, '1', [
        'expires'  => time() + 1800,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // 反映在当次渲染里
    $album['view_count']++;
}

// ============== 主视图 ==============
$albumImageRepo = new \LitePic\Repository\AlbumImageRepository();
$info = new \LitePic\Service\Image\ImageInfo();

$images = [];
foreach ($albumImageRepo->listFilenames((int)$album['id']) as $filename) {
    $meta = $info->getSafe($filename);
    if ($meta === null) continue; // 跳过孤儿
    $images[] = [
        'filename'   => $filename,
        'url'        => \LitePic\Service\Image\ImageUrl::forIdentifier($filename),
        'thumb_url'  => (string)($meta['thumb_url'] ?? \LitePic\Service\Image\ImageUrl::forIdentifier($filename)),
        'dimensions' => (string)($meta['dimensions'] ?? ''),
        'size'       => (int)($meta['size'] ?? 0),
    ];
}

$coverUrl = '';
if (!empty($album['cover_filename'])) {
    $coverUrl = \LitePic\Service\Image\ImageUrl::thumbnailUrl((string)$album['cover_filename']);
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

<main class="page-container page-main">
    <article class="page-shell public-album-shell" data-album-slug="<?= htmlspecialchars((string)($album['slug'] ?? '')) ?>" data-album-id="<?= (int)$album['id'] ?>" data-album-key="<?= htmlspecialchars($cookieKey) ?>">
        <header class="public-album-hero">
            <?php if ($coverUrl !== ''): ?>
                <div class="public-album-hero-cover" style="background-image:url('<?= htmlspecialchars($coverUrl) ?>')" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="public-album-hero-body">
                <h1 class="public-album-name"><?= htmlspecialchars((string)$album['name']) ?></h1>
                <?php if ((string)$album['description'] !== ''): ?>
                    <p class="public-album-desc"><?= nl2br(htmlspecialchars((string)$album['description'])) ?></p>
                <?php endif; ?>
                <div class="public-album-meta">
                    <span><i class="fa-light fa-images"></i> <?= number_format((int)$album['image_count']) ?> 张图片</span>
                    <span><i class="fa-light fa-eye"></i> <?= number_format((int)$album['view_count']) ?> 次访问</span>
                    <span><i class="fa-light fa-calendar"></i> <?= htmlspecialchars(date('Y-m-d', (int)$album['created_at'])) ?></span>
                    <?php if ($isAdmin): ?>
                        <span class="status-pill <?= htmlspecialchars($visBadge[1]) ?>"><?= htmlspecialchars($visBadge[0]) ?></span>
                        <a href="/albums/<?= (int)$album['id'] ?>/edit" class="public-album-admin-link" data-pjax>
                            <i class="fa-light fa-pen-to-square"></i><span>编辑相册</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php if (empty($images)): ?>
            <div class="public-album-empty">
                <i class="fa-light fa-image" aria-hidden="true"></i>
                <p>这个相册还没有图片</p>
            </div>
        <?php else: ?>
            <div class="public-album-grid" data-public-album-grid>
                <?php foreach ($images as $img): ?>
                    <figure class="public-album-tile" data-album-image>
                        <a href="<?= htmlspecialchars($img['url']) ?>" data-lightbox-src="<?= htmlspecialchars($img['url']) ?>"
                           target="_blank" rel="noopener noreferrer">
                            <img src="<?= htmlspecialchars($img['thumb_url']) ?>" alt="" loading="lazy"
                                 <?php if ($img['dimensions'] !== '' && preg_match('/^(\d+)x(\d+)/', $img['dimensions'], $d)): ?>
                                 width="<?= (int)$d[1] ?>" height="<?= (int)$d[2] ?>"
                                 <?php endif; ?>>
                        </a>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <footer class="public-album-foot">
            <span><a href="https://litepic.io" target="_blank" rel="noopener noreferrer">由 LitePic 驱动</a></span>
        </footer>
    </article>
</main>

<!-- 极简 lightbox — 点缩略图全屏看大图,ESC / 点击空白关闭 -->
<div class="public-album-lightbox" data-lightbox hidden>
    <button type="button" class="public-album-lightbox-close" data-lightbox-close aria-label="关闭">
        <i class="fa-light fa-xmark"></i>
    </button>
    <img alt="" data-lightbox-img>
</div>

<script>
(function () {
    const grid = document.querySelector('[data-public-album-grid]');
    const lb = document.querySelector('[data-lightbox]');
    if (!grid || !lb) return;

    const lbImg = lb.querySelector('[data-lightbox-img]');
    const closeBtn = lb.querySelector('[data-lightbox-close]');

    const open = (src) => {
        lbImg.src = src;
        lb.hidden = false;
        document.body.style.overflow = 'hidden';
    };
    const close = () => {
        lb.hidden = true;
        lbImg.src = '';
        document.body.style.overflow = '';
    };

    grid.addEventListener('click', (e) => {
        const a = e.target.closest('[data-lightbox-src]');
        if (!a) return;
        e.preventDefault();
        open(a.getAttribute('data-lightbox-src'));
    });
    closeBtn.addEventListener('click', close);
    lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !lb.hidden) close(); });
})();
</script>

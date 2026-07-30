<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
}

$is_logged_in = \LitePic\Service\Auth\UserContext::isLoggedIn();

$body_class = 'home-guest';
$page_title = '';
$html_title = trim(SITE_DESCRIPTION) !== '' ? SITE_NAME . ' ｜ ' . SITE_DESCRIPTION : SITE_NAME;
$image_count = (new \LitePic\Service\Stats\FooterStats())->imageCount();
$total_size = (new \LitePic\Service\Stats\FooterStats())->totalSize();

// 仅当至少注册过一个 Passkey 时才显示 "使用 Passkey 登录" 按钮，
// 否则点了也只能弹错（实际是后端 404 走 nginx error_page 被替换成 HTML 页）。
// 渲染时一次轻量 COUNT 比让前端跑一次失败的 fetch 更友好。
$passkey_available = false;
if (!$is_logged_in) {
    try {
        $rpId = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($rpId === '127.0.0.1') $rpId = 'localhost';
        $passkey_available = (new \LitePic\Service\Auth\Passkey\WebAuthn(
            SITE_NAME, $rpId, \LitePic\Core\RequestContext::requestOrigin()
        ))->hasCredentials();
    } catch (\Throwable $_) {
        // Schema not yet migrated, table missing, etc — fail safe (hide button).
    }
}

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="home-hero" aria-label="图床首页">
        <div class="home-hero-inner">
            <h1 class="home-hero-title"><?= htmlspecialchars(SITE_NAME) ?></h1>
            <p class="home-hero-description"><?= htmlspecialchars(SITE_DESCRIPTION) ?></p>
            <div class="home-hero-stats" aria-label="站点统计" data-home-stats>
                <span>本站已托管</span>
                <span class="home-stat-number" data-count-to="<?= (int)$image_count ?>"><?= number_format($image_count) ?></span>
                <span>张图片，共占用储存</span>
                <span class="home-stat-number" data-size-to="<?= (int)$total_size ?>"><?= htmlspecialchars(\LitePic\Core\Format::filesize($total_size)) ?></span>
            </div>

            <div class="home-hero-actions">
                <?php if ($is_logged_in): ?>
                    <a href="/upload" class="home-hero-btn home-hero-btn-upload">
                        <i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
                        <span>立刻上传</span>
                    </a>
                <?php else: ?>
                    <button type="button" class="home-hero-btn home-hero-btn-upload auth-toast-btn" title="立刻上传" aria-label="立刻上传" data-auth-message="登录后操作">
                        <i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
                        <span>立刻上传</span>
                    </button>
                <?php endif; ?>

                <?php if ($is_logged_in): ?>
                    <button type="button" class="home-hero-btn home-hero-btn-logout logout-btn" title="登出" aria-label="登出" data-cookie-name="<?= htmlspecialchars(API_KEY_COOKIE) ?>">
                        <i class="fa-light fa-right-from-bracket" aria-hidden="true"></i>
                        <span>登出</span>
                    </button>
                <?php else: ?>
                    <div class="home-user-login">
                        <button type="button" class="home-hero-btn home-hero-btn-user login-btn" title="登录" aria-label="登录" data-login-redirect="/gallery/">
                            <i class="fa-light fa-right-to-bracket" aria-hidden="true"></i>
                            <span>登录</span>
                        </button>
                        <div id="loginPanel" class="login-panel home-login-panel" role="dialog" aria-modal="true" aria-labelledby="homeLoginTitle" aria-hidden="true" data-modal="1" data-success-redirect="/gallery/">
                            <div class="home-login-dialog" role="document">
                                <button type="button" class="home-login-close" aria-label="关闭登录窗口" data-login-close>
                                    <i class="fa-light fa-xmark" aria-hidden="true"></i>
                                </button>
                                <div class="login-panel-header" id="homeLoginTitle">
                                    <i class="fa-light fa-key" aria-hidden="true"></i>
                                    <span>管理员登录</span>
                                </div>
                                <div class="login-form">
                                    <?php if (\LitePic\Service\Auth\UserContext::enabled()): ?>
                                        <div class="input-group">
                                            <i class="fa-light fa-user" aria-hidden="true"></i>
                                            <input type="text"
                                                id="loginUsername"
                                                placeholder="用户名 / 邮箱（管理员留空用下方密码）"
                                                autocomplete="username">
                                        </div>
                                        <div class="input-group">
                                            <i class="fa-light fa-lock" aria-hidden="true"></i>
                                            <input type="password"
                                                id="loginPassword"
                                                placeholder="密码"
                                                autocomplete="current-password">
                                        </div>
                                        <button type="button" class="login-submit" id="userLoginSubmit">
                                            <i class="fa-light fa-arrow-right-to-bracket" aria-hidden="true"></i>
                                            <span>登录</span>
                                        </button>
                                        <p class="m-0 text-xs text-gray" style="text-align:center; margin: 10px 0 2px;">— 管理员 —</p>
                                    <?php endif; ?>
                                    <div class="input-group">
                                        <i class="fa-light fa-lock" aria-hidden="true"></i>
                                        <input type="password"
                                            id="apiKey"
                                            placeholder="请输入 API Key"
                                            autocomplete="off">
                                    </div>
                                    <button type="button" class="login-submit">
                                        <i class="fa-light fa-arrow-right-to-bracket" aria-hidden="true"></i>
                                        <span>登录</span>
                                    </button>
                                    <?php if ($passkey_available): ?>
                                    <button type="button" class="login-submit login-passkey-btn">
                                        <i class="fa-light fa-fingerprint" aria-hidden="true"></i>
                                        <span>使用 Passkey 登录</span>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (\LitePic\Service\Auth\UserContext::enabled()): ?>
                                        <?php foreach (\LitePic\Service\Auth\OAuth\OAuthService::enabledProviders() as $p): ?>
                                            <a class="login-submit" href="/oauth/<?= htmlspecialchars($p) ?>/start" style="text-decoration:none; text-align:center;">
                                                <i class="fa-brands fa-<?= $p === 'google' ? 'google' : 'github' ?>" aria-hidden="true"></i>
                                                <span>使用 <?= $p === 'google' ? 'Google' : 'GitHub' ?> 登录</span>
                                            </a>
                                        <?php endforeach; ?>
                                        <p class="m-0 text-sm text-gray" style="text-align:center; margin-top: 10px;">
                                            还没有账号？<a href="/register">立即注册</a>
                                        </p>
                                        <script>
                                            (function () {
                                                var btn = document.getElementById('userLoginSubmit');
                                                if (!btn) return;
                                                btn.addEventListener('click', function () {
                                                    var username = document.getElementById('loginUsername').value.trim();
                                                    var password = document.getElementById('loginPassword').value;
                                                    btn.disabled = true;
                                                    fetch('/api/auth.php', {
                                                        method: 'POST',
                                                        headers: {'Content-Type': 'application/json'},
                                                        body: JSON.stringify({
                                                            action: 'user_login',
                                                            username: username,
                                                            password: password
                                                        })
                                                    }).then(function (r) { return r.json(); }).then(function (res) {
                                                        btn.disabled = false;
                                                        if (res.status === 'success' || res.code === 0) {
                                                            if (res.csrf_token) { window.CSRF_TOKEN = res.csrf_token; }
                                                            window.location.href = '/gallery/';
                                                        } else {
                                                            alert(res.message || '登录失败');
                                                        }
                                                    }).catch(function () {
                                                        btn.disabled = false;
                                                        alert('网络错误，请稍后重试');
                                                    });
                                                });
                                            })();
                                        </script>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php
require_once APP_ROOT . '/footer.php';

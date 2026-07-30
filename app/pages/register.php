<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
}

use LitePic\Service\Auth\OAuth\OAuthService;
use LitePic\Service\Auth\UserContext;

// 多用户未开启 → 注册页不存在
if (!UserContext::enabled()) {
    \LitePic\Core\HttpCache::redirect('/');
}

// 已登录 → 直接进图库
if (UserContext::isLoggedIn()) {
    \LitePic\Core\HttpCache::redirect('/gallery');
}

$mode = UserContext::mode(); // invite | open
$invite_code = trim((string)($_GET['invite'] ?? ''));
$oauth_error = trim((string)($_GET['oauth_error'] ?? ''));
$oauth_providers = OAuthService::enabledProviders();

$body_class = 'home-guest';
$page_title = '注册账号';
$html_title = '注册账号 ｜ ' . SITE_NAME;

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="home-hero" aria-label="注册账号">
        <div class="home-login-dialog" style="max-width: 420px; margin: 48px auto; position: relative;">
            <div class="login-panel-header">
                <i class="fa-light fa-user-plus" aria-hidden="true"></i>
                <span>注册 <?= htmlspecialchars(SITE_NAME) ?> 账号</span>
            </div>

            <?php if ($oauth_error !== ''): ?>
                <div class="settings-callout" style="margin: 12px 0;">
                    <strong>登录失败</strong>
                    <p class="m-0"><?= htmlspecialchars($oauth_error) ?></p>
                </div>
            <?php endif; ?>

            <div class="login-form">
                <div class="input-group">
                    <i class="fa-light fa-user" aria-hidden="true"></i>
                    <input type="text" id="reg_username" placeholder="用户名（2-32 位字母数字）" autocomplete="username">
                </div>
                <div class="input-group">
                    <i class="fa-light fa-envelope" aria-hidden="true"></i>
                    <input type="email" id="reg_email" placeholder="邮箱（可选）" autocomplete="email">
                </div>
                <div class="input-group">
                    <i class="fa-light fa-lock" aria-hidden="true"></i>
                    <input type="password" id="reg_password" placeholder="密码（至少 8 位）" autocomplete="new-password">
                </div>
                <div class="input-group">
                    <i class="fa-light fa-lock" aria-hidden="true"></i>
                    <input type="password" id="reg_confirm" placeholder="确认密码" autocomplete="new-password">
                </div>
                <div class="input-group">
                    <i class="fa-light fa-ticket" aria-hidden="true"></i>
                    <input type="text" id="reg_invite" placeholder="<?= $mode === 'invite' ? '邀请码（必填）' : '邀请码（可选）' ?>"
                           value="<?= htmlspecialchars($invite_code) ?>" autocomplete="off">
                </div>
                <button type="button" class="login-submit" id="reg_submit">
                    <i class="fa-light fa-user-plus" aria-hidden="true"></i>
                    <span>注册并登录</span>
                </button>

                <?php if ($oauth_providers !== []): ?>
                    <p class="m-0 text-sm text-gray" style="text-align:center; margin: 12px 0 4px;">或使用第三方账号注册 / 登录</p>
                    <?php foreach ($oauth_providers as $p): ?>
                        <?php
                        $oauthUrl = '/oauth/' . $p . '/start' . ($invite_code !== '' ? '?invite=' . rawurlencode($invite_code) : '');
                        ?>
                        <a class="login-submit" href="<?= htmlspecialchars($oauthUrl) ?>" style="text-decoration:none; text-align:center;">
                            <i class="fa-brands fa-<?= $p === 'google' ? 'google' : 'github' ?>" aria-hidden="true"></i>
                            <span><?= $p === 'google' ? 'Google' : 'GitHub' ?> 一键注册 / 登录</span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($mode === 'invite'): ?>
                        <p class="m-0 text-xs text-gray" style="text-align:center;">邀请制注册：使用第三方登录时请先填写上方邀请码再点按钮，或使用邀请链接 /oauth/<?= htmlspecialchars($oauth_providers[0]) ?>/start?invite=邀请码</p>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="m-0 text-sm text-gray" style="text-align:center; margin-top: 12px;">
                    已有账号？<a href="/">返回首页登录</a>
                </p>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    var btn = document.getElementById('reg_submit');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var username = document.getElementById('reg_username').value.trim();
        var email = document.getElementById('reg_email').value.trim();
        var password = document.getElementById('reg_password').value;
        var confirm = document.getElementById('reg_confirm').value;
        var invite = document.getElementById('reg_invite').value.trim();

        if (password !== confirm) {
            alert('两次输入的密码不一致');
            return;
        }
        btn.disabled = true;
        fetch('/api/auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'register',
                username: username,
                email: email,
                password: password,
                invite_code: invite
            })
        }).then(function (r) { return r.json(); }).then(function (res) {
            btn.disabled = false;
            if (res.status === 'success' || res.code === 0) {
                if (res.csrf_token) { window.CSRF_TOKEN = res.csrf_token; }
                window.location.href = '/gallery/';
            } else {
                alert(res.message || '注册失败');
            }
        }).catch(function () {
            btn.disabled = false;
            alert('网络错误，请稍后重试');
        });
    });
})();
</script>

<?php
require_once APP_ROOT . '/footer.php';

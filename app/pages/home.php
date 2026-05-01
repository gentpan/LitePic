<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/Core/bootstrap.php';
}

$is_logged_in = is_admin();

$body_class = 'home-guest';
$page_title = '';
$html_title = trim(SITE_DESCRIPTION) !== '' ? SITE_NAME . ' ｜ ' . SITE_DESCRIPTION : SITE_NAME;
$image_count = get_image_count();
$total_size = get_total_size();

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
                <span class="home-stat-number" data-size-to="<?= (int)$total_size ?>"><?= htmlspecialchars(format_filesize($total_size)) ?></span>
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
                        <button type="button" class="home-hero-btn home-hero-btn-user login-btn" title="登录" aria-label="登录" data-login-redirect="/gallery">
                            <i class="fa-light fa-right-to-bracket" aria-hidden="true"></i>
                            <span>登录</span>
                        </button>
                        <div id="loginPanel" class="login-panel home-login-panel" role="dialog" aria-modal="true" aria-labelledby="homeLoginTitle" aria-hidden="true" data-modal="1" data-success-redirect="/gallery">
                            <div class="home-login-dialog" role="document">
                                <button type="button" class="home-login-close" aria-label="关闭登录窗口" data-login-close>
                                    <i class="fa-light fa-xmark" aria-hidden="true"></i>
                                </button>
                                <div class="login-panel-header" id="homeLoginTitle">
                                    <i class="fa-light fa-key" aria-hidden="true"></i>
                                    <span>管理员登录</span>
                                </div>
                                <div class="login-form">
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
                                    <button type="button" class="login-submit login-passkey-btn">
                                        <i class="fa-light fa-fingerprint" aria-hidden="true"></i>
                                        <span>使用 Passkey 登录</span>
                                    </button>
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

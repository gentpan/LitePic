<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}

$body_class = 'home-guest';
$page_title = '';

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="home-hero" aria-label="图床首页">
        <div class="home-hero-inner">
            <div class="home-hero-mark" aria-hidden="true">
                <i class="fa-brands fa-upwork"></i>
            </div>
            <h1 class="home-hero-title">LitePic V2.3</h1>
            <p class="home-hero-description">
                轻量级 PHP 图床，支持 API Token 上传、缩略图、自动压缩与 WebP 转换、
                图库管理、统计面板、文档页、以及 R2/S3 同步。
            </p>

            <div class="home-hero-formats" role="note" aria-label="支持格式">
                <span class="formats-label">
                    <i class="fa-light fa-info-circle" aria-hidden="true"></i>
                    支持以下格式
                </span>
                <div class="formats-list">
                    <?php foreach (ALLOWED_TYPES as $ext): ?>
                        <span class="format-tag">.<?= htmlspecialchars(strtoupper((string)$ext)) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</main>

<?php
require_once APP_ROOT . '/footer.php';

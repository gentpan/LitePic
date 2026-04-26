<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}
/**
 * LitePic V2.2 - 轻量级图床程序
 * 
 * 一个简洁、高效的图片托管解决方案
 * 支持多图上传、拖拽上传、粘贴上传等功能
 * 
 * @package     LitePic
 * @author      gentpan
 * @copyright   2026 LitePic
 * @license     MIT License
 * @link        https://litepic.io
 * @version     1.0.0
 */


// 检查登录状态
$is_logged_in = ADMIN_API_KEY !== '' &&
                isset($_COOKIE[API_KEY_COOKIE]) &&
                hash_equals(hash('sha256', ADMIN_API_KEY), (string)$_COOKIE[API_KEY_COOKIE]);

// 设置页面标题（未登录首页仅显示站点名）
$page_title = $is_logged_in ? '图片上传' : '';


if (!$is_logged_in) {
    $body_class = 'home-guest';
}

// 前端上传大小校验与后端一致（受 PHP 与系统配置共同约束）
$effective_max_upload_bytes = get_effective_upload_max_bytes();

// 加载页头部分
require_once APP_ROOT . '/header.php';
?>

<main class="container page-main">
    <?php if (!$is_logged_in): ?>
        <section class="home-hero" aria-label="图床首页">
            <div class="home-hero-inner">
                <div class="home-hero-mark" aria-hidden="true">
                    <i class="fa-brands fa-upwork"></i>
                </div>
                <h1 class="home-hero-title">LitePic V2.2</h1>
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
    <?php else: ?>
        <style>
            /* 上传页可见性兜底，避免合并样式冲突导致上传提示消失 */
            .page-main .upload-box #dropZone .file-input-wrapper {
                width: 100%;
                max-width: 980px;
                margin: 0 auto;
                position: relative;
            }
            .page-main .upload-box #dropZone .file-input-wrapper label {
                min-height: 360px;
                display: flex !important;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 14px;
                text-align: center;
                color: #111827;
            }
            .page-main .upload-box #dropZone .icon-upload {
                display: inline-flex !important;
                font-size: 56px !important;
                line-height: 1;
                color: #6b7280 !important;
            }
            .page-main .upload-box #dropZone .file-input-wrapper:hover .icon-upload {
                color: var(--primary) !important;
            }
            .page-main .upload-box #dropZone .upload-tips {
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;
            }
            .page-main .upload-box #dropZone .upload-tips .text {
                display: block !important;
                font-size: 24px;
                font-weight: 500;
                line-height: 1.2;
                color: #111827 !important;
            }
            .page-main .upload-box #dropZone .upload-tips .hint {
                display: block !important;
                margin-top: 4px;
                font-size: 16px;
                color: #6b7280 !important;
            }
            .page-main .recent-uploads {
                display: block !important;
            }
            .page-main .recent-uploads .upload-grid {
                min-height: 120px;
            }
            @media (max-width: 768px) {
                .page-main .upload-box #dropZone .file-input-wrapper label {
                    min-height: 260px;
                }
                .page-main .upload-box #dropZone .icon-upload {
                    font-size: 42px !important;
                }
                .page-main .upload-box #dropZone .upload-tips .text {
                    font-size: 20px;
                }
                .page-main .upload-box #dropZone .upload-tips .hint {
                    font-size: 14px;
                }
            }
        </style>

        <!-- 上传区域 -->
        <div class="upload-box page-shell">
            <div class="upload-header page-shell-header">
                <i class="fa-brands fa-upwork"></i>
                <div class="upload-info">
                    <span class="hint">
                        <i class="fa-light fa-info-circle"></i>
                        <span>支持以下格式:
                            <?php
                            $types = array_map(function ($ext) {
                                return '<span>.' . strtoupper($ext) . '</span>';
                            }, ALLOWED_TYPES);
                            echo implode(' ', $types);
                            ?>
                        </span>
                    </span>
                </div>
            </div>

            <div id="dropZone" class="drop-zone page-shell-body">
                <form id="uploadForm" action="/api/upload.php" method="POST" enctype="multipart/form-data">
                    <div class="file-input-wrapper">
                        <input type="file"
                            name="image[]"
                            id="imageInput"
                            data-max-size="<?= (int)$effective_max_upload_bytes ?>"
                            data-auto-compress="<?= AUTO_COMPRESS_ON_UPLOAD ? '1' : '0' ?>"
                            data-auto-webp="<?= AUTO_CONVERT_WEBP_ON_UPLOAD ? '1' : '0' ?>"
                            accept="image/*"
                            multiple
                            required>
                        <label for="imageInput">
                            <i class="fa-light fa-cloud-arrow-up icon-upload"></i>
                            <div class="upload-tips">
                                <span class="text">选择图片或拖拽到此处</span>
                                <span class="hint">支持多选 / 拖拽 / 粘贴上传</span>
                            </div>
                        </label>
                    </div>
                </form>
            </div>

            <!-- 上传进度条 -->
            <div id="uploadProgress" class="upload-progress is-hidden">
                <div class="progress-bar">
                    <div class="progress-bar-inner"></div>
                </div>
                <div class="progress-status">
                    <span class="progress-text"></span>
                    <span class="progress-percent"></span>
                </div>
            </div>
        </div>

        <!-- 最近上传预览 -->
        <div class="recent-uploads page-shell">
            <div class="recent-header page-shell-header">
                <h3>
                    <i class="fa-light fa-clock-rotate-left"></i>
                    最近上传
                </h3>
                <a href="/gallery" class="view-all">
                    查看全部
                    <i class="fa-light fa-arrow-right"></i>
                </a>
            </div>
            <div class="upload-grid page-shell-body">
                <?php
                $recent_images = array_slice(get_uploaded_images(), 0, 5);
                foreach ($recent_images as $image):
                    $img_url = get_img_url($image);
                    $preview_url = $img_url;
                    if (can_generate_thumbnail($image) && create_thumbnail((string)$image)) {
                        $preview_url = get_thumbnail_url((string)$image);
                    }
                ?>
                    <div class="img-box" 
                        data-filename="<?= htmlspecialchars($image) ?>"
                        data-date="<?= filemtime(get_file_path($image)) ?>"
                        data-size="<?= filesize(get_file_path($image)) ?>"
                        data-url="<?= htmlspecialchars($img_url) ?>">
                        <img src="<?= htmlspecialchars($preview_url) ?>" 
                             data-original-url="<?= htmlspecialchars($img_url) ?>"
                             alt="<?= htmlspecialchars($image) ?>"
                             loading="lazy">
                        <div class="img-overlay">
                            <button class="action-btn copy-btn" 
                                    title="复制图片链接"
                                    type="button">
                                <i class="fa-light fa-copy"></i>
                            </button>
                            <button class="action-btn delete-btn" 
                                    type="button"
                                    title="删除图片">
                                <i class="fa-light fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_images)): ?>
                    <div class="recent-empty">
                        <i class="fa-light fa-image"></i>
                        <span>暂无最近上传图片</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php if ($is_logged_in): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof GalleryManager !== 'undefined') {
        GalleryManager.initUploadPage();
    }
});
</script>
<?php endif; ?>

<?php
// 加载页脚
require_once APP_ROOT . '/footer.php';

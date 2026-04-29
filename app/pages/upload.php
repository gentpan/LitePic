<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}

// 检查登录状态
$is_logged_in = ADMIN_API_KEY !== '' &&
                isset($_COOKIE[API_KEY_COOKIE]) &&
                hash_equals(hash('sha256', ADMIN_API_KEY), (string)$_COOKIE[API_KEY_COOKIE]);

$page_title = '图片上传';

// 前端上传大小校验与后端一致（受 PHP 与系统配置共同约束）
$effective_max_upload_bytes = get_effective_upload_max_bytes();

// 加载页头部分
require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <?php if (!$is_logged_in): ?>
        <!-- 未登录提示 -->
        <div class="page-shell bg-surface border border-border rounded-md max-w-[480px] mx-auto mt-20 p-8 text-center">
            <div class="mb-6">
                <i class="fa-light fa-lock text-4xl text-primary"></i>
            </div>
            <h2 class="text-xl font-bold text-dark mb-2">需要登录</h2>
            <p class="text-gray mb-6">请先登录以使用上传功能。</p>
            <button type="button" class="login-btn inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-sm text-sm font-medium cursor-pointer border-0 hover:bg-primary/90 transition-colors" title="登录">
                <i class="fa-light fa-right-to-bracket"></i>
                <span>登录</span>
            </button>
        </div>
    <?php else: ?>

        <!-- 上传区域 -->
        <div class="upload-box page-shell bg-surface border border-border rounded-md">
            <div class="upload-header page-shell-header text-center mb-8">
                <i class="fa-brands fa-upwork text-2xl text-primary"></i>
                <div class="upload-info mt-4 w-full flex justify-center">
                    <span class="hint inline-flex items-center justify-center gap-2 bg-light rounded-md text-sm text-gray border border-border">
                        <i class="fa-light fa-info-circle inline-flex items-center justify-center text-base leading-none text-primary"></i>
                        <span class="inline-flex items-center gap-2 leading-none">支持以下格式:
                            <?php
                            $types = array_map(function ($ext) {
                                return '<span class="inline-block px-1 py-0.5 bg-surface rounded-md text-primary font-medium mx-1 border border-border">.' . strtoupper($ext) . '</span>';
                            }, ALLOWED_TYPES);
                            echo implode(' ', $types);
                            ?>
                        </span>
                    </span>
                </div>
            </div>

            <div id="dropZone" class="drop-zone page-shell-body">
                <form id="uploadForm" action="/api/upload.php" method="POST" enctype="multipart/form-data">
                    <div class="file-input-wrapper w-full max-w-[980px] mx-auto relative p-0 h-full">
                        <input type="file"
                            name="image[]"
                            id="imageInput"
                            data-max-size="<?= (int)$effective_max_upload_bytes ?>"
                            data-auto-compress="<?= AUTO_COMPRESS_ON_UPLOAD ? '1' : '0' ?>"
                            data-auto-webp="<?= AUTO_CONVERT_WEBP_ON_UPLOAD ? '1' : '0' ?>"
                            accept="image/*"
                            multiple
                            required
                            class="absolute top-0 left-0 w-full h-full opacity-0 cursor-pointer z-20">
                        <label for="imageInput" class="h-full min-h-0 flex flex-col items-center justify-center gap-2 cursor-pointer p-8 relative z-10 text-center text-dark">
                            <i class="fa-light fa-cloud-arrow-up icon-upload inline-flex text-[32px] leading-none text-gray"></i>
                            <div class="upload-tips block opacity-100 visible">
                                <span class="text block text-base font-medium leading-tight text-dark">选择图片或拖拽到此处</span>
                                <span class="hint block mt-0.5 text-[13px] text-gray">支持多选 / 拖拽 / 粘贴上传</span>
                            </div>
                        </label>
                    </div>
                </form>
            </div>

            <!-- 上传进度条 -->
            <div id="uploadProgress" class="upload-progress is-hidden mt-8 opacity-0 translate-y-5">
                <div class="progress-bar h-1.5 bg-light rounded-md overflow-hidden relative">
                    <div class="progress-bar-inner absolute left-0 top-0 h-full bg-success w-0 rounded-md"></div>
                </div>
                <div class="progress-status flex justify-between items-center mt-2">
                    <span class="progress-text flex items-center gap-2 text-success text-sm"></span>
                    <span class="progress-percent text-sm font-medium text-success"></span>
                </div>
            </div>
        </div>

        <!-- 最近上传预览 -->
        <div class="recent-uploads page-shell bg-surface border border-border rounded-md p-4 block">
            <div class="recent-header page-shell-header flex justify-between items-center mb-6 border-b border-border py-2.5 px-3.5">
                <h3 class="flex items-center gap-2 m-0 text-dark">
                    <i class="fa-light fa-clock-rotate-left text-primary"></i>
                    最近上传
                </h3>
                <a href="/gallery" class="view-all flex items-center gap-2 text-primary no-underline text-sm px-2 py-1 rounded-md">
                    查看全部
                    <i class="fa-light fa-arrow-right"></i>
                </a>
            </div>
            <div class="upload-grid page-shell-body grid grid-cols-5 gap-0 border-t border-l border-border p-3.5">
                <?php
                $recent_images = array_slice(get_uploaded_images(), 0, 5);
                foreach ($recent_images as $image):
                    $img_url = get_img_url($image);
                    $preview_url = $img_url;
                    if (can_generate_thumbnail($image) && create_thumbnail((string)$image)) {
                        $preview_url = get_thumbnail_url((string)$image);
                    }
                ?>
                    <div class="img-box relative rounded-md overflow-hidden bg-surface border-r border-b border-border aspect-[10/7] h-auto self-start"
                        data-filename="<?= htmlspecialchars($image) ?>"
                        data-date="<?= filemtime(get_file_path($image)) ?>"
                        data-size="<?= filesize(get_file_path($image)) ?>"
                        data-url="<?= htmlspecialchars($img_url) ?>">
                        <img src="<?= htmlspecialchars($preview_url) ?>"
                             data-original-url="<?= htmlspecialchars($img_url) ?>"
                             alt="<?= htmlspecialchars($image) ?>"
                             loading="lazy"
                             class="w-full h-full object-cover block">
                        <div class="img-overlay absolute bottom-0 left-0 right-0 p-4 flex gap-2 opacity-0 translate-y-full">
                            <button class="action-btn copy-btn w-8 h-8 rounded-md border-none bg-white/90 text-dark cursor-pointer flex items-center justify-center"
                                    title="复制图片链接"
                                    type="button">
                                <i class="fa-light fa-copy"></i>
                            </button>
                            <button class="action-btn delete-btn w-8 h-8 rounded-md border-none bg-white/90 text-dark cursor-pointer flex items-center justify-center"
                                    type="button"
                                    title="删除图片">
                                <i class="fa-light fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_images)): ?>
                    <div class="recent-empty col-span-full min-h-[120px] border border-dashed border-border flex items-center justify-center gap-2.5 text-gray">
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

<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}

// 检查登录状态
$is_logged_in = is_admin();
if (!$is_logged_in) {
    header('Location: /');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_upload_processing') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!csrf_token_verify((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => '安全令牌无效或已过期，请刷新页面后重试',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $changed = strtolower(trim((string)($_POST['changed'] ?? '')));
    $auto_compress = (string)($_POST['auto_compress_on_upload'] ?? '0') === '1';
    $auto_convert = (string)($_POST['auto_convert_on_upload'] ?? '0') === '1';
    $convert_format = strtolower(trim((string)($_POST['convert_preferred_format'] ?? CONVERT_PREFERRED_FORMAT)));
    if (!in_array($convert_format, ['webp', 'avif'], true)) {
        $convert_format = 'webp';
    }

    $metrics = get_server_runtime_metrics();
    $capability = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [];
    if ($auto_convert && $convert_format === 'webp' && empty($capability['webp'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'WebP 支持未启用，无法开启上传后自动转换',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($auto_convert && $convert_format === 'avif' && empty($capability['avif'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'AVIF 支持未启用，无法开启上传后自动转换',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($changed === 'compress' && $auto_compress) {
        $auto_convert = false;
    } elseif ($changed === 'convert' && $auto_convert) {
        $auto_compress = false;
    } elseif ($auto_compress && $auto_convert) {
        $auto_compress = false;
    }

    $auto_webp = $auto_convert && $convert_format === 'webp';
    $auto_avif = $auto_convert && $convert_format === 'avif';
    $updated = write_env_kv([
        'AUTO_COMPRESS_ON_UPLOAD' => $auto_compress ? 'true' : 'false',
        'AUTO_CONVERT_WEBP_ON_UPLOAD' => $auto_webp ? 'true' : 'false',
        'AUTO_CONVERT_AVIF_ON_UPLOAD' => $auto_avif ? 'true' : 'false',
        'CONVERT_PREFERRED_FORMAT' => $convert_format,
    ]);

    if (!$updated) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '保存上传处理设置失败，请检查 .env 写入权限',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $current_compression_mode = get_compression_mode();
    $current_compression_labels = [
        'imagemagick' => 'ImageMagick',
        'gd' => 'GD 压缩',
        'tinypng' => 'TinyPNG 压缩',
    ];
    $current_compression_label = $current_compression_labels[$current_compression_mode] ?? 'ImageMagick';
    $messages = [];
    $messages[] = $auto_compress ? '上传后自动压缩已开启' : '上传后自动压缩已关闭';
    $messages[] = $auto_convert ? ('上传后自动转换已开启：' . strtoupper($convert_format)) : '上传后自动转换已关闭';

    echo json_encode([
        'success' => true,
        'message' => implode('；', $messages),
        'settings' => [
            'auto_compress_on_upload' => $auto_compress,
            'auto_convert_on_upload' => $auto_convert,
            'auto_convert_webp_on_upload' => $auto_webp,
            'auto_convert_avif_on_upload' => $auto_avif,
            'convert_preferred_format' => $convert_format,
            'conversion_label' => $auto_convert ? strtoupper($convert_format) : '关闭',
            'compression_label' => $auto_compress ? $current_compression_label : '关闭',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$page_title = '图片上传';

// 前端上传大小校验与后端一致（受 PHP 与系统配置共同约束）
$effective_max_upload_bytes = get_effective_upload_max_bytes();
$compression_mode = get_compression_mode();
$compression_mode_labels = [
    'imagemagick' => 'ImageMagick',
    'gd' => 'GD 压缩',
    'tinypng' => 'TinyPNG 压缩',
];
$compression_label = $compression_mode_labels[$compression_mode] ?? 'ImageMagick';
$compression_enabled = AUTO_COMPRESS_ON_UPLOAD;
$conversion_enabled = AUTO_CONVERT_WEBP_ON_UPLOAD || AUTO_CONVERT_AVIF_ON_UPLOAD;
$conversion_format = AUTO_CONVERT_AVIF_ON_UPLOAD ? 'AVIF' : 'WebP';
$tinypng_active_count = count(get_active_compression_api_keys());
$tinypng_mode_enabled = $compression_enabled && $compression_mode === 'tinypng';
$upload_mime_map = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml',
    'bmp' => 'image/bmp',
    'tiff' => 'image/tiff',
    'tif' => 'image/tiff',
];
$upload_accept_parts = array_map(static fn($ext) => '.' . $ext, ALLOWED_UPLOAD_TYPES);
foreach (ALLOWED_UPLOAD_TYPES as $ext) {
    if (isset($upload_mime_map[$ext]) && !in_array($upload_mime_map[$ext], $upload_accept_parts, true)) {
        $upload_accept_parts[] = $upload_mime_map[$ext];
    }
}
$upload_accept = implode(',', $upload_accept_parts);

// 加载页头部分
require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main upload-main">
    <!-- 上传区域 -->
    <section class="upload-box">
        <div class="upload-brand-icon" aria-hidden="true">
            <i class="fa-brands fa-upwork"></i>
        </div>
        <div class="upload-header upload-toolbar-row" data-upload-processing-controls data-csrf-token="<?= htmlspecialchars(csrf_token_get()) ?>" data-convert-format="<?= htmlspecialchars(strtolower($conversion_format)) ?>">
            <div class="upload-info">
                <span class="hint">
                    <i class="fa-light fa-info-circle"></i>
                    <span>支持以下格式:
                        <?php
                        $types = array_map(function ($ext) {
                            return '<span class="format-pill">.' . strtoupper($ext) . '</span>';
                        }, ALLOWED_UPLOAD_TYPES);
                        echo implode(' ', $types);
                        ?>
                    </span>
                </span>
            </div>
            <div class="upload-processing-controls" aria-label="上传处理设置">
                <label class="upload-setting-toggle <?= $compression_enabled ? 'is-active' : '' ?>" for="uploadAutoCompressToggle">
                    <span class="upload-setting-main">
                        <i class="fa-light fa-compress"></i>
                        <span>压缩</span>
                    </span>
                    <strong data-upload-compress-value><?= htmlspecialchars($compression_enabled ? $compression_label : '关闭') ?></strong>
                    <input
                        id="uploadAutoCompressToggle"
                        type="checkbox"
                        data-upload-setting-toggle="compress"
                        <?= $compression_enabled ? 'checked' : '' ?>>
                    <span class="upload-setting-switch" aria-hidden="true"><span></span></span>
                </label>
                <label class="upload-setting-toggle <?= $conversion_enabled ? 'is-active' : '' ?>" for="uploadAutoConvertToggle">
                    <span class="upload-setting-main">
                        <i class="fa-light fa-arrows-rotate"></i>
                        <span>转换</span>
                    </span>
                    <strong data-upload-convert-value><?= htmlspecialchars($conversion_enabled ? $conversion_format : '关闭') ?></strong>
                    <input
                        id="uploadAutoConvertToggle"
                        type="checkbox"
                        data-upload-setting-toggle="convert"
                        <?= $conversion_enabled ? 'checked' : '' ?>>
                    <span class="upload-setting-switch" aria-hidden="true"><span></span></span>
                </label>
            </div>
        </div>

        <div id="dropZone" class="drop-zone">
            <form id="uploadForm" action="/api/v1" method="POST" enctype="multipart/form-data">
                <div class="file-input-wrapper">
                    <input type="file"
                        name="image[]"
                        id="imageInput"
                        data-max-size="<?= (int)$effective_max_upload_bytes ?>"
                        data-auto-compress="<?= AUTO_COMPRESS_ON_UPLOAD ? '1' : '0' ?>"
                        data-auto-webp="<?= AUTO_CONVERT_WEBP_ON_UPLOAD ? '1' : '0' ?>"
                        data-auto-avif="<?= AUTO_CONVERT_AVIF_ON_UPLOAD ? '1' : '0' ?>"
                        data-allowed-types="<?= htmlspecialchars(implode(',', ALLOWED_UPLOAD_TYPES)) ?>"
                        accept="<?= htmlspecialchars($upload_accept) ?>"
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

        <div id="uploadQueuePanel" class="upload-queue is-empty" aria-live="polite">
            <div class="upload-queue-header">
                <div class="upload-queue-title">
                    <i class="fa-light fa-list-check"></i>
                    <span>待上传队列</span>
                    <strong id="uploadQueueCount">0</strong>
                </div>
                <div class="upload-queue-actions">
                    <button type="button" class="upload-queue-btn upload-queue-btn-muted" id="clearUploadQueue" disabled>
                        <i class="fa-light fa-trash-can"></i>
                        <span>清空队列</span>
                    </button>
                    <button type="button" class="upload-queue-btn upload-queue-btn-primary" id="uploadAllQueued" disabled>
                        <i class="fa-light fa-cloud-arrow-up"></i>
                        <span>上传全部</span>
                    </button>
                </div>
            </div>
            <div id="uploadQueueList" class="upload-queue-list"></div>
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
    </section>

    <!-- 最近上传预览 -->
    <section class="recent-uploads">
        <div class="recent-header">
            <h3>
                <i class="fa-light fa-clock-rotate-left"></i>
                <span>最近上传</span>
            </h3>
            <a href="/gallery" class="view-all">
                <span>查看全部</span>
                <i class="fa-light fa-arrow-right"></i>
            </a>
        </div>
        <div class="upload-grid">
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
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof GalleryManager !== 'undefined') {
        GalleryManager.initUploadPage();
    }
});
</script>

<?php
// 加载页脚
require_once APP_ROOT . '/footer.php';

<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
}

// 检查登录状态
$is_logged_in = (new \LitePic\Service\Auth\AuthService())->isAdmin();
if (!$is_logged_in) {
    \LitePic\Core\HttpCache::redirect('/');
}

// 相册下拉所需:把所有非 private 相册列出来
// key 用 slug-or-id(详见 AlbumService::urlKey),它就是 /api/v1/albums/<key> 段
$_albums_for_picker = [];
foreach ((new \LitePic\Repository\AlbumRepository())->all() as $_a) {
    if ($_a['visibility'] === 'private' && false) { /* 私有相册管理员也能看 */ }
    $_albums_for_picker[] = [
        'key'  => \LitePic\Service\Album\AlbumService::urlKey($_a),
        'name' => $_a['name'],
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['form_action'] ?? '') === 'save_upload_processing') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!\LitePic\Core\Csrf::verify((string)($_POST['csrf_token'] ?? ''))) {
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
    // 水印 toggle 直接翻转全局 WATERMARK_ENABLED — 跟设置页保持同一个开关源
    $watermark_enabled = (string)($_POST['watermark_enabled'] ?? '0') === '1';
    $convert_format = strtolower(trim((string)($_POST['convert_preferred_format'] ?? CONVERT_PREFERRED_FORMAT)));
    if (!in_array($convert_format, ['webp', 'avif', 'jpg', 'png'], true)) {
        $convert_format = 'webp';
    }

    $metrics = null;
    $capability = \LitePic\Service\Stats\ServerInfo::compressionCapability();
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
    if ($auto_convert && in_array($convert_format, ['jpg', 'png'], true) && empty($capability['gd']) && empty($capability['imagick'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => strtoupper($convert_format) . ' 转换需要 GD 或 ImageMagick',
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
    $updated = \LitePic\Core\Config::write([
        'AUTO_COMPRESS_ON_UPLOAD' => $auto_compress ? 'true' : 'false',
        'AUTO_CONVERT_ON_UPLOAD' => $auto_convert ? 'true' : 'false',
        'AUTO_CONVERT_WEBP_ON_UPLOAD' => $auto_webp ? 'true' : 'false',
        'AUTO_CONVERT_AVIF_ON_UPLOAD' => $auto_avif ? 'true' : 'false',
        'CONVERT_PREFERRED_FORMAT' => $convert_format,
        'WATERMARK_ENABLED' => $watermark_enabled ? 'true' : 'false',
    ]);

    if (!$updated) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '保存上传处理设置失败，请检查 .env 写入权限',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $current_compression_mode = \LitePic\Service\Image\ImageFormat::compressionMode();
    $current_compression_labels = [
        'imagemagick' => 'ImageMagick',
        'gd' => 'GD 压缩',
        'tinypng' => 'TinyPNG 压缩',
    ];
    $current_compression_label = $current_compression_labels[$current_compression_mode] ?? 'ImageMagick';
    $messages = [];
    $messages[] = $auto_compress ? '上传后自动压缩已开启' : '上传后自动压缩已关闭';
    $messages[] = $auto_convert ? ('上传后自动转换已开启：' . strtoupper($convert_format)) : '上传后自动转换已关闭';
    if ($changed === 'watermark') {
        $messages[] = $watermark_enabled ? '上传后自动加水印已开启' : '上传后自动加水印已关闭';
    }

    echo json_encode([
        'success' => true,
        'message' => implode('；', $messages),
        'settings' => [
            'auto_compress_on_upload' => $auto_compress,
            'auto_convert_on_upload' => $auto_convert,
            'auto_convert_webp_on_upload' => $auto_webp,
            'auto_convert_avif_on_upload' => $auto_avif,
            'convert_preferred_format' => $convert_format,
            'watermark_enabled' => $watermark_enabled,
            'conversion_label' => $auto_convert ? strtoupper($convert_format) : '关闭',
            'compression_label' => $auto_compress ? $current_compression_label : '关闭',
            'watermark_label' => $watermark_enabled ? '开启' : '关闭',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$page_title = '图片上传';

// 前端上传大小校验与后端一致（受 PHP 与系统配置共同约束）
$effective_max_upload_bytes = (new \LitePic\Service\Upload\UploadService())->maxBytes();
$compression_mode = \LitePic\Service\Image\ImageFormat::compressionMode();
$compression_mode_labels = [
    'imagemagick' => 'ImageMagick',
    'gd' => 'GD 压缩',
    'tinypng' => 'TinyPNG 压缩',
];
$compression_label = $compression_mode_labels[$compression_mode] ?? 'ImageMagick';
$compression_enabled = AUTO_COMPRESS_ON_UPLOAD;
$conversion_enabled = AUTO_CONVERT_WEBP_ON_UPLOAD || AUTO_CONVERT_AVIF_ON_UPLOAD;
$conversion_enabled = defined('AUTO_CONVERT_ON_UPLOAD') ? AUTO_CONVERT_ON_UPLOAD : $conversion_enabled;
$conversion_format = \LitePic\Service\Image\ImageFormat::targetLabel((string)CONVERT_PREFERRED_FORMAT);
$tinypng_active_count = count((new \LitePic\Repository\CompressionKeyRepository())->active());
$tinypng_mode_enabled = $compression_enabled && $compression_mode === 'tinypng';
$upload_mime_map = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'heic' => 'image/heic',
    'heif' => 'image/heif',
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
        <!-- 顶部工具栏单行布局:
                左:相册选择   右:3 个 toggle(压缩 / 转换 / 水印)
             两边自动 wrap;窄屏会折叠成两行 -->
        <div class="upload-header upload-toolbar-row" data-upload-processing-controls data-csrf-token="<?= htmlspecialchars(\LitePic\Core\Csrf::token()) ?>" data-convert-format="<?= htmlspecialchars(strtolower($conversion_format)) ?>">
            <!-- 左:相册选择(上传时直接把图片归入指定相册;留空 = 仅入全局图库) -->
            <div class="upload-album-picker">
                <label for="albumPicker" class="upload-album-picker-label">
                    <i class="fa-light fa-rectangle-history" aria-hidden="true"></i>
                    <span>上传到相册</span>
                </label>
                <select id="albumPicker" data-album-picker class="upload-album-picker-select">
                    <option value="">— 不加入相册 —</option>
                    <?php foreach ($_albums_for_picker as $_a): ?>
                        <option value="<?= htmlspecialchars($_a['key']) ?>"><?= htmlspecialchars($_a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="upload-album-picker-new" data-album-new-trigger title="新建相册" aria-label="新建相册">
                    <i class="fa-light fa-plus" aria-hidden="true"></i>
                </button>
            </div>

            <!-- 右:3 个 toggle -->
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
                <label class="upload-setting-toggle <?= WATERMARK_ENABLED ? 'is-active' : '' ?>" for="uploadAutoWatermarkToggle">
                    <span class="upload-setting-main">
                        <i class="fa-light fa-stamp"></i>
                        <span>水印</span>
                    </span>
                    <strong data-upload-watermark-value><?= WATERMARK_ENABLED ? '开启' : '关闭' ?></strong>
                    <input
                        id="uploadAutoWatermarkToggle"
                        type="checkbox"
                        data-upload-setting-toggle="watermark"
                        <?= WATERMARK_ENABLED ? 'checked' : '' ?>>
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
                        data-max-files="<?= (int)(defined('UPLOAD_MAX_FILES') ? UPLOAD_MAX_FILES : 100) ?>"
                        data-max-concurrent="<?= (int)(defined('UPLOAD_MAX_CONCURRENT') ? UPLOAD_MAX_CONCURRENT : 3) ?>"
                        data-auto-compress="<?= AUTO_COMPRESS_ON_UPLOAD ? '1' : '0' ?>"
                        data-auto-convert="<?= $conversion_enabled ? '1' : '0' ?>"
                        data-convert-format="<?= htmlspecialchars(strtolower($conversion_format)) ?>"
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
                            <span class="hint">原图先上传，缩略图 / 压缩 / 转换 / 水印随后排队处理</span>
                        </div>
                    </label>
                </div>
            </form>

            <!-- 支持格式 — 低调放在 dropZone 底部,不抢视觉焦点 -->
            <div class="drop-zone-formats" aria-label="支持的图片格式">
                <?php foreach (ALLOWED_UPLOAD_TYPES as $ext): ?>
                    <span class="drop-zone-format">.<?= strtoupper(htmlspecialchars($ext)) ?></span>
                <?php endforeach; ?>
            </div>
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

    <?php
    $recent_repo = new \LitePic\Repository\ImageRepository();
    $recent_info = new \LitePic\Service\Image\ImageInfo($recent_repo);
    $recent_rows = [];
    foreach ($recent_repo->listIdentifiersSafe() as $image) {
        $row = $recent_info->getSafe((string)$image);
        if ($row) {
            $recent_rows[] = $row;
        }
        if (count($recent_rows) >= 5) {
            break;
        }
    }

    $recent_last_label = '暂无上传';
    if (!empty($recent_rows)) {
        $recent_last = $recent_rows[count($recent_rows) - 1];
        $recent_last_ts = (int)($recent_last['time'] ?? 0);
        if ($recent_last_ts > 0) {
            $recent_last_label = '最后一张 ' . date('Y-m-d H:i', $recent_last_ts);
        }
    }
    ?>
    <!-- 最近上传预览 -->
    <section class="recent-uploads">
        <div class="recent-header">
            <h3>
                <i class="fa-light fa-clock-rotate-left"></i>
                <span>最近上传</span>
                <small class="recent-time-tag" data-recent-time><?= htmlspecialchars($recent_last_label, ENT_QUOTES, 'UTF-8') ?></small>
            </h3>
            <a href="/gallery" class="view-all">
                <span>查看全部</span>
                <i class="fa-light fa-arrow-right"></i>
            </a>
        </div>
        <div class="upload-grid">
            <?php
            foreach ($recent_rows as $row):
                echo (new \LitePic\View\ImageCard($row, false, false, false))->render();
            endforeach;
            ?>
            <?php if (empty($recent_rows)): ?>
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

/* ============== 新建相册弹窗(/upload 页) ==============
   点 picker 旁边的 [+] 弹一个迷你表单(名称 + 可见性 + 可选密码)。
   提交 → POST /api/v1/albums → 成功后把新 album 添加到下拉、自动选中、
   关闭弹窗。整个流程不离开 /upload 页。 */
(function () {
    const trigger = document.querySelector('[data-album-new-trigger]');
    if (!trigger) return;

    const picker = document.querySelector('[data-album-picker]');
    const csrf = window.CSRF_TOKEN
              || document.querySelector('input[name="csrf_token"]')?.value
              || '';

    const escapeAttr = (s) => String(s ?? '').replace(/[&<>"']/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    const formHtml = `
        <form class="album-quick-form" novalidate>
            <div class="album-quick-field">
                <label for="albumNewName">相册名称 <span style="color:#d73a49">*</span></label>
                <input id="albumNewName" name="name" type="text" maxlength="80" required
                       autocomplete="off" placeholder="例：夏日合集">
            </div>
            <div class="album-quick-field">
                <label for="albumNewVisibility">可见性</label>
                <select id="albumNewVisibility" name="visibility">
                    <option value="public">公开 — 任何人可访问</option>
                    <option value="unlisted">不公开 — 凭链接访问</option>
                    <option value="password">密码保护</option>
                    <option value="private">仅自己</option>
                </select>
            </div>
            <div class="album-quick-field" data-album-new-password hidden>
                <label for="albumNewPassword">访问密码</label>
                <input id="albumNewPassword" name="password" type="password" maxlength="80"
                       autocomplete="new-password" placeholder="至少 4 位">
            </div>
            <p class="album-quick-error" data-album-new-error hidden></p>
            <div class="album-quick-actions">
                <button type="button" class="album-quick-btn album-quick-btn-cancel" data-album-new-cancel>取消</button>
                <button type="submit" class="album-quick-btn album-quick-btn-submit">
                    <i class="fa-light fa-plus"></i><span>创建相册</span>
                </button>
            </div>
        </form>
    `;

    const open = () => {
        if (!window.ImgEt?.DialogManager?.showCustomDialog) {
            // 退路:DialogManager 没就绪就跳到独立编辑页
            window.location.href = '/albums/new';
            return;
        }
        ImgEt.DialogManager.showCustomDialog('新建相册', formHtml);

        // showCustomDialog 不返回元素 —— 用 selector 抓最后一个 .custom-dialog
        const dialogs = document.querySelectorAll('.custom-dialog');
        const dialog = dialogs[dialogs.length - 1];
        if (!dialog) return;

        const form = dialog.querySelector('.album-quick-form');
        const nameInput = dialog.querySelector('#albumNewName');
        const visSelect = dialog.querySelector('#albumNewVisibility');
        const pwdField = dialog.querySelector('[data-album-new-password]');
        const pwdInput = dialog.querySelector('#albumNewPassword');
        const cancelBtn = dialog.querySelector('[data-album-new-cancel]');
        const submitBtn = dialog.querySelector('.album-quick-btn-submit');
        const errBox = dialog.querySelector('[data-album-new-error]');

        setTimeout(() => nameInput?.focus(), 50);

        // 可见性切换 → 显示/隐藏密码字段
        visSelect?.addEventListener('change', () => {
            if (pwdField) pwdField.hidden = visSelect.value !== 'password';
        });

        cancelBtn?.addEventListener('click', () => dialog.closeHandler?.());

        const showError = (msg) => {
            if (!errBox) return;
            errBox.textContent = msg || '';
            errBox.hidden = !msg;
        };

        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            showError('');

            const name = (nameInput?.value || '').trim();
            const visibility = visSelect?.value || 'public';
            const password = pwdInput?.value || '';

            if (!name) { showError('请填写相册名称'); nameInput?.focus(); return; }
            if (visibility === 'password' && password.length < 4) {
                showError('密码至少 4 位');
                pwdInput?.focus();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.querySelector('span').textContent = '创建中...';

            try {
                const payload = { name, visibility };
                if (visibility === 'password') payload.password = password;

                const res = await fetch('/api/v1/albums', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin',
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.status !== 'success') {
                    throw new Error(data.message || `创建失败 (${res.status})`);
                }

                // 把新 album 加到下拉,自动选中。
                // key = slug 优先,无 slug 时降级到数字 id —— 这跟 PHP 端
                // AlbumService::urlKey() 同一逻辑,picker.value 直接就是 fetch
                // 目标的 <key> 段,无需后端再二次解析。
                const album = data.album;
                const albumKey = album ? (album.slug || (album.id ? String(album.id) : '')) : '';
                if (picker && albumKey) {
                    const opt = document.createElement('option');
                    opt.value = albumKey;
                    opt.textContent = album.name;
                    opt.selected = true;
                    picker.appendChild(opt);
                    picker.value = albumKey;
                    picker.dispatchEvent(new Event('change', { bubbles: true }));
                }

                window.ImgEt?.Utils?.showNotification?.(
                    `相册「${album?.name || name}」已创建,本次上传将归入此相册`,
                    'success'
                );

                dialog.closeHandler?.();
            } catch (err) {
                console.error('Album create error:', err);
                showError(err.message || '创建失败');
                submitBtn.disabled = false;
                submitBtn.querySelector('span').textContent = '创建相册';
            }
        });
    };

    trigger.addEventListener('click', open);
})();
</script>

<?php
// 加载页脚
require_once APP_ROOT . '/footer.php';

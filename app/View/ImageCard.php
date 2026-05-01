<?php
declare(strict_types=1);

namespace LitePic\View;

use LitePic\Core\Format;

/**
 * Renders the image card markup used in the gallery / upload result list.
 *
 * Constructor flags toggle which UI affordances appear: WebP/AVIF/
 * compress action buttons, and the multi-select checkbox.
 */
class ImageCard {
    private array $info;
    private bool $show_webp;
    private bool $show_comp;
    private bool $show_select;

    public function __construct(array $info, bool $show_webp = false, bool $show_comp = false, bool $show_select = false) {
        $this->info = array_merge([
            'filename' => '',
            'size' => 0,
            'dimensions' => '0×0',
            'time' => time(),
            'url' => ''
        ], $info);

        $this->show_webp = $show_webp;
        $this->show_comp = $show_comp;
        $this->show_select = $show_select;
    }

    public function render(): string {
        $card_class = $this->show_select ? 'img-card' : 'img-box';
        ob_start();
        ?>
        <?php
        // 给右键菜单准备的数据 — 各种衍生 URL 在 PHP 端算好，
        // JS 不用拼路径就能直接做下载链接。
        $rc_filename     = (string)$this->info['filename'];
        $rc_url          = (string)$this->info['url'];
        $rc_thumb_url    = (string)($this->info['thumb_url'] ?? '');
        $rc_has_thumb    = !empty($this->info['has_thumbnail']) || $rc_thumb_url !== '';
        $rc_has_webp     = !empty($this->info['has_webp']);
        $rc_has_avif     = !empty($this->info['has_avif']);
        // WebP / AVIF 衍生 URL：把扩展名替换掉。
        $rc_webp_url = $rc_has_webp ? preg_replace('/\.[a-z0-9]+$/i', '.webp', $rc_url) : '';
        $rc_avif_url = $rc_has_avif ? preg_replace('/\.[a-z0-9]+$/i', '.avif', $rc_url) : '';
        $rc_ext = strtolower(pathinfo($rc_filename, PATHINFO_EXTENSION));
        $rc_can_convert = in_array($rc_ext, ['jpg', 'jpeg', 'png', 'gif'], true);
        $rc_preferred = defined('CONVERT_PREFERRED_FORMAT') ? (string)CONVERT_PREFERRED_FORMAT : 'webp';
        ?>
        <div class="<?= $card_class ?>"
             data-filename="<?= htmlspecialchars($rc_filename) ?>"
             data-size="<?= $this->info['size'] ?>"
             data-date="<?= $this->info['time'] ?>"
             data-url="<?= htmlspecialchars($rc_url) ?>"
             data-thumb-url="<?= htmlspecialchars($rc_thumb_url) ?>"
             data-webp-url="<?= htmlspecialchars((string)$rc_webp_url) ?>"
             data-avif-url="<?= htmlspecialchars((string)$rc_avif_url) ?>"
             data-has-thumb="<?= $rc_has_thumb ? '1' : '0' ?>"
             data-has-webp="<?= $rc_has_webp ? '1' : '0' ?>"
             data-has-avif="<?= $rc_has_avif ? '1' : '0' ?>"
             data-can-convert="<?= $rc_can_convert ? '1' : '0' ?>"
             data-preferred-format="<?= htmlspecialchars($rc_preferred) ?>">
            
            <!-- 图片预览 -->
            <?= $this->renderPreview() ?>

            <!-- 图片信息 -->
            <?= $this->show_select ? $this->renderInfo() : '' ?>

            <!-- 操作按钮 -->
            <?= $this->renderActions() ?>

            <!-- 选择框 -->
            <?= $this->show_select ? $this->renderSelect() : '' ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderPreview(): string {
        ob_start();
        $preview_url = (string)($this->info['thumb_url'] ?? $this->info['url']);
        $original_url = (string)($this->info['url'] ?? $preview_url);
        if ($this->show_select) {
            ?>
            <div class="img-preview">
                <img src="<?= htmlspecialchars($preview_url) ?>" 
                     data-original-url="<?= htmlspecialchars($original_url) ?>"
                     alt="<?= htmlspecialchars($this->info['filename']) ?>"
                     loading="lazy">
                <div class="img-time-overlay">
                    <i class="fa-light fa-clock"></i>
                    <?= date('Y-m-d H:i', $this->info['time']) ?>
                </div>
            </div>
            <?php
        } else {
            ?>
            <img src="<?= htmlspecialchars($preview_url) ?>" 
                 data-original-url="<?= htmlspecialchars($original_url) ?>"
                 alt="<?= htmlspecialchars($this->info['filename']) ?>"
                 loading="lazy">
            <?php
        }
        return (string)ob_get_clean();
    }

    private function renderInfo(): string {
        $raw_name = (string)($this->info['original_name'] ?? $this->info['filename']);
        $display_name = (string)pathinfo($raw_name, PATHINFO_FILENAME);
        if ($display_name === '') {
            $display_name = (string)pathinfo((string)$this->info['filename'], PATHINFO_FILENAME);
        }
        $format_code = strtoupper(trim((string)($this->info['format'] ?? '')));
        if ($format_code === '') {
            $format_ext = strtolower((string)pathinfo((string)$this->info['filename'], PATHINFO_EXTENSION));
            $format_code = $format_ext !== '' ? strtoupper($format_ext) : 'FILE';
        }
        $format_names = [
            'JPG' => 'JPEG',
            'JPEG' => 'JPEG',
            'PNG' => 'PNG',
            'WEBP' => 'WebP',
            'AVIF' => 'AVIF',
            'GIF' => 'GIF 动图',
            'SVG' => 'SVG',
            'ICO' => 'ICON',
            'BMP' => 'BMP 位图',
            'TIFF' => 'TIFF',
            'TIF' => 'TIFF',
            'FILE' => '文件',
        ];
        $format_label = $format_names[$format_code] ?? $format_code;
        $format_class = 'fmt-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $format_code) ?? 'file');

        ob_start();
        ?>
        <div class="img-info">
            <div class="img-name-row">
                <div class="img-name" title="<?= htmlspecialchars($raw_name) ?>">
                    <?= htmlspecialchars($display_name) ?>
                </div>
            </div>
            <div class="img-meta">
                <span class="img-meta-badge img-dimensions img-dimensions-badge" title="图片尺寸">
                    <i class="fa-light fa-expand"></i>
                    <span><?= htmlspecialchars((string)$this->info['dimensions']) ?></span>
                </span>
                <span class="img-meta-badge img-size img-size-badge" title="文件大小">
                    <i class="fa-light fa-hard-drive"></i>
                    <span class="img-size-value"><?= Format::filesize($this->info['size']) ?></span>
                </span>
                <span class="img-meta-badge img-format-tag <?= htmlspecialchars($format_class) ?>" title="图片格式">
                    <i class="fa-light fa-file-image"></i>
                    <span><?= htmlspecialchars($format_label) ?></span>
                </span>
            </div>

        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function renderActions(): string {
        ob_start();
        $preferred = CONVERT_PREFERRED_FORMAT;
        ?>
        <div class="<?= $this->show_select ? 'img-actions' : 'img-overlay' ?>">
            <button class="action-btn copy-btn"
                    title="复制链接"
                    type="button">
                <i class="fa-light fa-copy"></i>
            </button>
            
            <?php 
            $ext = strtolower(pathinfo($this->info['filename'], PATHINFO_EXTENSION));
            $canCompress = in_array($ext, ['jpg','jpeg','png']);
            $canConvert = in_array($ext, ['jpg','jpeg','png','gif']);
            ?>

            <?php if ($this->show_comp && $canCompress): ?>
            <button class="action-btn compress-btn"
                    title="压缩图片"
                    type="button">
                <i class="fa-light fa-compress"></i>
            </button>
            <?php endif; ?>
            
            <?php if ($this->show_webp && $canConvert): ?>
            <?php if ($preferred === 'avif'): ?>
            <button class="action-btn avif-btn"
                    title="转换AVIF"
                    type="button">
                <i class="fa-light fa-image"></i>
            </button>
            <?php else: ?>
            <button class="action-btn webp-btn"
                    title="转换WebP"
                    type="button">
                <i class="fa-light fa-image"></i>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            
            <button class="action-btn delete-btn"
                    title="删除图片"
                    type="button">
                <i class="fa-light fa-trash"></i>
            </button>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function renderSelect(): string {
        ob_start();
        ?>
        <div class="img-select">
            <input type="checkbox" 
                   class="select-img" 
                   name="selected_images[]"
                   value="<?= htmlspecialchars($this->info['filename']) ?>">
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

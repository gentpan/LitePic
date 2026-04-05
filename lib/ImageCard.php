<?php
declare(strict_types=1);

/**
 * 图片卡片组件
 * 
 * @param array  $info        图片信息数组
 * @param bool   $show_webp   是否显示WebP转换按钮
 * @param bool   $show_comp   是否显示压缩按钮 
 * @param bool   $show_select 是否显示选择框
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
        <div class="<?= $card_class ?>" 
             data-filename="<?= htmlspecialchars($this->info['filename']) ?>"
             data-size="<?= $this->info['size'] ?>"
             data-date="<?= $this->info['time'] ?>"
             data-url="<?= htmlspecialchars($this->info['url']) ?>">
            
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
        ?>
        <div class="<?= $this->show_select ? 'img-preview' : '' ?>">
            <img src="<?= htmlspecialchars($preview_url) ?>" 
                 data-original-url="<?= htmlspecialchars($original_url) ?>"
                 alt="<?= htmlspecialchars($this->info['filename']) ?>"
                 loading="lazy">
        </div>
        <?php
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
            'GIF' => 'GIF 动图',
            'SVG' => 'SVG 矢量',
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
                <span class="img-format-tag <?= htmlspecialchars($format_class) ?>"><?= htmlspecialchars($format_label) ?></span>
            </div>
            <div class="img-meta">
                <span class="img-size" title="文件大小">
                    <i class="fa-light fa-hard-drive"></i>
                    <?= format_filesize($this->info['size']) ?>
                </span>
                <span class="img-dimensions" title="图片尺寸">
                    <i class="fa-light fa-expand"></i>
                    <?= $this->info['dimensions'] ?>
                </span>
                <span class="img-time" title="上传时间">
                    <i class="fa-light fa-clock"></i>
                    <?= date('Y-m-d H:i', $this->info['time']) ?>
                </span>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function renderActions(): string {
        ob_start();
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
            $canWebp = in_array($ext, ['jpg','jpeg','png']); 
            ?>

            <?php if ($this->show_comp && $canCompress): ?>
            <button class="action-btn compress-btn" 
                    title="压缩图片"
                    type="button">
                <i class="fa-light fa-compress"></i>
            </button>
            <?php endif; ?>
            
            <?php if ($this->show_webp && $canWebp): ?>
            <button class="action-btn webp-btn" 
                    title="转换WebP"
                    type="button">
                <i class="fa-light fa-image"></i>
            </button>
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

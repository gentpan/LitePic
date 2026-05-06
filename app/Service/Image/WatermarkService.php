<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Burns a text or PNG watermark into the source file (destructive — the
 * original is overwritten).
 *
 * Layout pipeline:
 *   1. Compute the bounding box for the text/image given padding + margin
 *   2. Place it via `boxPosition()` honouring WATERMARK_POSITION
 *   3. Optionally draw a frosted-glass panel under it (Gaussian blur +
 *      semi-transparent overlay) when WATERMARK_PANEL_ENABLED
 *   4. Render the text (TTF preferred for unicode) or composite the PNG
 *   5. Save back to the source path with the original MIME
 *
 * Image-resource creation is delegated to the legacy
 * `\LitePic\Service\Image\ConversionService::createImageResource()` for now — that lives in the conversion
 * pipeline and will move when ConversionService gets physical.
 */
final class WatermarkService
{
    private const SUPPORTED_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function isEnabled(): bool
    {
        return defined('WATERMARK_ENABLED') && WATERMARK_ENABLED;
    }

    public function canWatermark(string $ext): bool
    {
        return in_array(strtolower($ext), self::SUPPORTED_EXTS, true);
    }

    /**
     * Save an uploaded font (.ttf/.otf) or PNG-image asset under
     * data/watermarks/ and return the absolute path. Returns null
     * (with `$error` set) if validation fails. PNG uploads also get a
     * MIME re-check against the file body to catch extension spoofing.
     */
    public static function storeUploadedAsset(string $field, array $allowedExtensions, ?string &$error = null): ?string
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return null;
        }
        $file = $_FILES[$field];
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) return null;
        if ($errorCode !== UPLOAD_ERR_OK) {
            $error = '上传文件失败，请检查 PHP 上传限制';
            return null;
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? '');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            $error = '上传格式不支持';
            return null;
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = '上传临时文件无效';
            return null;
        }
        if ($ext === 'png') {
            $info = @getimagesize($tmp);
            if (!is_array($info) || (string)($info['mime'] ?? '') !== 'image/png') {
                $error = 'PNG 水印文件无效';
                return null;
            }
        }

        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $dir = $appRoot . '/data/watermarks';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = '水印资源目录不可写';
            return null;
        }
        $prefix = $ext === 'png' ? 'image' : 'font';
        $target = $dir . '/' . $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $target)) {
            $error = '保存上传文件失败';
            return null;
        }
        return $target;
    }

    /**
     * @return array{enabled:bool, attempted:bool, applied:bool, skip_reason:?string}
     */
    public function apply(string $filename): array
    {
        $result = [
            'enabled' => $this->isEnabled(),
            'attempted' => false,
            'applied' => false,
            'skip_reason' => null,
        ];
        if (!$result['enabled']) {
            $result['skip_reason'] = 'disabled';
            return $result;
        }

        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!$this->canWatermark($ext)) {
            $result['skip_reason'] = 'unsupported_format';
            return $result;
        }

        $path = PathService::resolveFilePath($filename);
        if (!is_file($path)) {
            $result['skip_reason'] = 'missing_file';
            return $result;
        }

        $info = @getimagesize($path);
        if (!is_array($info)) {
            $result['skip_reason'] = 'invalid_image';
            return $result;
        }

        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        $mime = (string)($info['mime'] ?? '');
        if ($width < 80 || $height < 40) {
            $result['skip_reason'] = 'image_too_small';
            return $result;
        }

        $image = \LitePic\Service\Image\ConversionService::createImageResource($path, $mime);
        if (!$image) {
            $result['skip_reason'] = 'resource_failed';
            return $result;
        }

        $result['attempted'] = true;
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $opacity = max(1, min(100, (int)WATERMARK_OPACITY));
        [$r, $g, $b] = self::hexToRgb((string)WATERMARK_COLOR);
        $color = self::allocateAlpha($image, $r, $g, $b, $opacity);
        $shadow = self::allocateAlpha($image, 0, 0, 0, max(1, min(76, $opacity - 16)));
        if ($color === false || $shadow === false) {
            self::destroyImage($image);
            $result['skip_reason'] = 'color_failed';
            return $result;
        }

        $margin = max(0, (int)WATERMARK_MARGIN);
        $padding = max(0, (int)(defined('WATERMARK_PANEL_PADDING') ? WATERMARK_PANEL_PADDING : 0));
        $type = self::watermarkType();

        $skipReason = $type === 'image'
            ? self::applyImageOverlay($image, $width, $height, $opacity, $margin, $padding)
            : self::applyTextOverlay($image, $width, $height, $color, $shadow, $margin, $padding);

        if ($skipReason !== null) {
            self::destroyImage($image);
            $result['skip_reason'] = $skipReason;
            return $result;
        }

        $saved = self::saveImage($image, $path, $mime);
        self::destroyImage($image);

        if (!$saved) {
            $result['skip_reason'] = 'save_failed';
            return $result;
        }
        clearstatcache(true, $path);
        $result['applied'] = true;
        return $result;
    }

    private static function applyImageOverlay($image, int $width, int $height, int $opacity, int $margin, int $padding): ?string
    {
        $watermarkPath = trim((string)(defined('WATERMARK_IMAGE_PATH') ? WATERMARK_IMAGE_PATH : ''));
        $hasImage = $watermarkPath !== ''
            && is_file($watermarkPath)
            && function_exists('imagecreatefrompng')
            && strtolower((string)pathinfo($watermarkPath, PATHINFO_EXTENSION)) === 'png';
        if (!$hasImage) return 'watermark_image_missing';

        $watermark = imagecreatefrompng($watermarkPath);
        if (!$watermark) return 'watermark_image_failed';

        imagealphablending($watermark, true);
        imagesavealpha($watermark, true);

        $pngW = imagesx($watermark);
        $pngH = imagesy($watermark);
        $maxW = min((int)WATERMARK_IMAGE_WIDTH, max(24, (int)floor($width * 0.38)));
        $targetW = min($pngW, $maxW);
        $targetH = (int)round($pngH * ($targetW / max(1, $pngW)));
        $boxW = $targetW + ($padding * 2);
        $boxH = $targetH + ($padding * 2);
        [$boxX, $boxY] = self::boxPosition($width, $height, $boxW, $boxH, $margin);
        self::drawFrostedPanel($image, $boxX, $boxY, $boxW, $boxH);
        $copied = self::copyPngWithOpacity(
            $image, $watermark,
            $boxX + $padding, $boxY + $padding,
            $targetW, $targetH, $opacity
        );
        self::destroyImage($watermark);
        return $copied ? null : 'watermark_image_copy_failed';
    }

    private static function applyTextOverlay($image, int $width, int $height, $color, $shadow, int $margin, int $padding): ?string
    {
        $text = trim((string)WATERMARK_TEXT);
        if ($text === '') return 'empty_text';
        $hasNonAscii = preg_match('/[^\x20-\x7E]/', $text) === 1;

        $fontPath = self::resolveFontPath();
        $hasTtf = $fontPath !== '' && is_file($fontPath)
            && function_exists('imagettfbbox') && function_exists('imagettftext');

        if ($hasTtf) {
            $fontSize = max(8, min((int)WATERMARK_FONT_SIZE, max(8, (int)floor($width / 10))));
            $box = imagettfbbox($fontSize, 0, $fontPath, $text);
            if (!is_array($box)) return 'font_box_failed';
            $textW = abs((int)$box[2] - (int)$box[0]);
            $textH = abs((int)$box[7] - (int)$box[1]);
            $boxW = $textW + ($padding * 2);
            $boxH = $textH + ($padding * 2);
            [$boxX, $boxY] = self::boxPosition($width, $height, $boxW, $boxH, $margin);
            self::drawFrostedPanel($image, $boxX, $boxY, $boxW, $boxH);
            $x = $boxX + $padding;
            $y = $boxY + $padding + $textH;
            imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $shadow, $fontPath, $text);
            imagettftext($image, $fontSize, 0, $x, $y, $color, $fontPath, $text);
            return null;
        }

        if ($hasNonAscii) return 'font_required_for_unicode';

        $font = 5;
        $textW = imagefontwidth($font) * strlen($text);
        $textH = imagefontheight($font);
        $boxW = $textW + ($padding * 2);
        $boxH = $textH + ($padding * 2);
        [$boxX, $boxY] = self::boxPosition($width, $height, $boxW, $boxH, $margin);
        self::drawFrostedPanel($image, $boxX, $boxY, $boxW, $boxH);
        $x = $boxX + $padding;
        $y = $boxY + $padding;
        imagestring($image, $font, $x + 1, $y + 1, $text, $shadow);
        imagestring($image, $font, $x, $y, $text, $color);
        return null;
    }

    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = 'ffffff';
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function defaultFontPath(): string
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $candidates = [
            $appRoot . '/data/watermarks/Ubuntu-Regular.ttf',
            $appRoot . '/data/watermarks/Ubuntu-R.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-Regular.ttf',
            '/usr/share/fonts/ubuntu/Ubuntu-R.ttf',
            '/usr/local/share/fonts/Ubuntu-Regular.ttf',
            '/Library/Fonts/Ubuntu-R.ttf',
            '/Library/Fonts/Ubuntu-Regular.ttf',
        ];
        foreach ($candidates as $path) {
            // System font paths (/usr/share/fonts, /Library/Fonts) are usually
            // outside open_basedir on shared hosts and is_file() emits a
            // warning even with @ on some PHP builds. Suppress the warning.
            if (@is_file($path)) return $path;
        }
        return '';
    }

    public static function resolveFontPath(): string
    {
        $configured = trim((string)(defined('WATERMARK_FONT_PATH') ? WATERMARK_FONT_PATH : ''));
        if ($configured !== '' && @is_file($configured)) {
            return $configured;
        }
        return self::defaultFontPath();
    }

    private static function watermarkType(): string
    {
        $type = strtolower((string)(defined('WATERMARK_TYPE') ? WATERMARK_TYPE : 'text'));
        return in_array($type, ['text', 'image'], true) ? $type : 'text';
    }

    private static function saveImage($image, string $filepath, string $mime): bool
    {
        switch ($mime) {
            case 'image/jpeg':
                return function_exists('imagejpeg') && imagejpeg($image, $filepath, 90);
            case 'image/png':
                imagealphablending($image, false);
                imagesavealpha($image, true);
                return function_exists('imagepng') && imagepng($image, $filepath);
            case 'image/webp':
                return function_exists('imagewebp') && imagewebp($image, $filepath, 86);
            case 'image/avif':
                return function_exists('imageavif') && imageavif($image, $filepath, 82);
            default:
                return false;
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function boxPosition(int $imageW, int $imageH, int $boxW, int $boxH, int $margin): array
    {
        $position = defined('WATERMARK_POSITION') ? WATERMARK_POSITION : 'bottom-right';
        switch ($position) {
            case 'bottom-left':
                $x = $margin;
                $y = $imageH - $margin - $boxH;
                break;
            case 'top-right':
                $x = $imageW - $margin - $boxW;
                $y = $margin;
                break;
            case 'top-left':
                $x = $margin;
                $y = $margin;
                break;
            case 'center':
                $x = (int)(($imageW - $boxW) / 2);
                $y = (int)(($imageH - $boxH) / 2);
                break;
            case 'bottom-right':
            default:
                $x = $imageW - $margin - $boxW;
                $y = $imageH - $margin - $boxH;
                break;
        }
        return [max($margin, $x), max($margin, $y)];
    }

    private static function allocateAlpha($image, int $r, int $g, int $b, int $opacity)
    {
        $opacity = max(0, min(100, $opacity));
        $alpha = 127 - (int)round(127 * ($opacity / 100));
        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }

    private static function drawRoundedRect($image, int $x, int $y, int $w, int $h, int $radius, int $color): void
    {
        $radius = max(0, min($radius, (int)floor(min($w, $h) / 2)));
        if ($radius <= 0) {
            imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $color);
            return;
        }
        imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
        imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $h - $radius, $radius * 2, $radius * 2, $color);
    }

    private static function drawFrostedPanel($image, int $x, int $y, int $w, int $h): void
    {
        if (!defined('WATERMARK_PANEL_ENABLED') || !WATERMARK_PANEL_ENABLED || $w <= 0 || $h <= 0) {
            return;
        }
        $sourceW = imagesx($image);
        $sourceH = imagesy($image);
        $x = max(0, min($x, $sourceW - 1));
        $y = max(0, min($y, $sourceH - 1));
        $w = max(1, min($w, $sourceW - $x));
        $h = max(1, min($h, $sourceH - $y));

        $patch = imagecreatetruecolor($w, $h);
        if ($patch) {
            imagealphablending($patch, false);
            imagesavealpha($patch, true);
            imagecopy($patch, $image, 0, 0, $x, $y, $w, $h);
            if (defined('IMG_FILTER_GAUSSIAN_BLUR')) {
                for ($i = 0; $i < 4; $i++) {
                    imagefilter($patch, IMG_FILTER_GAUSSIAN_BLUR);
                }
            }
            imagecopy($image, $patch, $x, $y, 0, 0, $w, $h);
            self::destroyImage($patch);
        }
        $overlay = self::allocateAlpha($image, 12, 18, 28, (int)WATERMARK_PANEL_OPACITY);
        if ($overlay !== false) {
            self::drawRoundedRect($image, $x, $y, $w, $h, (int)WATERMARK_PANEL_RADIUS, $overlay);
        }
    }

    private static function copyPngWithOpacity($dst, $src, int $dstX, int $dstY, int $dstW, int $dstH, int $opacity): bool
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0 || $dstW <= 0 || $dstH <= 0) return false;

        $tmp = imagecreatetruecolor($dstW, $dstH);
        if (!$tmp) return false;

        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, $dstW, $dstH, $transparent);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        if ($opacity < 100) {
            $opacityRatio = max(0.0, min(1.0, $opacity / 100));
            for ($y = 0; $y < $dstH; $y++) {
                for ($x = 0; $x < $dstW; $x++) {
                    $rgba = imagecolorat($tmp, $x, $y);
                    $alpha = ($rgba & 0x7F000000) >> 24;
                    $visible = (127 - $alpha) * $opacityRatio;
                    $newAlpha = 127 - (int)round($visible);
                    imagesetpixel($tmp, $x, $y, ($rgba & 0x00FFFFFF) | ($newAlpha << 24));
                }
            }
        }

        imagecopy($dst, $tmp, $dstX, $dstY, 0, 0, $dstW, $dstH);
        self::destroyImage($tmp);
        return true;
    }

    private static function destroyImage($image): void
    {
        if (PHP_VERSION_ID < 80500 && is_resource($image)) {
            imagedestroy($image);
        }
    }
}

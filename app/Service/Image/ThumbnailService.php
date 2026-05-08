<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use Imagick;
use LitePic\Repository\ImageRepository;
use Throwable;

/**
 * Generates and removes thumbnails. Uses ImageMagick when present
 * (handles huge images that would blow GD's memory_limit) and falls
 * back to GD otherwise.
 *
 * Conversion functions like \LitePic\Service\Image\ConversionService::createImageResource() still live in the
 * legacy procedural layer and are reached via the global function.
 */
final class ThumbnailService
{
    private const SUPPORTED_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp', 'tiff', 'tif', 'ico'];

    private ImageRepository $images;

    public function __construct(?ImageRepository $images = null)
    {
        $this->images = $images ?? new ImageRepository();
    }

    public static function canGenerate(string $filename): bool
    {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED_EXTS, true);
    }

    /**
     * Generate a thumbnail.
     *
     * @param array<int|string,mixed>|null $info  Optional pre-computed
     *        getimagesize() result. UploadService already computed this
     *        for image-meta storage; passing it here saves a redundant
     *        file-stat + header-decode pass on the same file.
     */
    public function create(string $filename, bool $force = false, ?array $info = null): bool
    {
        if (!self::canGenerate($filename)) return false;

        $sourcePath = PathService::resolveFilePath($filename);
        if (!file_exists($sourcePath)) return false;

        $thumbPath = ImageUrl::thumbnailPath($filename);
        if (!$force && file_exists($thumbPath)) return true;

        $thumbDir = dirname($thumbPath);
        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true)) return false;

        // Caller may have already paid the getimagesize cost upstream — reuse if so.
        if ($info === null) {
            $info = @getimagesize($sourcePath);
        }
        if (!is_array($info)) return false;
        $sw = (int)($info[0] ?? 0);
        $sh = (int)($info[1] ?? 0);
        if ($sw <= 0 || $sh <= 0) return false;

        $maxW = defined('THUMBNAIL_MAX_WIDTH') ? THUMBNAIL_MAX_WIDTH : 640;
        $maxH = defined('THUMBNAIL_MAX_HEIGHT') ? THUMBNAIL_MAX_HEIGHT : 360;
        $quality = defined('THUMBNAIL_QUALITY') ? THUMBNAIL_QUALITY : 82;

        $scale = min($maxW / $sw, $maxH / $sh, 1);
        $tw = max(1, (int)floor($sw * $scale));
        $th = max(1, (int)floor($sh * $scale));

        $ok = $this->createWithImagick($sourcePath, $thumbPath, $tw, $th, $quality)
           || $this->createWithGd($sourcePath, $thumbPath, $tw, $th, (string)$info['mime'], $quality);

        if ($ok) {
            $identifier = PathService::normalizeIdentifier($filename) ?: $filename;
            if ($this->images->exists($identifier)) {
                $this->images->setFlags($identifier, ['has_thumbnail' => true]);
            }
        }
        return $ok;
    }

    public function delete(string $filename): void
    {
        $identifier = PathService::normalizeIdentifier($filename);
        $safe = $identifier !== '' ? $identifier : basename($filename);
        $thumbName = ImageUrl::thumbnailFilename($safe);

        $sourcePath = PathService::resolveFilePath($safe);
        $sourceDir = dirname($sourcePath);
        $stem = pathinfo($safe, PATHINFO_FILENAME);
        $ext = strtolower((string)pathinfo($safe, PATHINFO_EXTENSION));

        $candidates = array_unique([
            ImageUrl::thumbnailPath($safe),
            UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $thumbName,
            UPLOAD_PATH_LOCAL . 'thumbs' . DIRECTORY_SEPARATOR . $thumbName,
            $sourceDir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $thumbName,
            $sourceDir . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . $thumbName,
            $sourceDir . DIRECTORY_SEPARATOR . $stem . '.thumb.jpg',
            $ext !== '' ? $sourceDir . DIRECTORY_SEPARATOR . $stem . '.thumb.' . $ext : null,
        ]);

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }

        if ($identifier !== '' && $this->images->exists($identifier)) {
            $this->images->setFlags($identifier, ['has_thumbnail' => false]);
        }
    }

    /**
     * @return array{total:int,created:int,skipped:int,failed:int}
     */
    public function generateAll(bool $force = true): array
    {
        $images = $this->images->listIdentifiers();
        $report = ['total' => count($images), 'created' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($images as $filename) {
            $name = (string)$filename;
            if (!self::canGenerate($name)) {
                $report['skipped']++;
                continue;
            }
            if ($this->create($name, $force)) {
                $report['created']++;
            } else {
                $report['failed']++;
            }
        }
        return $report;
    }

    private function createWithImagick(string $source, string $target, int $w, int $h, int $quality): bool
    {
        if (!class_exists(Imagick::class) || $w <= 0 || $h <= 0) return false;

        try {
            $image = new Imagick();

            // 关键优化:对 JPEG 用 libjpeg 的 DCT-domain 降采样 hint。
            // setOption('jpeg:size', ...) 必须在 readImage 前设置 — libjpeg 解码时
            // 直接跳过低位 DCT 系数,而不是先解码全图再缩放。对 4032×3024 这样
            // 的手机照片可以从 ~700ms 降到 ~150ms (4-5×)。其它格式不影响。
            $sourceExt = strtolower((string)pathinfo($source, PATHINFO_EXTENSION));
            $isJpeg = in_array($sourceExt, ['jpg', 'jpeg'], true);
            if ($isJpeg) {
                // hint 给 2× 目标尺寸 — 保证 thumbnailImage 后的成品质量,
                // 同时仍跳过 ~75% 的 DCT 系数解码工作。
                $image->setOption('jpeg:size', ($w * 2) . 'x' . ($h * 2));
            }

            $image->readImage($source);
            $image->setFirstIterator();
            $frame = $image->getImage();
            $image->clear();
            $image->destroy();

            $frame->setImagePage(0, 0, 0, 0);

            // alpha removal + flatten 只对带透明通道的格式有意义 (PNG / GIF / WebP)。
            // JPEG 永远不透明,这里跑 mergeImageLayers 是纯浪费 50-150ms。
            // 用源扩展名而非 hasImageAlphaChannel 是因为后者对动图返回 true 还是
            // 需要 flatten,但 JPEG 我们 100% 知道是单层不透明。
            if (!$isJpeg) {
                $frame->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }
                $frame->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $frame->thumbnailImage($w, $h, true, true);
            $frame->setImageFormat('jpeg');
            $frame->setImageCompression(Imagick::COMPRESSION_JPEG);
            $frame->setImageCompressionQuality($quality);

            $saved = $frame->writeImage($target);
            $frame->clear();
            $frame->destroy();
            return $saved && file_exists($target);
        } catch (Throwable $e) {
            error_log('ImageMagick thumbnail failed for ' . basename($source) . ': ' . $e->getMessage());
            return false;
        }
    }

    private function createWithGd(string $source, string $target, int $w, int $h, string $mime, int $quality): bool
    {
        // Use ConversionService's GD resource factory — knows how to read
        // jpeg/png/gif/webp into a GD resource and respects memory_limit.
        $src = @\LitePic\Service\Image\ConversionService::createImageResource($source, $mime);
        if (!$src) return false;

        $sw = imagesx($src);
        $sh = imagesy($src);
        $thumb = imagecreatetruecolor($w, $h);
        if (!$thumb) {
            imagedestroy($src);
            return false;
        }
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefilledrectangle($thumb, 0, 0, $w, $h, $white);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);

        $saved = imagejpeg($thumb, $target, $quality);
        imagedestroy($src);
        imagedestroy($thumb);
        return (bool)$saved;
    }
}

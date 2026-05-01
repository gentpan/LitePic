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
    private const SUPPORTED_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

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

    public function create(string $filename, bool $force = false): bool
    {
        if (!self::canGenerate($filename)) return false;

        $sourcePath = PathService::resolveFilePath($filename);
        if (!file_exists($sourcePath)) return false;

        $thumbPath = ImageUrl::thumbnailPath($filename);
        if (!$force && file_exists($thumbPath)) return true;

        $thumbDir = dirname($thumbPath);
        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true)) return false;

        $info = @getimagesize($sourcePath);
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
            $image->readImage($source);
            $image->setFirstIterator();
            $frame = $image->getImage();
            $image->clear();
            $image->destroy();

            $frame->setImagePage(0, 0, 0, 0);
            $frame->setImageBackgroundColor('white');
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }
            $frame->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
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
        // Falls back through the legacy \LitePic\Service\Image\ConversionService::createImageResource() helper for now,
        // which knows how to read jpeg/png/gif/webp into a GD resource.
        if (!function_exists('create_image_resource')) return false;
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

<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Core\Logger;
use LitePic\Repository\ImageRepository;

/**
 * Format conversion: writes a sibling file in the target format
 * (`foo.jpg` -> `foo.jpg.webp`-equivalent path with `.webp` extension)
 * and updates the SQLite row's variant flags.
 *
 * Also owns the upload-time post-process pipeline: compress, then
 * webp/avif convert, then thumbnail. Mutually-exclusive logic between
 * compress vs. convert lives in CompressionService::autoCompressAfterUpload.
 */
final class ConversionService
{
    public function toWebp(string $filepath): bool
    {
        return self::convert(
            $filepath,
            'webp',
            (int)(defined('WEBP_QUALITY') ? WEBP_QUALITY : 80),
            'WebP'
        );
    }

    public function toAvif(string $filepath): bool
    {
        return self::convert(
            $filepath,
            'avif',
            (int)(defined('AVIF_QUALITY') ? AVIF_QUALITY : 80),
            'AVIF'
        );
    }

    public function toJpeg(string $filepath): bool
    {
        return self::convert($filepath, 'jpg', 90, 'JPG');
    }

    public function toPng(string $filepath): bool
    {
        return self::convert($filepath, 'png', 90, 'PNG');
    }

    public function toFormat(string $filepath, string $targetExt): bool
    {
        return match (ImageFormat::normalizeTarget($targetExt)) {
            'webp' => $this->toWebp($filepath),
            'avif' => $this->toAvif($filepath),
            'jpg' => $this->toJpeg($filepath),
            'png' => $this->toPng($filepath),
            default => false,
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function autoConvertWebpAfterUpload(string $filename): array
    {
        return self::autoConvert($filename, 'webp', defined('AUTO_CONVERT_WEBP_ON_UPLOAD') && AUTO_CONVERT_WEBP_ON_UPLOAD);
    }

    /**
     * @return array<string,mixed>
     */
    public function autoConvertAvifAfterUpload(string $filename): array
    {
        return self::autoConvert($filename, 'avif', defined('AUTO_CONVERT_AVIF_ON_UPLOAD') && AUTO_CONVERT_AVIF_ON_UPLOAD);
    }

    /**
     * @return array<string,mixed>
     */
    public function autoConvertPreferredAfterUpload(string $filename, ?string $target = null, ?bool $enabled = null): array
    {
        $target = $target !== null ? $target : (defined('CONVERT_PREFERRED_FORMAT') ? (string)CONVERT_PREFERRED_FORMAT : 'webp');
        $enabled = $enabled ?? (defined('AUTO_CONVERT_ON_UPLOAD') && AUTO_CONVERT_ON_UPLOAD);
        return self::autoConvert($filename, $target, $enabled);
    }

    /**
     * Compress + convert + thumbnail in one pass. Used immediately
     * after an upload. Mutually-exclusive logic (compress vs convert)
     * lives in the inner services.
     *
     * @return array{auto_compress:array,auto_convert:array,auto_webp:array,auto_avif:array}
     */
    public function runUploadPostProcess(string $filename, ?string $target = null, ?bool $convertEnabled = null): array
    {
        $compress = (new CompressionService())->autoCompressAfterUpload($filename);
        $target = ImageFormat::normalizeTarget((string)($target ?? (defined('CONVERT_PREFERRED_FORMAT') ? CONVERT_PREFERRED_FORMAT : 'webp'))) ?: 'webp';
        $convert = $this->autoConvertPreferredAfterUpload($filename, $target, $convertEnabled);
        $webp = $target === 'webp' ? $convert : ['enabled' => false];
        $avif = $target === 'avif' ? $convert : ['enabled' => false];
        self::logDebug($filename, $compress, $webp, $avif);
        return [
            'auto_compress' => $compress,
            'auto_convert' => $convert,
            'auto_webp' => $webp,
            'auto_avif' => $avif,
        ];
    }

    /**
     * Save a GD image resource to disk in the file's native format.
     * Note: also calls imagedestroy() on the resource (matches legacy).
     */
    public static function saveImageWithType($image, string $filepath, string $mime): void
    {
        switch ($mime) {
            case 'image/jpeg':
                imagejpeg($image, $filepath, 90);
                break;
            case 'image/png':
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image, $filepath);
                break;
            case 'image/gif':
                imagegif($image, $filepath);
                break;
        }
        imagedestroy($image);
    }

    /**
     * Heuristic memory check before reading a (potentially huge) image
     * into a GD resource — refuses if the estimated allocation would
     * push us past memory_limit (with an 8MB safety margin).
     */
    public static function canAllocateMemoryForImage(string $filepath, string $mime): bool
    {
        $info = @getimagesize($filepath);
        if (!is_array($info)) return true;
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w <= 0 || $h <= 0) return true;

        // GD treats images as RGBA truecolor internally; 1.8x for intermediate buffers.
        $estimated = (int)($w * $h * 4 * 1.8);
        $limit = \LitePic\Service\Upload\UploadService::iniSizeToBytes((string)ini_get('memory_limit'));
        if ($limit <= 0) return true; // -1 or unparseable means unlimited
        $used = (int)memory_get_usage(true);
        $safety = 8 * 1024 * 1024;
        return ($used + $estimated + $safety) < $limit;
    }

    /**
     * Open a GD resource for the given MIME — caller is responsible for
     * imagedestroy(). Returns null when the format isn't supported by
     * the current GD build, or when the image would blow memory_limit.
     */
    public static function createImageResource(string $filepath, string $mime)
    {
        if (!self::canAllocateMemoryForImage($filepath, $mime)) {
            Logger::warning('Skip image resource create due to memory limit', [
                'file' => basename($filepath),
                'mime' => $mime,
                'memory_limit' => ini_get('memory_limit'),
                'memory_used' => memory_get_usage(true),
            ]);
            return null;
        }

        switch ($mime) {
            case 'image/jpeg':
                return function_exists('imagecreatefromjpeg') ? imagecreatefromjpeg($filepath) : null;
            case 'image/png':
                if (!function_exists('imagecreatefrompng')) return null;
                $source = imagecreatefrompng($filepath);
                if ($source) {
                    imagepalettetotruecolor($source);
                    imagealphablending($source, true);
                    imagesavealpha($source, true);
                }
                return $source;
            case 'image/gif':
                return function_exists('imagecreatefromgif') ? imagecreatefromgif($filepath) : null;
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($filepath) : null;
            case 'image/avif':
                return function_exists('imagecreatefromavif') ? imagecreatefromavif($filepath) : null;
            default:
                return null;
        }
    }

    private static function convert(string $filepath, string $targetExt, int $quality, string $label): bool
    {
        if (!file_exists($filepath)) {
            error_log("Conversion error: 原始文件不存在 ({$filepath})");
            return false;
        }

        $info = @getimagesize($filepath);
        if (!is_array($info)) {
            error_log("Conversion error: 无法获取图片信息 ({$filepath})");
            return false;
        }
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        $mime = (string)($info['mime'] ?? '');

        // 绝对上限：超大图（如 200MP+）直接跳过，避免一张图把整个 PHP-FPM
        // 进程的内存吃光导致 worker 被 OOM kill。默认 100MP，可在 .env 调。
        $maxPixels = defined('IMAGE_PROCESS_MAX_PIXELS') ? (int)IMAGE_PROCESS_MAX_PIXELS : 100_000_000;
        if ($maxPixels > 0 && $w > 0 && $h > 0 && ($w * $h) > $maxPixels) {
            Logger::warning('Skip conversion: image exceeds IMAGE_PROCESS_MAX_PIXELS', [
                'file' => basename($filepath), 'target' => $label,
                'width' => $w, 'height' => $h, 'pixels' => $w * $h, 'max' => $maxPixels,
            ]);
            return false;
        }

        $targetPath = preg_replace('/\.(jpg|jpeg|png|gif|webp|avif|heic|heif|bmp|tiff|tif|ico)$/i', '.' . $targetExt, $filepath);
        if (!is_string($targetPath) || $targetPath === '' || $targetPath === $filepath) {
            error_log("Conversion error: 无法确定 {$label} 输出路径");
            return false;
        }

        // 决定走哪个引擎 — 由 CONVERSION_ENGINE 设置控制：
        //   auto    : Imagick 优先（处理大图省内存），不可用回退 GD
        //   imagick : 强制 Imagick，不可用就直接失败（不偷偷回退到 GD）
        //   gd      : 强制 GD（兼容性好但 30MP 以上易爆内存）
        $engine = defined('CONVERSION_ENGINE') ? CONVERSION_ENGINE : 'auto';
        $tryImagick = ($engine === 'imagick' || $engine === 'auto') && self::imagickSupports($targetExt);
        $tryGd = ($engine === 'gd' || $engine === 'auto');

        if ($tryImagick && self::convertWithImagick($filepath, $targetPath, $targetExt, $quality, $label)) {
            self::recordVariant($filepath, $targetPath, $targetExt);
            return true;
        }

        // 强制 imagick 模式下，Imagick 失败 = 直接报错
        if ($engine === 'imagick') {
            error_log("Conversion error: CONVERSION_ENGINE=imagick 但转换失败 ({$filepath}) — 检查 Imagick 是否装了 libwebp / libheif");
            return false;
        }

        // GD fallback — 老服务器没装 Imagick / Imagick 不支持目标格式时
        if (!$tryGd) {
            error_log("Conversion error: 当前 CONVERSION_ENGINE={$engine} 但 GD 路径已禁用");
            return false;
        }
        if (!self::gdSupports($targetExt)) {
            error_log("Conversion error: 当前环境不支持 {$label}（Imagick 路径也不可用）");
            return false;
        }
        $source = self::createImageResource($filepath, $mime);
        if (!$source) {
            error_log("Conversion error: 无法创建 GD 图片资源 ({$filepath}) — 可能内存不足，建议在「设置 → 图片处理」把转换引擎切到 Imagick");
            return false;
        }

        $ok = self::writeGdImage($source, $targetPath, $targetExt, $quality);
        imagedestroy($source);
        if (!$ok) {
            error_log("{$label} generation failed via GD for {$filepath}");
            return false;
        }

        self::recordVariant($filepath, $targetPath, $targetExt);
        return true;
    }

    /**
     * 写完变体后把元数据登记到 images 表 — 抽出来让 GD 和 Imagick 两条路径
     * 共用同一份"成功后处理"代码。
     */
    private static function recordVariant(string $filepath, string $targetPath, string $targetExt): void
    {
        $repo = new ImageRepository();
        $originalIdentifier = PathService::identifierFromPath($filepath) ?? basename($filepath);
        $original = $repo->originalNameFor($originalIdentifier) ?? basename($filepath);
        $variantIdentifier = PathService::identifierFromPath($targetPath) ?? basename($targetPath);
        $repo->recordOriginalName($variantIdentifier, $original);
        if ($targetExt === 'webp' || $targetExt === 'avif') {
            $repo->setFlags($originalIdentifier, [$targetExt === 'webp' ? 'has_webp' : 'has_avif' => true]);
        }
    }

    private static function gdSupports(string $targetExt): bool
    {
        return match ($targetExt) {
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            'jpg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            default => false,
        };
    }

    private static function writeGdImage($source, string $targetPath, string $targetExt, int $quality): bool
    {
        switch ($targetExt) {
            case 'webp':
                return imagewebp($source, $targetPath, max(1, min(100, $quality)));
            case 'avif':
                return imageavif($source, $targetPath, max(1, min(100, $quality)));
            case 'jpg':
                $w = imagesx($source);
                $h = imagesy($source);
                $canvas = imagecreatetruecolor($w, $h);
                if (!$canvas) return false;
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
                $ok = imagejpeg($canvas, $targetPath, max(1, min(100, $quality)));
                imagedestroy($canvas);
                return $ok;
            case 'png':
                imagealphablending($source, false);
                imagesavealpha($source, true);
                return imagepng($source, $targetPath, 6);
            default:
                return false;
        }
    }

    /**
     * Imagick 是否可用且支持目标格式（WEBP / AVIF）。
     * queryFormats 返回的是大写格式名列表。
     */
    private static function imagickSupports(string $targetExt): bool
    {
        if (!class_exists(\Imagick::class)) return false;
        try {
            $im = new \Imagick();
            $formats = $im->queryFormats(strtoupper($targetExt));
            $im->clear();
            return !empty($formats);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Imagick-based 转换路径。处理大图远优于 GD。
     *
     *   • setResourceLimit 给 Imagick 自身的内存预算让出空间（默认 256MB
     *     一般够用），磁盘缓存兜底
     *   • setImageBackgroundColor + flatten — PNG / GIF 透明背景转 WebP
     *     需要先 flatten 否则透明像素会被填成黑色
     *   • setImageCompressionQuality 跟 GD 的 quality 参数语义一致
     *
     * 失败时尝试清理输出文件 + 返回 false 让 caller 走 GD fallback。
     */
    private static function convertWithImagick(string $filepath, string $targetPath, string $targetExt, int $quality, string $label): bool
    {
        $image = null;
        try {
            $image = new \Imagick();

            // 给 Imagick 自己的资源预算 — 256MB 内存 / 1GB 磁盘缓存
            // 内存不够时自动 swap 到磁盘临时文件，慢但能成
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);

            $image->readImage($filepath);
            // 多帧（GIF / 多页 TIFF）只取第一帧 — webp/avif 静态变体没必要多帧
            $image->setFirstIterator();

            if ($targetExt === 'jpg') {
                $image->setImageBackgroundColor('white');
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                }
                $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            } elseif ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor('transparent');
            }

            $image->setImageFormat($targetExt === 'jpg' ? 'JPEG' : strtoupper($targetExt));
            $image->setImageCompressionQuality(max(1, min(100, $quality)));

            // libwebp 特定参数 — method 4 是 quality / speed 的合理折中
            if ($targetExt === 'webp') {
                $image->setOption('webp:method', '4');
                $image->setOption('webp:lossless', 'false');
            } elseif ($targetExt === 'avif') {
                $image->setOption('heic:speed', '6');  // AVIF (libheif) speed 0=slowest/best, 8=fastest
            }

            // 写出
            $ok = $image->writeImage($targetPath);

            $image->clear();
            $image->destroy();
            $image = null;

            if (!$ok || !is_file($targetPath) || filesize($targetPath) === 0) {
                @unlink($targetPath);
                Logger::warning('Imagick conversion produced empty output', [
                    'file' => basename($filepath), 'target' => $label,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            if ($image) {
                try { $image->clear(); } catch (\Throwable $_) {}
                try { $image->destroy(); } catch (\Throwable $_) {}
            }
            @unlink($targetPath);
            Logger::warning('Imagick conversion threw — falling back to GD', [
                'file'   => basename($filepath),
                'target' => $label,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function autoConvert(string $filename, string $targetExt, bool $enabled): array
    {
        $result = [
            'enabled' => $enabled,
            'attempted' => false,
            'created' => false,
            'skip_reason' => null,
            'filename' => null,
            'url' => null,
        ];
        if (!$enabled) {
            $result['skip_reason'] = 'disabled';
            return $result;
        }

        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $supported = ImageFormat::canConvertTo($ext, $targetExt);
        if (!$supported) {
            $result['skip_reason'] = 'unsupported_format';
            return $result;
        }

        $path = PathService::resolveFilePath($filename);
        if (!file_exists($path)) {
            $result['skip_reason'] = 'missing_file';
            return $result;
        }

        $result['attempted'] = true;
        $ok = (new self())->toFormat($path, $targetExt);
        if (!$ok) {
            $result['skip_reason'] = 'convert_failed';
            return $result;
        }

        $variantPath = preg_replace('/\.(jpg|jpeg|png|gif|webp|avif|heic|heif|bmp|tiff|tif|ico)$/i', '.' . $targetExt, $path);
        if (!is_string($variantPath) || !file_exists($variantPath)) {
            $result['skip_reason'] = 'output_missing';
            return $result;
        }

        $variantFilename = PathService::identifierFromPath($variantPath) ?? basename($variantPath);
        (new ThumbnailService())->create($variantFilename, true);

        $result['created'] = true;
        $result['filename'] = $variantFilename;
        $result['url'] = ImageUrl::forIdentifier($variantFilename);
        return $result;
    }

    private static function logDebug(string $filename, array $compress, array $webp, array $avif = []): void
    {
        $original = (new \LitePic\Repository\ImageRepository())->originalNameFor($filename) ?? $filename;
        $hadFailure =
            (!empty($compress['attempted']) && empty($compress['compressed'])) ||
            (!empty($webp['attempted']) && empty($webp['created'])) ||
            (!empty($avif['attempted']) && empty($avif['created']));

        $payload = [
            'filename' => $filename,
            'original_name' => $original,
            'compress' => [
                'enabled' => (bool)($compress['enabled'] ?? false),
                'attempted' => (bool)($compress['attempted'] ?? false),
                'compressed' => (bool)($compress['compressed'] ?? false),
                'method' => $compress['method'] ?? null,
                'skip_reason' => $compress['skip_reason'] ?? null,
                'before_size' => $compress['before_size_text'] ?? null,
                'after_size' => $compress['after_size_text'] ?? null,
                'saved' => $compress['saved_size_text'] ?? null,
                'saved_percent' => $compress['saved_percent'] ?? 0,
            ],
            'webp' => [
                'enabled' => (bool)($webp['enabled'] ?? false),
                'attempted' => (bool)($webp['attempted'] ?? false),
                'created' => (bool)($webp['created'] ?? false),
                'skip_reason' => $webp['skip_reason'] ?? null,
                'filename' => $webp['filename'] ?? null,
            ],
        ];
        if (!empty($avif)) {
            $payload['avif'] = [
                'enabled' => (bool)($avif['enabled'] ?? false),
                'attempted' => (bool)($avif['attempted'] ?? false),
                'created' => (bool)($avif['created'] ?? false),
                'skip_reason' => $avif['skip_reason'] ?? null,
                'filename' => $avif['filename'] ?? null,
            ];
        }
        $hadFailure
            ? Logger::warning('Upload post-process report', $payload)
            : Logger::debug('Upload post-process report', $payload);
    }
}

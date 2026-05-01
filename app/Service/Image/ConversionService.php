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
            'imagewebp',
            80,
            'WebP'
        );
    }

    public function toAvif(string $filepath): bool
    {
        return self::convert(
            $filepath,
            'avif',
            'imageavif',
            80,
            'AVIF'
        );
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
     * Compress + convert + thumbnail in one pass. Used immediately
     * after an upload. Mutually-exclusive logic (compress vs convert)
     * lives in the inner services.
     *
     * @return array{auto_compress:array,auto_webp:array,auto_avif:array}
     */
    public function runUploadPostProcess(string $filename): array
    {
        $compress = (new CompressionService())->autoCompressAfterUpload($filename);
        $webp = $this->autoConvertWebpAfterUpload($filename);
        $avif = $this->autoConvertAvifAfterUpload($filename);
        self::logDebug($filename, $compress, $webp, $avif);
        return [
            'auto_compress' => $compress,
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
        $limit = function_exists('ini_size_to_bytes')
            ? ini_size_to_bytes((string)ini_get('memory_limit'))
            : 0;
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

    private static function convert(string $filepath, string $targetExt, string $gdFn, int $quality, string $label): bool
    {
        if (!function_exists($gdFn)) {
            error_log("Conversion error: 当前环境不支持 {$label}");
            return false;
        }
        if (!file_exists($filepath)) {
            error_log("Conversion error: 原始文件不存在 ({$filepath})");
            return false;
        }

        $info = @getimagesize($filepath);
        if (!is_array($info)) {
            error_log("Conversion error: 无法获取图片信息 ({$filepath})");
            return false;
        }
        $mime = (string)($info['mime'] ?? '');
        $source = self::createImageResource($filepath, $mime);
        if (!$source) {
            error_log("Conversion error: 无法创建图片资源 ({$filepath})");
            return false;
        }

        $targetPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.' . $targetExt, $filepath);
        if (!is_string($targetPath) || $targetPath === '') {
            imagedestroy($source);
            error_log("Conversion error: 无法确定 {$label} 输出路径");
            return false;
        }

        $ok = $gdFn($source, $targetPath, $quality);
        imagedestroy($source);
        if (!$ok) {
            error_log("{$label} generation failed for {$filepath}");
            return false;
        }

        // Mirror the original-name into the variant row + flag the parent.
        $repo = new ImageRepository();
        $originalIdentifier = PathService::identifierFromPath($filepath) ?? basename($filepath);
        $original = function_exists('get_original_filename')
            ? (get_original_filename($originalIdentifier) ?? basename($filepath))
            : basename($filepath);
        $variantIdentifier = PathService::identifierFromPath($targetPath) ?? basename($targetPath);
        if (function_exists('save_original_filename')) {
            save_original_filename($variantIdentifier, $original);
        }
        $repo->setFlags($originalIdentifier, [$targetExt === 'webp' ? 'has_webp' : 'has_avif' => true]);
        return true;
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
        $supported = $targetExt === 'webp'
            ? ImageFormat::canConvertWebp($ext)
            : ImageFormat::canConvertAvif($ext);
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
        $ok = $targetExt === 'webp'
            ? (new self())->toWebp($path)
            : (new self())->toAvif($path);
        if (!$ok) {
            $result['skip_reason'] = 'convert_failed';
            return $result;
        }

        $variantPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.' . $targetExt, $path);
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
        $original = function_exists('get_original_filename')
            ? (get_original_filename($filename) ?? $filename)
            : $filename;
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

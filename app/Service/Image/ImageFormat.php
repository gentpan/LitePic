<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Inspect image files: detect the real format from MIME, read SVG
 * dimensions, decide whether a given extension supports a given operation.
 */
final class ImageFormat
{
    private const COMPRESSIBLE = ['jpg', 'jpeg', 'png'];
    private const RASTER_CONVERTIBLE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif'];
    private const WEBP_CONVERTIBLE = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'heif'];
    private const AVIF_CONVERTIBLE = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'heif'];
    private const JPEG_CONVERTIBLE = ['png', 'gif', 'webp', 'avif', 'heic', 'heif'];
    private const PNG_CONVERTIBLE = ['jpg', 'jpeg', 'gif', 'webp', 'avif', 'heic', 'heif'];
    private const CONVERT_TARGETS = ['webp', 'avif', 'jpg', 'png'];
    /** Authoritative list of compression engines (single source of truth —
     *  SettingsController references this directly instead of keeping its
     *  own copy). */
    public const COMPRESSION_MODES = ['tinypng', 'gd', 'imagemagick'];

    private const MIME_TO_LABEL = [
        'image/jpeg' => 'JPG',
        'image/jpg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/avif' => 'AVIF',
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIF',
        'image/heic-sequence' => 'HEIC',
        'image/heif-sequence' => 'HEIF',
        'image/gif' => 'GIF',
        'image/svg+xml' => 'SVG',
        'image/x-icon' => 'ICO',
        'image/vnd.microsoft.icon' => 'ICO',
        'image/bmp' => 'BMP',
        'image/tiff' => 'TIFF',
    ];

    public static function canCompress(string $ext): bool
    {
        return in_array(strtolower($ext), self::COMPRESSIBLE, true);
    }

    public static function canConvertWebp(string $ext): bool
    {
        return in_array(strtolower($ext), self::WEBP_CONVERTIBLE, true);
    }

    public static function canConvertAvif(string $ext): bool
    {
        return in_array(strtolower($ext), self::AVIF_CONVERTIBLE, true);
    }

    public static function canConvertJpeg(string $ext): bool
    {
        return in_array(strtolower($ext), self::JPEG_CONVERTIBLE, true);
    }

    public static function canConvertPng(string $ext): bool
    {
        return in_array(strtolower($ext), self::PNG_CONVERTIBLE, true);
    }

    public static function canConvertTo(string $ext, string $targetExt): bool
    {
        $ext = strtolower($ext);
        $targetExt = self::normalizeTarget($targetExt);
        if ($targetExt === '' || $ext === $targetExt || ($targetExt === 'jpg' && $ext === 'jpeg')) {
            return false;
        }
        return match ($targetExt) {
            'webp' => self::canConvertWebp($ext),
            'avif' => self::canConvertAvif($ext),
            'jpg' => self::canConvertJpeg($ext),
            'png' => self::canConvertPng($ext),
            default => false,
        };
    }

    public static function canConvertPreferred(string $ext): bool
    {
        return self::canConvertTo($ext, defined('CONVERT_PREFERRED_FORMAT') ? (string)CONVERT_PREFERRED_FORMAT : 'webp');
    }

    public static function normalizeTarget(string $targetExt): string
    {
        $targetExt = strtolower(trim($targetExt));
        if ($targetExt === 'jpeg') $targetExt = 'jpg';
        return in_array($targetExt, self::CONVERT_TARGETS, true) ? $targetExt : '';
    }

    public static function targetLabel(string $targetExt): string
    {
        return match (self::normalizeTarget($targetExt)) {
            'jpg' => 'JPG',
            'png' => 'PNG',
            'avif' => 'AVIF',
            default => 'WebP',
        };
    }

    public static function compressionMode(): string
    {
        $mode = strtolower(trim((string)(defined('COMPRESSION_MODE') ? COMPRESSION_MODE : 'imagemagick')));
        return in_array($mode, self::COMPRESSION_MODES, true) ? $mode : 'imagemagick';
    }

    /**
     * Best-effort label for the file's actual format. Falls back to the
     * extension if MIME detection comes up empty.
     */
    public static function detectLabel(string $filepath): string
    {
        if (!is_file($filepath)) {
            return strtoupper((string)pathinfo($filepath, PATHINFO_EXTENSION));
        }

        $mime = '';
        $info = @getimagesize($filepath);
        if (is_array($info) && isset($info['mime'])) {
            $mime = strtolower((string)$info['mime']);
        } elseif (function_exists('mime_content_type')) {
            $mime = strtolower((string)@mime_content_type($filepath));
        }

        if ($mime !== '' && isset(self::MIME_TO_LABEL[$mime])) {
            return self::MIME_TO_LABEL[$mime];
        }
        $ext = strtoupper((string)pathinfo($filepath, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : 'FILE';
    }

    /**
     * Read width/height from an SVG: prefer the explicit attributes,
     * fall back to viewBox. Returns null if neither is parseable.
     *
     * @return array{width:int,height:int}|null
     */
    public static function svgDimensions(string $filepath): ?array
    {
        if (!is_file($filepath) || !is_readable($filepath)) return null;
        $content = @file_get_contents($filepath, false, null, 0, 65536);
        if (!is_string($content) || $content === '') return null;
        if (!preg_match('/<svg\b[^>]*>/i', $content, $tag)) return null;

        $tag = $tag[0];
        $attr = static function (string $name) use ($tag): ?string {
            if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*([\'"])(.*?)\1/i', $tag, $m)) {
                return trim((string)$m[2]);
            }
            return null;
        };

        $parseLen = static function (?string $value): ?int {
            if ($value === null || $value === '' || str_contains($value, '%')) return null;
            if (!preg_match('/^(-?\d+(?:\.\d+)?)([a-z]*)$/iu', trim($value), $m)) return null;
            $unit = strtolower((string)($m[2] ?? ''));
            if ($unit !== '' && $unit !== 'px') return null;
            $n = (float)$m[1];
            return $n > 0 ? (int)round($n) : null;
        };

        $w = $parseLen($attr('width'));
        $h = $parseLen($attr('height'));
        if ($w !== null && $h !== null) return ['width' => $w, 'height' => $h];

        $viewBox = $attr('viewBox');
        if ($viewBox !== null) {
            $parts = preg_split('/[\s,]+/', trim($viewBox));
            if (is_array($parts) && count($parts) >= 4) {
                $vw = (float)$parts[2];
                $vh = (float)$parts[3];
                if ($vw > 0 && $vh > 0) {
                    return ['width' => (int)round($vw), 'height' => (int)round($vh)];
                }
            }
        }
        return null;
    }
}

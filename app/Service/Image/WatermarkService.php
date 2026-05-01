<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Burns a text/image watermark into the file. Currently writes to the
 * original file; a "non-destructive" mode that overlays at serve time
 * is on the roadmap.
 */
final class WatermarkService
{
    public function isEnabled(): bool
    {
        return defined('WATERMARK_ENABLED') && WATERMARK_ENABLED;
    }

    public function canWatermark(string $ext): bool
    {
        return (bool)can_watermark_extension($ext);
    }

    public function apply(string $filename): array
    {
        return apply_watermark_to_image($filename);
    }
}

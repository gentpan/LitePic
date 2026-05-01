<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Lossy compression dispatcher. Picks the backend based on
 * `COMPRESSION_MODE` and delegates to the corresponding routine.
 *
 * The three backend implementations (ImageMagick / TinyPNG / GD)
 * still live in the legacy procedural layer for now — they're
 * intricate enough that physically moving them belongs to a follow-up
 * pass. This class is the public OO API the rest of the app should use.
 */
final class CompressionService
{
    public function compress(string $path, int $quality = 85): array
    {
        return compress_image_by_mode($path, $quality);
    }

    public function compressWithImagick(string $path, int $quality = 85): bool
    {
        return (bool)compress_with_imagemagick($path, $quality);
    }

    public function compressWithTinyPng(string $path): bool
    {
        return (bool)compress_with_tinypng($path);
    }

    public function compressWithGd(string $path, int $quality = 85): bool
    {
        return (bool)compress_with_gd($path, $quality);
    }

    /**
     * Auto-compress immediately after a successful upload (governed by
     * AUTO_COMPRESS_ON_UPLOAD).
     */
    public function autoCompressAfterUpload(string $filename): array
    {
        return auto_compress_uploaded_image($filename);
    }

    public function mode(): string
    {
        return ImageFormat::compressionMode();
    }
}

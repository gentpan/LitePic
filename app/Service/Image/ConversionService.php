<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Format conversion (JPEG/PNG/GIF -> WebP / AVIF).
 *
 * Like CompressionService, this is the OO surface; the bodies live
 * in functions.php for now. The three "auto convert after upload"
 * helpers govern what happens during the upload pipeline.
 */
final class ConversionService
{
    public function toWebp(string $filepath): bool
    {
        return (bool)convert_to_webp($filepath);
    }

    public function toAvif(string $filepath): bool
    {
        return (bool)convert_to_avif($filepath);
    }

    public function autoConvertWebpAfterUpload(string $filename): array
    {
        return auto_convert_uploaded_to_webp($filename);
    }

    public function autoConvertAvifAfterUpload(string $filename): array
    {
        return auto_convert_uploaded_to_avif($filename);
    }

    /**
     * Run the full upload-time post-processing pipeline (compress,
     * convert, watermark, thumbnail, remote sync) in one call.
     */
    public function runUploadPostProcess(string $filename): array
    {
        return run_upload_post_process($filename);
    }
}

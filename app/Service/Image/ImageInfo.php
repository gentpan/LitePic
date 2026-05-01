<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Repository\ImageRepository;

/**
 * Hydrates the data shown in the gallery card / API list response.
 *
 * Tries the SQLite-backed `images` row first; only falls back to the
 * filesystem (filemtime, getimagesize) when the row is missing the
 * relevant fields. Lazy-fills the row when it had to do filesystem work,
 * so subsequent reads stay cheap.
 */
final class ImageInfo
{
    private ImageRepository $repo;

    public function __construct(?ImageRepository $repo = null)
    {
        $this->repo = $repo ?? new ImageRepository();
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * `get()` wrapped with a swallow-and-log fallback. Used by templates
     * that need a never-throws contract.
     */
    public function getSafe(string $filename): ?array
    {
        try {
            return $this->get($filename);
        } catch (\Throwable $e) {
            error_log('Error getting image info: ' . $e->getMessage());
            return null;
        }
    }

    public function get(string $filename): ?array
    {
        $identifier = PathService::normalizeIdentifier($filename);
        if ($identifier === '') {
            $identifier = PathService::displayName($filename);
        }
        $filepath = PathService::resolveFilePath($filename);
        if (!file_exists($filepath)) {
            return null;
        }

        $row = $this->repo->find($identifier);

        $size = $row['size'] ?? 0;
        if ($size <= 0) $size = (int)@filesize($filepath);
        $time = $row['created_at'] ?? 0;
        if ($time <= 0) $time = (int)@filemtime($filepath);

        $width = (int)($row['width'] ?? 0);
        $height = (int)($row['height'] ?? 0);
        $needsDimensions = $width <= 0 || $height <= 0;

        $format = ImageFormat::detectLabel($filepath);
        if ($format === 'SVG') {
            $svg = ImageFormat::svgDimensions($filepath);
            if ($svg !== null) {
                $width = $svg['width'];
                $height = $svg['height'];
                $dimensionsLabel = $width . 'x' . $height;
            } else {
                $width = 0;
                $height = 0;
                $dimensionsLabel = '矢量图';
            }
            $needsDimensions = false;
        } elseif ($needsDimensions) {
            $info = @getimagesize($filepath);
            if (is_array($info)) {
                $width = (int)$info[0];
                $height = (int)$info[1];
            }
            $dimensionsLabel = $width . 'x' . $height;
        } else {
            $dimensionsLabel = $width . 'x' . $height;
        }

        // Lazy-fill the SQLite row with anything we just had to compute.
        if ($row !== null && ($needsDimensions || ($row['size'] ?? 0) <= 0)) {
            $this->repo->update($identifier, [
                'size' => $size,
                'width' => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,
            ]);
        }

        $original = $row['original_name'] ?? null;
        if ($original === null || $original === '') {
            $original = PathService::displayName($identifier);
        }

        $thumbUrl = ImageUrl::forIdentifier($identifier);
        if (\LitePic\Service\Image\ThumbnailService::canGenerate($identifier)
            && (new \LitePic\Service\Image\ThumbnailService())->create((string)$identifier)) {
            $thumbUrl = ImageUrl::thumbnailUrl((string)$identifier);
        }

        return [
            'filename' => $identifier,
            'original_name' => (string)$original,
            'size' => $size,
            'filesize' => self::formatBytes($size),
            'width' => $width,
            'height' => $height,
            'dimensions' => $dimensionsLabel,
            'format' => $format,
            'time' => $time,
            'url' => ImageUrl::forIdentifier($identifier),
            'thumb_url' => $thumbUrl,
            'request_count' => (int)($row['view_count'] ?? 0),
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Builds public URLs for uploaded images and their thumbnails.
 *
 * Resolution order:
 *   1. If remote storage is set up to serve traffic, return the CDN URL.
 *   2. If hotlink protection is on, route through `/i/<identifier>` so
 *      the request hits image.php and the referer check.
 *   3. Otherwise serve the file directly out of /uploads/.
 *
 * The thumbnail variant follows the same logic plus a `.thumbs/yyyy/mm/`
 * sub-path. The remote-storage helpers needed here live in the legacy
 * procedural layer for now (will move to RemoteStorageService later).
 */
final class ImageUrl
{
    public static function forIdentifier(string $filename): string
    {
        $identifier = PathService::normalizeIdentifier($filename);
        if ($identifier !== '') {
            $remote = remote_storage_public_url_for_identifier($identifier);
            if ($remote !== null) return $remote;
            return self::buildLocalUrl($identifier);
        }

        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $relative = PathService::identifierFromPath(PathService::resolveFilePath($filename));
            if ($relative !== null) {
                $remote = remote_storage_public_url_for_identifier($relative);
                if ($remote !== null) return $remote;
                return self::buildLocalUrl($relative);
            }
        }

        $display = PathService::displayName($filename);
        return self::isHotlinkProtected()
            ? rtrim(SITE_URL, '/') . '/i/' . rawurlencode($display)
            : SITE_URL . UPLOAD_PATH_WEB . $display;
    }

    public static function thumbnailFilename(string $filename): string
    {
        $stem = pathinfo(PathService::displayName($filename), PATHINFO_FILENAME);
        return $stem . '.thumb.jpg';
    }

    public static function thumbnailPath(string $filename): string
    {
        $thumb = self::thumbnailFilename($filename);
        $identifier = PathService::normalizeIdentifier($filename);

        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $relative = $identifier !== ''
                ? $identifier
                : (string)PathService::identifierFromPath(PathService::resolveFilePath($filename));
            [$year, $month] = self::yearMonth($relative);
            return UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $year
                 . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $thumb;
        }

        return UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $thumb;
    }

    public static function thumbnailUrl(string $filename): string
    {
        $thumb = self::thumbnailFilename($filename);
        $remote = remote_storage_public_url_for_local_path(self::thumbnailPath($filename));
        if ($remote !== null) return $remote;

        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $identifier = PathService::normalizeIdentifier($filename);
            $relative = $identifier !== ''
                ? $identifier
                : (string)PathService::identifierFromPath(PathService::resolveFilePath($filename));
            [$year, $month] = self::yearMonth($relative);
            return SITE_URL . UPLOAD_PATH_WEB . '.thumbs/' . $year . '/' . $month . '/' . $thumb;
        }

        return SITE_URL . UPLOAD_PATH_WEB . '.thumbs/' . $thumb;
    }

    private static function buildLocalUrl(string $identifier): string
    {
        return self::isHotlinkProtected()
            ? rtrim(SITE_URL, '/') . '/i/' . PathService::encodeForUrl($identifier)
            : SITE_URL . UPLOAD_PATH_WEB . $identifier;
    }

    private static function isHotlinkProtected(): bool
    {
        return defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function yearMonth(string $relative): array
    {
        $parts = explode('/', trim($relative, '/'));
        return [$parts[0] ?? date('Y'), $parts[1] ?? date('m')];
    }
}

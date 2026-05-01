<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Builds public URLs for uploaded images and their thumbnails.
 *
 * Resolution order:
 *   1. If remote storage is set up to serve traffic, return the CDN URL.
 *   2. If hotlink protection OR the view counter is on, route through
 *      `/i/<identifier>` so the request hits image.php (which enforces
 *      the referer check and/or increments view_count).
 *   3. Otherwise serve the file directly out of /uploads/.
 *
 * The thumbnail variant follows the same logic plus a `.thumbs/yyyy/mm/`
 * sub-path.
 */
final class ImageUrl
{
    public static function forIdentifier(string $filename): string
    {
        $identifier = PathService::normalizeIdentifier($filename);
        if ($identifier !== '') {
            $remote = (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForIdentifier($identifier);
            if ($remote !== null) return $remote;
            return self::buildLocalUrl($identifier);
        }

        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $relative = PathService::identifierFromPath(PathService::resolveFilePath($filename));
            if ($relative !== null) {
                $remote = (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForIdentifier($relative);
                if ($remote !== null) return $remote;
                return self::buildLocalUrl($relative);
            }
        }

        $display = PathService::displayName($filename);
        return self::shouldRouteThroughPhp()
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
        $remote = (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForLocalPath(self::thumbnailPath($filename));
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

    /**
     * Build the public URL for a stored identifier (e.g. "2026/05/abc.webp").
     *
     * 决策优先级：
     *   1. 防盗链 / 图片请求统计 任一开启 → 强制走 /i/ (PHP 端可校验 / 计数)
     *      — URL_PREFIX 设置在这种情况下不生效（功能 trump 美观）
     *   2. 否则按 URL_PREFIX 拼接：<URL_PREFIX><identifier>
     *      物理文件路径不变，.htaccess 里的 catch-all rewrite 会把任何单词
     *      前缀 + /yyyy/mm/file 自动指向 uploads/yyyy/mm/file
     */
    private static function buildLocalUrl(string $identifier): string
    {
        // 功能优先：防盗链 / 视图计数器开了，必须走 PHP，无视 URL_PREFIX
        if (ImageServeService::isRoutedThroughPhp()) {
            return rtrim(SITE_URL, '/') . '/i/' . PathService::encodeForUrl($identifier);
        }

        $prefix = defined('URL_PREFIX') ? URL_PREFIX : '/uploads/';
        // /i/ 前缀也走 PHP（用户主动选了代理路径）
        if ($prefix === '/i/') {
            return rtrim(SITE_URL, '/') . '/i/' . PathService::encodeForUrl($identifier);
        }
        // 其它前缀（包括 /uploads/、/、/img/、/photo/ 等）都拼接成
        // <SITE_URL><prefix><identifier>，由 Apache 直接 serve
        return rtrim(SITE_URL, '/') . $prefix . $identifier;
    }

    /**
     * True when EITHER hotlink protection OR the image view counter is
     * configured on. Both features need every public image request to
     * pass through PHP, so the URL points at the `/i/<identifier>` route.
     */
    private static function shouldRouteThroughPhp(): bool
    {
        return ImageServeService::isRoutedThroughPhp();
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

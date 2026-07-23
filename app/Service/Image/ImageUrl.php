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

        $relative = PathService::identifierFromPath(PathService::resolveFilePath($filename));
        if ($relative !== null) {
            $remote = (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForIdentifier($relative);
            if ($remote !== null) return $remote;
            return self::buildLocalUrl($relative);
        }

        $display = PathService::displayName($filename);
        $base = self::siteUrl();
        return ImageServeService::isRoutedThroughPhp()
            ? $base . '/i/' . rawurlencode($display)
            : $base . UPLOAD_PATH_WEB . $display;
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
        $relative = $identifier !== ''
            ? $identifier
            : (string)PathService::identifierFromPath(PathService::resolveFilePath($filename));
        [$year, $month] = self::yearMonth($relative);
        return UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $year
             . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $thumb;
    }

    public static function thumbnailUrl(string $filename): string
    {
        $thumb = self::thumbnailFilename($filename);
        $remote = (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForLocalPath(self::thumbnailPath($filename));
        if ($remote !== null) return $remote;

        $identifier = PathService::normalizeIdentifier($filename);
        $relative = $identifier !== ''
            ? $identifier
            : (string)PathService::identifierFromPath(PathService::resolveFilePath($filename));
        [$year, $month] = self::yearMonth($relative);
        return self::siteUrl() . UPLOAD_PATH_WEB . '.thumbs/' . $year . '/' . $month . '/' . $thumb;
    }

    /**
     * Build the public URL for a stored identifier (e.g. "2026/05/abc.webp").
     *
     * 决策优先级：
     *   1. 防盗链 / 图片请求统计 任一开启 → 强制走 /i/ (PHP 端可校验 / 计数)
     *      — URL_PREFIX 设置在这种情况下不生效（功能 trump 美观）
     *   2. 否则按 URL_PREFIX 拼接：<URL_PREFIX><identifier>
     *      物理文件路径不变，nginx try_files 会把任意公开前缀
     *      + /yyyy/mm/file 回退到 index.php，由 PHP 定位真实文件。
     */
    private static function buildLocalUrl(string $identifier): string
    {
        $base = self::siteUrl();

        // 功能优先：防盗链 / 视图计数器开了，必须走 PHP，无视 URL_PREFIX
        if (ImageServeService::isRoutedThroughPhp()) {
            return $base . '/i/' . PathService::encodeForUrl($identifier);
        }

        $prefix = defined('URL_PREFIX') ? URL_PREFIX : '/uploads/';
        // /i/ 前缀也走 PHP（用户主动选了代理路径）
        if ($prefix === '/i/') {
            return $base . '/i/' . PathService::encodeForUrl($identifier);
        }
        // 其它前缀（包括 /uploads/、/、/img/、/photo/ 等）都拼接成
        // <SITE_URL><prefix><identifier>，由 nginx try_files / PHP fallback 解析。
        return $base . $prefix . $identifier;
    }

    private static function siteUrl(): string
    {
        return \LitePic\Core\Config::siteUrl();
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

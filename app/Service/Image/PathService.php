<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

/**
 * Resolves the family of names a single uploaded image goes by:
 *   - identifier: relative path under uploads/ (the canonical key,
 *     e.g. "2026/04/abc.jpg")
 *   - absolute path: identifier prefixed with UPLOAD_PATH_LOCAL
 *   - public URL: SITE_URL + UPLOAD_PATH_WEB + identifier (or the
 *     hotlink-protected /i/ form, or the remote CDN form)
 *
 * All path-handling code in the rest of the app should go through
 * here so the rules for identifier validation are one-source-of-truth.
 */
final class PathService
{
    /**
     * Strip / normalise a possibly-untrusted identifier. Returns '' if any
     * path component is empty, '.', or '..' — the caller is then expected
     * to reject the request.
     */
    public static function normalizeIdentifier(string $identifier): string
    {
        $normalized = ltrim(trim(str_replace('\\', '/', $identifier)), '/');
        if ($normalized === '') return '';

        $safe = [];
        foreach (explode('/', $normalized) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                return '';
            }
            $safe[] = $part;
        }
        return implode('/', $safe);
    }

    public static function identifierFromPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', UPLOAD_PATH_LOCAL), '/') . '/';
        if (!str_starts_with($normalized, $base)) {
            return null;
        }
        $relative = self::normalizeIdentifier(substr($normalized, strlen($base)));
        return $relative === '' ? null : $relative;
    }

    public static function displayName(string $identifier): string
    {
        $normalized = self::normalizeIdentifier($identifier);
        return $normalized === '' ? basename($identifier) : basename($normalized);
    }

    public static function encodeForUrl(string $identifier): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($identifier, '/'))));
    }

    /**
     * Generate a unique filename for an upload. Uses a date-prefixed path
     * when STORAGE_TYPE === 'date'.
     */
    public static function generateFilename(string $ext): string
    {
        $ext = strtolower($ext);
        do {
            $candidate = uniqid() . '_' . random_int(100, 999) . '.' . $ext;
        } while (file_exists(self::resolveFilePath($candidate)));
        return $candidate;
    }

    /**
     * Resolve the absolute filesystem path for an identifier.
     *
     * If the identifier is a basename only (no slashes), falls back to a
     * glob-search across yyyy/mm/ directories — preserves the legacy
     * behaviour where bare filenames could still be resolved.
     */
    public static function resolveFilePath(string $filename): string
    {
        $normalized = self::normalizeIdentifier($filename);
        if ($normalized !== '') {
            return UPLOAD_PATH_LOCAL . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }

        $base = basename($filename);
        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $safe = str_replace(['*', '?', '[', ']'], '', $base);
            $matches = glob(UPLOAD_PATH_LOCAL . '[0-9][0-9][0-9][0-9]/[0-1][0-9]/' . $safe);
            if ($matches) {
                foreach ($matches as $path) {
                    $n = str_replace('\\', '/', $path);
                    if (str_contains($n, '/.thumbs/')) continue;
                    if (preg_match('#/(\d{4})/(0[1-9]|1[0-2])/#', $n)) return $path;
                }
            }
        }
        return UPLOAD_PATH_LOCAL . $base;
    }

    /**
     * Today's storage directory (the place new uploads land).
     */
    public static function todaysStoragePath(): string
    {
        return self::storagePathByTimestamp(time());
    }

    public static function storagePathByTimestamp(int $timestamp): string
    {
        if (defined('STORAGE_TYPE') && STORAGE_TYPE === 'date') {
            $ts = $timestamp > 0 ? $timestamp : time();
            $path = UPLOAD_PATH_LOCAL . date('Y', $ts) . DIRECTORY_SEPARATOR . date('m', $ts) . DIRECTORY_SEPARATOR;
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            return $path;
        }
        return UPLOAD_PATH_LOCAL;
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

use LitePic\Service\Hotlink\HotlinkProtection;
use LitePic\Service\Image\PathService;

/**
 * Tail-scans web server access logs to count per-image request hits.
 *
 * Caches results in `data/access_log_stats_cache.json`. Disabled by
 * default; turn on with ACCESS_LOG_STATS_ENABLED=true. Configure the
 * log file paths via ACCESS_LOG_PATHS, the cache TTL via
 * ACCESS_LOG_CACHE_TTL, and the per-file scan budget via
 * ACCESS_LOG_MAX_BYTES.
 *
 * Counts both `/uploads/...` direct hits and `/i/...` proxied hits.
 */
final class AccessLogStats
{
    /** @var array<string,mixed>|null Process-local cache (per-request). */
    private static ?array $memoryCache = null;

    public function isEnabled(): bool
    {
        return defined('ACCESS_LOG_STATS_ENABLED') && ACCESS_LOG_STATS_ENABLED;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(bool $force = false): array
    {
        if (!$force && is_array(self::$memoryCache)) {
            return self::$memoryCache;
        }

        $enabled = $this->isEnabled();
        $paths = self::resolvedPaths();
        $cacheFile = self::cacheFile();
        $cacheTtl = defined('ACCESS_LOG_CACHE_TTL') ? (int)ACCESS_LOG_CACHE_TTL : 300;
        $maxBytes = defined('ACCESS_LOG_MAX_BYTES') ? (int)ACCESS_LOG_MAX_BYTES : 20971520;
        $cacheKey = sha1((string)json_encode([
            $enabled,
            $paths,
            $maxBytes,
            defined('SITE_URL') ? SITE_URL : '',
            defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED,
        ], JSON_UNESCAPED_SLASHES));

        if (!$force && is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $cached = is_string($raw) ? json_decode($raw, true) : null;
            if (
                is_array($cached) &&
                ($cached['cache_key'] ?? '') === $cacheKey &&
                time() - (int)($cached['generated_at'] ?? 0) <= $cacheTtl
            ) {
                $cached['from_cache'] = true;
                self::$memoryCache = $cached;
                return $cached;
            }
        }

        $stats = [
            'enabled' => $enabled,
            'paths' => $paths,
            'readable_paths' => [],
            'unreadable_paths' => [],
            'scanned_lines' => 0,
            'matched_requests' => 0,
            'total_requests' => 0,
            'images' => [],
            'top' => [],
            'truncated' => false,
            'max_bytes' => $maxBytes,
            'generated_at' => time(),
            'cache_key' => $cacheKey,
            'from_cache' => false,
        ];

        if (!$enabled) {
            self::$memoryCache = $stats;
            return $stats;
        }

        foreach ($paths as $path) {
            $fileStats = self::scanFile($path, $maxBytes);
            if (!empty($fileStats['readable'])) {
                $stats['readable_paths'][] = [
                    'path' => $path,
                    'size' => (int)($fileStats['size'] ?? 0),
                    'truncated' => !empty($fileStats['truncated']),
                ];
            } else {
                $stats['unreadable_paths'][] = $path;
                continue;
            }

            $stats['scanned_lines'] += (int)($fileStats['scanned_lines'] ?? 0);
            $stats['matched_requests'] += (int)($fileStats['matched_requests'] ?? 0);
            $stats['truncated'] = $stats['truncated'] || !empty($fileStats['truncated']);

            foreach (($fileStats['images'] ?? []) as $identifier => $count) {
                $stats['images'][$identifier] = (int)($stats['images'][$identifier] ?? 0) + (int)$count;
            }
        }

        arsort($stats['images']);
        $stats['total_requests'] = array_sum(array_map('intval', $stats['images']));
        foreach (array_slice($stats['images'], 0, 20, true) as $identifier => $count) {
            $stats['top'][] = [
                'filename' => (string)$identifier,
                'request_count' => (int)$count,
                'url' => function_exists('get_img_url') ? get_img_url((string)$identifier) : '',
                'original_name' => function_exists('get_original_filename')
                    ? (get_original_filename((string)$identifier) ?? PathService::displayName((string)$identifier))
                    : PathService::displayName((string)$identifier),
            ];
        }

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        self::$memoryCache = $stats;
        return $stats;
    }

    public function imageRequestCount(string $filename, ?array $cached = null): int
    {
        $identifier = PathService::normalizeIdentifier($filename);
        if ($identifier === '') return 0;
        $stats = $cached ?? $this->get();
        $images = is_array($stats['images'] ?? null) ? $stats['images'] : [];
        return (int)($images[$identifier] ?? 0);
    }

    public static function cacheFile(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/data/access_log_stats_cache.json';
    }

    /**
     * @return array<int, string>
     */
    public static function resolvedPaths(): array
    {
        $paths = (defined('ACCESS_LOG_PATHS') && is_array(ACCESS_LOG_PATHS)) ? ACCESS_LOG_PATHS : [];
        if ($paths === []) {
            $paths = self::defaultPaths();
        }
        $unique = [];
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path !== '') $unique[$path] = true;
        }
        return array_keys($unique);
    }

    /**
     * @return array<int, string>
     */
    private static function defaultPaths(): array
    {
        return [
            (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/logs/access.log',
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            '/usr/local/nginx/logs/access.log',
            '/www/wwwlogs/' . HotlinkProtection::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? '')) . '.log',
        ];
    }

    public static function uriToImageIdentifier(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $uri;
        }
        $path = rawurldecode($path);

        $uploadWeb = defined('UPLOAD_PATH_WEB') ? UPLOAD_PATH_WEB : '/uploads/';
        if (str_starts_with($path, $uploadWeb)) {
            $identifier = substr($path, strlen($uploadWeb));
        } elseif (str_starts_with($path, '/i/')) {
            $identifier = substr($path, 3);
        } else {
            return null;
        }

        $identifier = PathService::normalizeIdentifier($identifier);
        if ($identifier === '' || str_contains($identifier, '.thumbs/')) {
            return null;
        }

        $allowed = defined('ALLOWED_TYPES') ? ALLOWED_TYPES : [];
        $ext = strtolower((string)pathinfo($identifier, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return null;
        }

        return $identifier;
    }

    public static function parseLine(string $line): ?string
    {
        if (!preg_match(
            '/"(?P<method>GET|HEAD)\s+(?P<uri>\S+)\s+HTTP\/[0-9.]+"\s+(?P<status>\d{3})\b/i',
            $line,
            $m
        )) {
            return null;
        }
        $status = (int)($m['status'] ?? 0);
        if ($status < 200 || $status >= 400) return null;
        return self::uriToImageIdentifier((string)($m['uri'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    public static function scanFile(string $path, int $maxBytes): array
    {
        $result = [
            'path' => $path,
            'readable' => false,
            'truncated' => false,
            'size' => 0,
            'scanned_lines' => 0,
            'matched_requests' => 0,
            'images' => [],
        ];
        if (!is_file($path) || !is_readable($path)) return $result;

        $size = (int)@filesize($path);
        $result['readable'] = true;
        $result['size'] = $size;
        $result['truncated'] = $size > $maxBytes;

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            $result['readable'] = false;
            return $result;
        }
        if ($size > $maxBytes) {
            @fseek($handle, -$maxBytes, SEEK_END);
            fgets($handle); // skip the (likely-partial) first line
        }
        while (($line = fgets($handle)) !== false) {
            $result['scanned_lines']++;
            $identifier = self::parseLine($line);
            if ($identifier === null) continue;
            $result['matched_requests']++;
            $result['images'][$identifier] = (int)($result['images'][$identifier] ?? 0) + 1;
        }
        fclose($handle);
        return $result;
    }
}

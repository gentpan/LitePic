<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

/**
 * Parses the web server access log to count per-image hits. Cached on
 * disk (TTL controlled by ACCESS_LOG_CACHE_TTL).
 */
final class AccessLogStats
{
    public function isEnabled(): bool
    {
        return defined('ACCESS_LOG_STATS_ENABLED') && ACCESS_LOG_STATS_ENABLED;
    }

    public function get(bool $force = false): array
    {
        return get_access_log_stats($force);
    }

    public function imageRequestCount(string $filename, ?array $cached = null): int
    {
        return get_image_request_count($filename, $cached);
    }
}

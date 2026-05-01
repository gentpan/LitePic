<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

use LitePic\Repository\ImageRepository;

/**
 * Aggregate counts shown in the footer / dashboard widgets.
 *
 * Reads straight from the SQLite images table — no file cache needed
 * since SUM(size) is a few microseconds on the indexed column.
 *
 * The legacy `data/footer_stats_cache.json` is still written so any
 * external tooling that reads it doesn't break, but it's no longer the
 * source of truth.
 */
final class FooterStats
{
    private ImageRepository $images;

    public function __construct(?ImageRepository $images = null)
    {
        $this->images = $images ?? new ImageRepository();
    }

    public function imageCount(): int
    {
        return $this->images->totalCount();
    }

    public function totalSize(): int
    {
        return $this->images->totalSize();
    }

    /**
     * One-shot snapshot for templates. The $ttl param is kept for
     * source-compat with the legacy procedural API but is ignored —
     * SQLite is fast enough that there's no need to cache.
     *
     * @return array{image_count:int,total_size:int}
     */
    public function snapshot(int $ttl = 45): array
    {
        $snapshot = [
            'image_count' => $this->imageCount(),
            'total_size' => $this->totalSize(),
        ];

        // Keep the legacy on-disk cache in lock-step for any caller that
        // still reads it directly (planning to delete entirely once we've
        // verified nothing external reads it).
        @file_put_contents(self::cacheFile(), json_encode([
            'ts' => time(),
            'image_count' => $snapshot['image_count'],
            'total_size' => $snapshot['total_size'],
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $snapshot;
    }

    public static function cacheFile(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/data/footer_stats_cache.json';
    }
}

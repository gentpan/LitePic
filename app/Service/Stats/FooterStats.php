<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

use LitePic\Repository\ImageRepository;

/**
 * Aggregate counts shown in the footer / dashboard widgets.
 *
 * Reads straight from the SQLite images table, with a short-TTL cache
 * to amortise the SUM(size) across many concurrent page loads.
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
     * Cached snapshot for templates that want everything at once.
     *
     * @return array{image_count:int,total_size:int}
     */
    public function snapshot(int $ttl = 45): array
    {
        // Defer to the legacy cached implementation for now so we keep
        // the on-disk JSON cache compatible with anything that still reads it.
        $stats = get_footer_stats_cached($ttl);
        return [
            'image_count' => (int)($stats['image_count'] ?? 0),
            'total_size' => (int)($stats['total_size'] ?? 0),
        ];
    }
}

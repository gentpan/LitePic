<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

use LitePic\Repository\ImageRepository;

/**
 * Aggregate counts shown in the footer / dashboard widgets.
 *
 * Reads straight from the SQLite images table — no file cache needed
 * since SUM(size) is a few microseconds on the indexed column.
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
        return [
            'image_count' => $this->imageCount(),
            'total_size' => $this->totalSize(),
        ];
    }
}

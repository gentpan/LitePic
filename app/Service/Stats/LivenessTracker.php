<?php
declare(strict_types=1);

namespace LitePic\Service\Stats;

use LitePic\Core\Database;

/**
 * Per-minute liveness sampler + uptime-strip data source.
 *
 * Two surfaces:
 *   - {@see recordOnce()} — called from bootstrap on every request, fires
 *     a single INSERT OR IGNORE per minute. Static guard makes it cheap
 *     even when called repeatedly inside one request.
 *   - {@see series()} — read-side. Returns an array shaped for the
 *     uptime strip widget. The displayed strip is based on server OS
 *     uptime, not web-request sampling, so it reflects the machine's
 *     real boot time.
 *
 * Status semantics:
 *   - up       = 100% of expected pings present
 *   - partial  = some pings present but < 100%
 *   - down     = no pings present in a window we expected to see them
 *   - future   = window hasn't elapsed yet (don't paint as down)
 *   - no_data  = uptime source unavailable
 */
final class LivenessTracker
{
    /** Supported range presets. */
    public const RANGES = ['1h', '1d', '30d', '90d'];

    private static bool $recorded = false;

    /**
     * Bucket a unix timestamp to the start of its minute.
     */
    public static function bucketMinute(int $ts): int
    {
        return (int)(floor($ts / 60) * 60);
    }

    /**
     * Record one ping for the current minute. Safe to call multiple times
     * per request — only the first call hits the DB.
     *
     * 这里不做随机抽样：UPTIME 条的粒度就是分钟,INSERT OR IGNORE
     * 会把同一分钟的并发请求压成一条记录。这样低访问量后台也不会
     * 因为抽样没命中而出现“假离线”。
     */
    public static function recordOnce(): void
    {
        if (self::$recorded) return;
        self::$recorded = true;

        try {
            $bucket = self::bucketMinute(time());
            Database::connection()
                ->prepare('INSERT OR IGNORE INTO liveness_pings (bucket_at) VALUES (:b)')
                ->execute([':b' => $bucket]);
        } catch (\Throwable $_) {
            // Best-effort — never break a request because uptime tracking failed.
        }
    }

    /**
     * Build the uptime series for an admin-facing range.
     *
     * @param string $range  one of {@see RANGES}
     * @return array{
     *   range:string, start_at:int, end_at:int,
     *   overall_percent:float,
     *   segments: array<int, array{at:int, until:int, percent:float, status:string}>
     * }
     */
    public function series(string $range = '1d'): array
    {
        if (!in_array($range, self::RANGES, true)) {
            $range = '1d';
        }

        [$segmentSeconds, $segmentCount, $bucketsPerSegment] = self::rangeShape($range);
        $now = time();
        $endAt = self::alignToBoundary($now, $segmentSeconds);
        $startAt = $endAt - ($segmentCount * $segmentSeconds);

        $serverUptime = (new ServerInfo())->uptimeSeconds();
        if ($serverUptime !== null && $serverUptime >= 0) {
            return $this->seriesFromServerUptime($range, $startAt, $endAt, $segmentSeconds, $segmentCount, $now, $serverUptime);
        }

        // Pull all pings in the window in one query — cheap (ranged scan on PK).
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT bucket_at FROM liveness_pings
              WHERE bucket_at >= :s AND bucket_at < :e'
        );
        $stmt->execute([':s' => $startAt, ':e' => $endAt + $segmentSeconds]);
        $present = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $b) {
            $present[(int)$b] = true;
        }

        // Find the first-ever ping so we can mark windows before it as no_data
        // instead of falsely flagging them as downtime.
        $firstEverStmt = $pdo->query('SELECT MIN(bucket_at) FROM liveness_pings');
        $firstEver = $firstEverStmt ? (int)($firstEverStmt->fetchColumn() ?: 0) : 0;

        // Build segments
        $segments = [];
        $totalPings = 0;
        $totalExpected = 0;
        for ($i = 0; $i < $segmentCount; $i++) {
            $segStart = $startAt + ($i * $segmentSeconds);
            $segEnd = $segStart + $segmentSeconds;

            $expected = $bucketsPerSegment;
            $hasFutureBuckets = $segEnd > $now;
            if ($hasFutureBuckets) {
                // Trim the expected count for the live segment to only the
                // minutes that have actually elapsed.
                $elapsed = max(0, $now - $segStart);
                $expected = max(1, (int)floor($elapsed / 60));
            }

            // Count pings in this segment
            $hits = 0;
            for ($b = $segStart; $b < $segEnd && $b < $now; $b += 60) {
                if (isset($present[$b])) $hits++;
            }

            // Decide status
            if ($firstEver > 0 && $segEnd <= $firstEver) {
                $status = 'no_data';
                $percent = 0.0;
            } elseif ($segStart >= $now) {
                $status = 'future';
                $percent = 0.0;
            } elseif ($expected === 0) {
                $status = 'no_data';
                $percent = 0.0;
            } else {
                $percent = round(($hits / $expected) * 100, 2);
                if ($percent >= 100) {
                    $status = 'up';
                } elseif ($percent <= 0) {
                    $status = 'down';
                } else {
                    $status = 'partial';
                }
            }

            $segments[] = [
                'at'      => $segStart,
                'until'   => $segEnd,
                'percent' => $percent,
                'status'  => $status,
            ];

            // Only count finished, post-firstEver segments toward the overall.
            if ($status !== 'future' && $status !== 'no_data') {
                $totalPings += $hits;
                $totalExpected += $expected;
            }
        }

        $overall = $totalExpected > 0
            ? round(($totalPings / $totalExpected) * 100, 2)
            : 0.0;

        return [
            'range'           => $range,
            'start_at'        => $startAt,
            'end_at'          => min($endAt, $now),
            'overall_percent' => $overall,
            'segments'        => $segments,
        ];
    }

    /**
     * Build strip segments from OS uptime. A segment is up for the portion
     * after the current boot timestamp; time before boot is treated as down.
     *
     * @return array{
     *   range:string, start_at:int, end_at:int,
     *   overall_percent:float,
     *   segments: array<int, array{at:int, until:int, percent:float, status:string}>
     * }
     */
    private function seriesFromServerUptime(
        string $range,
        int $startAt,
        int $endAt,
        int $segmentSeconds,
        int $segmentCount,
        int $now,
        int $serverUptime
    ): array {
        $bootAt = $now - $serverUptime;
        $segments = [];
        $totalUp = 0;
        $totalExpected = 0;

        for ($i = 0; $i < $segmentCount; $i++) {
            $segStart = $startAt + ($i * $segmentSeconds);
            $segEnd = $segStart + $segmentSeconds;
            $effectiveEnd = min($segEnd, $now);

            if ($segStart >= $now) {
                $status = 'future';
                $percent = 0.0;
            } elseif ($effectiveEnd <= $segStart) {
                $status = 'no_data';
                $percent = 0.0;
            } else {
                $expected = $effectiveEnd - $segStart;
                $upSeconds = max(0, $effectiveEnd - max($segStart, $bootAt));
                $percent = $expected > 0 ? round(($upSeconds / $expected) * 100, 2) : 0.0;

                if ($percent >= 100) {
                    $status = 'up';
                } elseif ($percent <= 0) {
                    $status = 'down';
                } else {
                    $status = 'partial';
                }

                $totalUp += $upSeconds;
                $totalExpected += $expected;
            }

            $segments[] = [
                'at'      => $segStart,
                'until'   => $segEnd,
                'percent' => $percent,
                'status'  => $status,
            ];
        }

        $overall = $totalExpected > 0
            ? round(($totalUp / $totalExpected) * 100, 2)
            : 0.0;

        return [
            'range'           => $range,
            'start_at'        => $startAt,
            'end_at'          => min($endAt, $now),
            'overall_percent' => $overall,
            'segments'        => $segments,
        ];
    }

    /**
     * @return array{0:int, 1:int, 2:int}  [segmentSeconds, segmentCount, expectedBucketsPerSegment]
     */
    private static function rangeShape(string $range): array
    {
        return match ($range) {
            '1h'  => [60,    60, 1],     // 60 segments × 1 min, 1 minute-bucket per segment
            '1d'  => [3600,  24, 60],    // 24 segments × 1 hour, 60 minute-buckets per segment
            '30d' => [86400, 30, 1440],  // 30 segments × 1 day, 1440 minute-buckets per segment
            '90d' => [86400, 90, 1440],  // 90 segments × 1 day, 1440 minute-buckets per segment
            default => [3600, 24, 60],
        };
    }

    /**
     * Round $ts UP to the next multiple of $boundary so the bar's right
     * edge sits on a clean tick (next minute / hour / day).
     */
    private static function alignToBoundary(int $ts, int $boundary): int
    {
        return (int)(ceil($ts / $boundary) * $boundary);
    }
}

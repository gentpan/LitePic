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
 *     uptime strip widget: a list of segments, an overall percentage,
 *     and the start/end timestamps of the window.
 *
 * Status semantics:
 *   - up       = 100% of expected pings present
 *   - partial  = some pings present but < 100%
 *   - down     = no pings present in a window we expected to see them
 *   - future   = window hasn't elapsed yet (don't paint as down)
 *   - no_data  = window starts before our first recorded ping (gray)
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
     * 性能注:这里用 1/8 抽样写入,把每请求一次的写竞争降到 ~12.5%。
     * uptime 桶的分辨率本身就是「分钟」,只要 60 秒内有 1 个请求落桶,
     * 这个分钟就被标活;一分钟内通常远不止 8 个请求,抽样不会丢桶。
     * 上传 burst 时 20 个并发 worker 不再都抢同一个 SQLite 写锁。
     */
    public static function recordOnce(): void
    {
        if (self::$recorded) return;
        self::$recorded = true;

        // 1/8 抽样 — 用 random_int 确保密码学级随机度(虽然这里只是写入抽样)。
        // mt_rand 也行但 random_int 更适合默认。负载下浪费的 random_int 调用
        // 微秒级,不构成新的瓶颈。
        if (random_int(1, 8) !== 1) return;

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

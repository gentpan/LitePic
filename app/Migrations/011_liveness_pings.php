<?php
declare(strict_types=1);

/**
 * Liveness ping log for the uptime strip.
 *
 * Single column: `bucket_at` is a unix timestamp aligned to the start of
 * its minute (`floor(time/60)*60`). PRIMARY KEY makes per-minute writes
 * idempotent — every PHP request fires `INSERT OR IGNORE`, which collapses
 * to one row per minute regardless of traffic.
 *
 * Read pattern: COUNT bucketed by minute / hour / day depending on the
 * uptime range the admin is viewing. ~1440 rows/day × 90 days = ~130k
 * rows max, ~1MB on disk — no pruning needed for a single-instance image
 * host. If we ever ship a hosted multi-tenant build this becomes a
 * partition-by-tenant table; for now keep it dead simple.
 *
 * Idempotent: CREATE IF NOT EXISTS.
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS liveness_pings (
            bucket_at INTEGER PRIMARY KEY
        )
    SQL);
};

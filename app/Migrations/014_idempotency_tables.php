<?php
declare(strict_types=1);

/**
 * Two small tables for v3.3.6 idempotency / abuse-throttle fixes.
 *
 *   - `telegram_seen_updates`: dedupes Telegram `update_id` so a
 *     network blip → Telegram retry doesn't double-create an album
 *     or re-attach a photo. The handler ACKs duplicates and returns.
 *
 *   - `album_visit_log`: per-(album, ip) view-count throttle backing
 *     store. The previous cookie-only check inflated counts on any
 *     incognito reload — this gives a server-side authoritative ledger
 *     keyed on (album_id, ip_hash, half-hour bucket).
 *
 * Both tables are intentionally small + auto-pruning by design:
 *   - telegram_seen_updates: worker.php DELETEs entries older than 24h
 *   - album_visit_log: worker.php DELETEs entries older than 1h
 *     (visit dedupe only needs to survive the 30-min cooldown window)
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS.
 */
return function (PDO $pdo): void {
    // ---- Telegram update_id dedupe ------------------------------------------
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS telegram_seen_updates (
            update_id INTEGER PRIMARY KEY,
            seen_at   INTEGER NOT NULL
        )
    SQL);
    // Cleanup query uses bucket_at; index lets the DELETE WHERE seen_at < X
    // be a range scan instead of full table scan.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_telegram_seen_updates_seen_at
                ON telegram_seen_updates(seen_at)');

    // ---- Album visit-count IP throttle --------------------------------------
    // (album_id, ip_hash, bucket_at) is the natural PK. INSERT OR IGNORE
    // makes "have we counted this visitor in this bucket already?" a single
    // statement: rowCount > 0 means new visit, == 0 means already counted.
    //
    // bucket_at granularity is 30 min — matches the previous cookie window.
    // ip_hash is sha1(REMOTE_ADDR + ADMIN_SESSION_SECRET) so the raw IP is
    // never stored (matches the "no PII in DB" stance of the rest of LitePic).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS album_visit_log (
            album_id  INTEGER NOT NULL,
            ip_hash   TEXT    NOT NULL,
            bucket_at INTEGER NOT NULL,
            PRIMARY KEY (album_id, ip_hash, bucket_at)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_album_visit_log_bucket_at
                ON album_visit_log(bucket_at)');
};

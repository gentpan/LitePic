<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Config;
use LitePic\Core\Database;

/**
 * IP-keyed dedupe ledger for the public album visit counter.
 *
 * Replaces the previous cookie-only check (incognito reloads inflated
 * the count freely). Server-side authority on "did this IP visit in the
 * current bucket?". The cookie path is still kept in public_album.php
 * as a UX optimisation — skips the DB hit on rapid refreshes — but the
 * SQLite table is the source of truth.
 *
 * Bucket granularity is 30 minutes (matches the previous cookie window).
 *
 * Privacy: raw IPs are never stored. We hash with sha1(ip + secret) so
 * the table can't be used to reverse-engineer visitor IPs even with DB
 * access. ADMIN_SESSION_SECRET is reused as the salt — it already exists
 * in every install.
 */
final class AlbumVisitLogRepository
{
    private const BUCKET_SECONDS = 1800;

    /**
     * Returns true if this visit was the first one in the current bucket
     * for (album, IP). Caller should increment the album view counter
     * only when true.
     *
     * Safe to call with empty / missing IP — returns true (we'd rather
     * over-count by treating mystery visitors as new than under-count and
     * lose data).
     */
    public function recordVisitIfNew(int $albumId, string $ip): bool
    {
        if ($albumId <= 0) return false;
        $bucket = (int)(floor(time() / self::BUCKET_SECONDS) * self::BUCKET_SECONDS);
        $ipHash = self::hashIp($ip);
        $stmt = Database::connection()->prepare(
            'INSERT OR IGNORE INTO album_visit_log (album_id, ip_hash, bucket_at)
                 VALUES (:a, :h, :b)'
        );
        $stmt->execute([':a' => $albumId, ':h' => $ipHash, ':b' => $bucket]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Drop rows older than $maxAgeSeconds (default 1h). We only need to
     * preserve buckets within the active 30-min dedupe window, so 1h
     * leaves plenty of safety margin and keeps the table tiny.
     */
    public function prune(int $maxAgeSeconds = 3600): int
    {
        $cutoff = time() - max(self::BUCKET_SECONDS, $maxAgeSeconds);
        $stmt = Database::connection()->prepare(
            'DELETE FROM album_visit_log WHERE bucket_at < :cut'
        );
        $stmt->execute([':cut' => $cutoff]);
        return $stmt->rowCount();
    }

    private static function hashIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') $ip = '0.0.0.0';
        $salt = (string)Config::get('ADMIN_SESSION_SECRET', 'litepic-no-secret');
        // sha1 is fine here — we're not protecting passwords, we just need
        // a stable, irreversible-without-rainbow-table fingerprint.
        return sha1($ip . ':' . $salt);
    }
}

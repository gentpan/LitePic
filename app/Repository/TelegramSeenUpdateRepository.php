<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Idempotency ledger for Telegram webhook updates.
 *
 * Telegram retries non-2xx responses for up to 24 hours, so a network
 * blip between our handler and our 200 ACK causes Telegram to redeliver
 * the same `update_id`. Without dedupe, `/newalbum My Album` on retry
 * creates a second album with the same name (photo uploads are already
 * dedupe-protected via SHA1).
 *
 * Usage:
 *   if (!$repo->markSeen($updateId)) return; // already handled — skip
 *
 * Cleanup is the worker.php cron's job — see {@see prune()}.
 */
final class TelegramSeenUpdateRepository
{
    /**
     * Atomically mark this update_id as seen. Returns true if this call
     * inserted the row (first time we've seen it), false if it was already
     * present (a retry).
     */
    public function markSeen(int $updateId): bool
    {
        if ($updateId <= 0) return true; // no update_id → can't dedupe; let it through
        $stmt = Database::connection()->prepare(
            'INSERT OR IGNORE INTO telegram_seen_updates (update_id, seen_at)
                 VALUES (:u, :t)'
        );
        $stmt->execute([':u' => $updateId, ':t' => time()]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Drop entries older than $maxAgeSeconds (default 24h, matching Telegram's
     * retry window — after that they stop trying so we can't get a duplicate
     * delivery anymore).
     *
     * Returns number of rows deleted (for logging).
     */
    public function prune(int $maxAgeSeconds = 86400): int
    {
        $cutoff = time() - max(60, $maxAgeSeconds);
        $stmt = Database::connection()->prepare(
            'DELETE FROM telegram_seen_updates WHERE seen_at < :cut'
        );
        $stmt->execute([':cut' => $cutoff]);
        return $stmt->rowCount();
    }
}

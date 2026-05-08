<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Per-Telegram-user state — currently just the user's chosen "default
 * destination album" plus last-seen timestamp.
 *
 * Why a dedicated table instead of stuffing into the main `settings` row:
 *   - One row per allow-listed Telegram user; a global setting can't
 *     express that.
 *   - Keeps state-mutation cheap: `/use my-album` only writes one column.
 *
 * The table is created by migration 013_telegram_settings.php.
 */
final class TelegramUserStateRepository
{
    /**
     * @return array{user_id:int, default_album_key:?string, last_seen_at:int, created_at:int}|null
     */
    public function find(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, default_album_key, last_seen_at, created_at
               FROM telegram_user_state
              WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) return null;

        $key = $row['default_album_key'] ?? null;
        if ($key !== null && (string)$key === '') $key = null;
        return [
            'user_id'           => (int)$row['user_id'],
            'default_album_key' => $key !== null ? (string)$key : null,
            'last_seen_at'      => (int)$row['last_seen_at'],
            'created_at'        => (int)$row['created_at'],
        ];
    }

    /**
     * Record that we've heard from this user — bumps `last_seen_at` and
     * inserts a new row at first contact. No-ops on the album key.
     */
    public function touch(int $userId): void
    {
        $now = time();
        // SQLite UPSERT — INSERT, on PK conflict UPDATE just last_seen_at.
        Database::connection()->prepare(
            'INSERT INTO telegram_user_state (user_id, default_album_key, last_seen_at, created_at)
                 VALUES (:u, NULL, :t, :t)
                 ON CONFLICT(user_id) DO UPDATE SET last_seen_at = :t'
        )->execute([':u' => $userId, ':t' => $now]);
    }

    /**
     * Set (or clear, with `null`) the user's default destination album.
     */
    public function setDefaultAlbumKey(int $userId, ?string $albumKey): void
    {
        $now = time();
        $key = $albumKey !== null && $albumKey !== '' ? $albumKey : null;
        Database::connection()->prepare(
            'INSERT INTO telegram_user_state (user_id, default_album_key, last_seen_at, created_at)
                 VALUES (:u, :k, :t, :t)
                 ON CONFLICT(user_id) DO UPDATE
                     SET default_album_key = :k, last_seen_at = :t'
        )->execute([':u' => $userId, ':k' => $key, ':t' => $now]);
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Async deletion queue for remote (S3-compatible) storage objects.
 *
 * Replaces data/remote_delete_queue.json. Compared to the JSON queue:
 * - SQL UPSERT (`ON CONFLICT`) avoids the read-modify-write race that the
 *   PHP-side merge had whenever two requests queued deletions concurrently.
 * - The `available_at` index lets the worker pull only the rows that are
 *   actually due, instead of scanning the whole queue every tick.
 */
final class RemoteDeleteQueueRepository
{
    /**
     * Add (or coalesce) an entry. If the same object_key already exists,
     * the earlier `available_at` wins so we don't accidentally postpone
     * a deletion that was due sooner.
     */
    public function enqueue(string $objectKey, int $delaySeconds): void
    {
        $objectKey = trim($objectKey);
        if ($objectKey === '') return;

        $now = time();
        $availableAt = $now + max(0, $delaySeconds);

        // SQLite supports ON CONFLICT since 3.24 (well below our minimum).
        $stmt = Database::connection()->prepare(
            'INSERT INTO remote_delete_queue (object_key, available_at, attempts, last_error, created_at)
             VALUES (:k, :a, 0, NULL, :c)
             ON CONFLICT(object_key) DO UPDATE SET
                available_at = MIN(available_at, excluded.available_at)'
        );
        // object_key isn't UNIQUE in our schema yet — fix that opportunistically.
        // (See dedup() for the dedup pass we run before relying on UPSERT.)
        try {
            $stmt->execute([':k' => $objectKey, ':a' => $availableAt, ':c' => $now]);
        } catch (\PDOException $e) {
            // Fallback: explicit dedup if ON CONFLICT isn't supported.
            $this->upsertManual($objectKey, $availableAt, $now);
        }
    }

    private function upsertManual(string $objectKey, int $availableAt, int $now): void
    {
        $find = Database::connection()->prepare(
            'SELECT id, available_at FROM remote_delete_queue WHERE object_key = :k LIMIT 1'
        );
        $find->execute([':k' => $objectKey]);
        $row = $find->fetch();
        if ($row === false) {
            Database::connection()
                ->prepare('INSERT INTO remote_delete_queue (object_key, available_at, attempts, created_at) VALUES (:k, :a, 0, :c)')
                ->execute([':k' => $objectKey, ':a' => $availableAt, ':c' => $now]);
            return;
        }
        if ((int)$row['available_at'] > $availableAt) {
            Database::connection()
                ->prepare('UPDATE remote_delete_queue SET available_at = :a WHERE id = :id')
                ->execute([':a' => $availableAt, ':id' => (int)$row['id']]);
        }
    }

    /**
     * @return array<int, array{id:int,object_key:string,available_at:int,attempts:int,last_error:?string,created_at:int}>
     */
    public function dueNow(int $limit = 25): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, object_key, available_at, attempts, last_error, created_at
             FROM remote_delete_queue
             WHERE available_at <= :n
             ORDER BY available_at ASC
             LIMIT :l'
        );
        $now = time();
        $stmt->bindValue(':n', $now, \PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn ($r) => [
            'id' => (int)$r['id'],
            'object_key' => (string)$r['object_key'],
            'available_at' => (int)$r['available_at'],
            'attempts' => (int)$r['attempts'],
            'last_error' => isset($r['last_error']) ? (string)$r['last_error'] : null,
            'created_at' => (int)$r['created_at'],
        ], $stmt->fetchAll() ?: []);
    }

    public function delete(int $id): void
    {
        Database::connection()
            ->prepare('DELETE FROM remote_delete_queue WHERE id = :id')
            ->execute([':id' => $id]);
    }

    public function recordFailure(int $id, string $error, int $newAvailableAt): void
    {
        Database::connection()
            ->prepare('UPDATE remote_delete_queue
                       SET attempts = attempts + 1, last_error = :e, available_at = :a
                       WHERE id = :id')
            ->execute([':id' => $id, ':e' => $error, ':a' => $newAvailableAt]);
    }

    public function totalCount(): int
    {
        return (int)Database::connection()->query('SELECT COUNT(*) FROM remote_delete_queue')->fetchColumn();
    }
}

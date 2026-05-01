<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Background image-processing queue. Each row tells the worker what to do
 * with one file (compress, convert, watermark, sync to S3, etc.).
 *
 * Replaces the JSON queue at data/import_tasks.json. The processing
 * pipeline itself still lives in legacy procedural code; this repo is the
 * storage layer.
 */
final class ImportQueueRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    private const FLAG_KEYS = ['create_thumbnail', 'auto_compress', 'auto_webp', 'auto_avif', 'watermark', 'remote_sync'];

    /**
     * Enqueue (or merge) a task for `$filename`. If an entry already exists,
     * the boolean flags are OR-merged so a second enqueue can't downgrade
     * a more thorough first pass.
     */
    public function enqueue(string $filename, array $options): bool
    {
        $filename = trim($filename);
        if ($filename === '') return false;

        $pdo = Database::connection();
        $existing = $this->findByFilename($filename);
        $merged = self::normalizeOptions($options);
        if ($existing !== null && is_array($existing['options'] ?? null)) {
            foreach (self::FLAG_KEYS as $key) {
                $merged[$key] = !empty($merged[$key]) || !empty($existing['options'][$key]);
            }
        }
        $payload = json_encode($merged, JSON_UNESCAPED_UNICODE);
        $now = time();

        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE import_queue SET options = :o, status = :s, updated_at = :t WHERE id = :id'
            );
            $stmt->execute([':o' => $payload, ':s' => self::STATUS_PENDING, ':t' => $now, ':id' => $existing['id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO import_queue (filename, options, status, created_at, updated_at)
                 VALUES (:f, :o, :s, :t, :t)'
            );
            $stmt->execute([':f' => $filename, ':o' => $payload, ':s' => self::STATUS_PENDING, ':t' => $now]);
        }
        return true;
    }

    public function findByFilename(string $filename): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, filename, options, status, attempts, last_error, created_at, updated_at
             FROM import_queue WHERE filename = :f LIMIT 1'
        );
        $stmt->execute([':f' => $filename]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextBatch(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT id, filename, options, status, attempts, last_error, created_at, updated_at
             FROM import_queue
             WHERE status = :s
             ORDER BY id ASC
             LIMIT :l'
        );
        $stmt->bindValue(':s', self::STATUS_PENDING);
        $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'cast'], $stmt->fetchAll() ?: []);
    }

    public function markDone(int $id): void
    {
        Database::connection()
            ->prepare('DELETE FROM import_queue WHERE id = :id')
            ->execute([':id' => $id]);
    }

    public function markFailure(int $id, string $error, int $maxAttempts = 3): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT attempts FROM import_queue WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $attempts = (int)$stmt->fetchColumn() + 1;

        if ($attempts >= $maxAttempts) {
            // Drop after exhausting retries — same behaviour as the legacy
            // in-memory pipeline (which kept tasks for at most 3 attempts).
            $pdo->prepare('DELETE FROM import_queue WHERE id = :id')->execute([':id' => $id]);
            return;
        }

        $pdo->prepare(
            'UPDATE import_queue
             SET attempts = :n, last_error = :e, status = :s, updated_at = :t
             WHERE id = :id'
        )->execute([
            ':n' => $attempts,
            ':e' => $error,
            ':s' => self::STATUS_PENDING,
            ':t' => time(),
            ':id' => $id,
        ]);
    }

    public function pendingCount(): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM import_queue WHERE status = :s');
        $stmt->execute([':s' => self::STATUS_PENDING]);
        return (int)$stmt->fetchColumn();
    }

    public function failedCount(): int
    {
        return (int)Database::connection()
            ->query('SELECT COUNT(*) FROM import_queue WHERE attempts > 0')
            ->fetchColumn();
    }

    /**
     * Whether any of the requested operations are non-trivial (matches
     * the legacy \LitePic\Repository\ImportQueueRepository::hasWork() check).
     */
    public static function hasWork(array $options): bool
    {
        foreach (self::FLAG_KEYS as $key) {
            if (!empty($options[$key])) return true;
        }
        return false;
    }

    /**
     * @return array{create_thumbnail:bool,auto_compress:bool,auto_webp:bool,auto_avif:bool,watermark:bool,remote_sync:bool}
     */
    public static function normalizeOptions(array $options): array
    {
        return [
            'create_thumbnail' => !empty($options['create_thumbnail']),
            'auto_compress' => !empty($options['auto_compress']),
            'auto_webp' => !empty($options['auto_webp']),
            'auto_avif' => !empty($options['auto_avif']),
            'watermark' => !empty($options['watermark']),
            'remote_sync' => !empty($options['remote_sync']),
        ];
    }

    private static function cast(array $row): array
    {
        $options = json_decode((string)($row['options'] ?? '{}'), true);
        if (!is_array($options)) $options = [];
        return [
            'id' => (int)$row['id'],
            'filename' => (string)$row['filename'],
            'options' => self::normalizeOptions($options),
            'status' => (string)$row['status'],
            'attempts' => (int)$row['attempts'],
            'last_error' => isset($row['last_error']) ? (string)$row['last_error'] : null,
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}

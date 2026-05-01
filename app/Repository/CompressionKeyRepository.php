<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * TinyPNG (and any other compression-API) keys.
 *
 * Replaces the legacy COMPRESSION_API_KEYS_JSON env value (and the
 * data/compression_api_keys.json fallback) with a SQLite table.
 */
final class CompressionKeyRepository
{
    private const SELECT = 'SELECT id, name, api_key, enabled, usage_success, usage_failure,
                                   last_status_code, last_error, last_used_at, created_at
                            FROM compression_api_keys';

    /**
     * @return array<int, array<string,mixed>>
     */
    public function all(): array
    {
        $rows = Database::connection()
            ->query(self::SELECT . ' ORDER BY created_at DESC')
            ->fetchAll() ?: [];
        return array_map([self::class, 'cast'], $rows);
    }

    /**
     * @return array<int, array<string,mixed>>  enabled keys only
     */
    public function active(): array
    {
        $rows = Database::connection()
            ->query(self::SELECT . ' WHERE enabled = 1 ORDER BY created_at ASC')
            ->fetchAll() ?: [];
        return array_map([self::class, 'cast'], $rows);
    }

    public function find(string $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function create(string $name, string $apiKey): bool
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') return false;
        $name = trim($name);
        if ($name === '') $name = 'TinyPNG';

        $stmt = Database::connection()->prepare(
            'INSERT INTO compression_api_keys
                (id, name, api_key, enabled, created_at)
             VALUES (:id, :name, :key, 1, :created)'
        );
        return $stmt->execute([
            ':id' => uniqid('cmp_', true),
            ':name' => $name,
            ':key' => $apiKey,
            ':created' => time(),
        ]);
    }

    public function setEnabled(string $id, bool $enabled): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE compression_api_keys SET enabled = :e WHERE id = :id'
        );
        $stmt->execute([':e' => $enabled ? 1 : 0, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM compression_api_keys WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function recordUsage(string $id, bool $success, int $statusCode = 0, ?string $error = null): void
    {
        $sql = $success
            ? 'UPDATE compression_api_keys
               SET usage_success = usage_success + 1, last_status_code = :code,
                   last_error = NULL, last_used_at = :t
               WHERE id = :id'
            : 'UPDATE compression_api_keys
               SET usage_failure = usage_failure + 1, last_status_code = :code,
                   last_error = :err, last_used_at = :t
               WHERE id = :id';
        $params = [':code' => $statusCode, ':t' => time(), ':id' => $id];
        if (!$success) {
            $params[':err'] = $error;
        }
        Database::connection()->prepare($sql)->execute($params);
    }

    private static function cast(array $row): array
    {
        $success = (int)($row['usage_success'] ?? 0);
        $failure = (int)($row['usage_failure'] ?? 0);
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'api_key' => (string)$row['api_key'],
            'enabled' => (bool)($row['enabled'] ?? false),
            'used_count' => $success + $failure,
            'success_count' => $success,
            'failed_count' => $failure,
            'last_used_at' => isset($row['last_used_at']) ? (int)$row['last_used_at'] : null,
            'last_status_code' => isset($row['last_status_code']) ? (int)$row['last_status_code'] : 0,
            'last_error' => isset($row['last_error']) ? (string)$row['last_error'] : null,
            'created_at' => (int)$row['created_at'],
        ];
    }
}

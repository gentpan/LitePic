<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * Persisted key/value store for ALL application settings (the .env
 * replacement) plus app state that doesn't belong in .env (cache
 * snapshots, "first-run done" flags, last-run timestamps, etc.).
 *
 * Values are stored as TEXT — typed accessors (`getInt`, `getBool`,
 * `getJson`) convert on read, and the env_value/bool/csv helpers in
 * config.php read through `Config::warmSettings()` so the existing
 * `define()` call sites are unchanged.
 */
final class SettingsRepository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value === null ? $default : (int)$value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) return $default;
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function getJson(string $key, $default = null)
    {
        $raw = $this->get($key);
        if ($raw === null) return $default;
        $decoded = json_decode($raw, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    public function set(string $key, string $value): void
    {
        $now = time();
        Database::connection()
            ->prepare('INSERT INTO settings (key, value, updated_at)
                       VALUES (:k, :v, :t)
                       ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = :t')
            ->execute([':k' => $key, ':v' => $value, ':t' => $now]);
    }

    public function setJson(string $key, $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->set($key, $encoded === false ? '' : $encoded);
    }

    /**
     * Bulk write. All updates run inside a single transaction so a
     * partial save (mid-batch DB error) doesn't leave the row set
     * half-applied — the form retry will see previous values intact.
     *
     * @param array<string, string> $updates
     */
    public function setMany(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = :t'
        );

        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $now = time();
            foreach ($updates as $k => $v) {
                $stmt->execute([':k' => (string)$k, ':v' => (string)$v, ':t' => $now]);
            }
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Whole table as a flat key=>value map. Used by `Config::warmSettings()`
     * to populate the in-process cache once per request.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = Database::connection()->query('SELECT key, value FROM settings')->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['key']] = (string)$r['value'];
        }
        return $out;
    }

    public function delete(string $key): void
    {
        Database::connection()
            ->prepare('DELETE FROM settings WHERE key = :k')
            ->execute([':k' => $key]);
    }

    public function exists(string $key): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        return (bool)$stmt->fetchColumn();
    }
}

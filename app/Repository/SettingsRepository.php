<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Generic key/value store for app state that doesn't belong in `.env`:
 * cache snapshots, "first-run done" flags, last-run timestamps, etc.
 *
 * Values are stored as strings; helpers convert to JSON / int / bool.
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

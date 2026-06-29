<?php
declare(strict_types=1);

namespace LitePic\Core;

use PDO;
use PDOException;

/**
 * SQLite connection singleton.
 *
 * The database file lives under `data/litepic.sqlite` (denied by nginx rules).
 * WAL journaling is enabled so reads don't block during writes from PHP-FPM
 * workers running in parallel.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $path = null;

    public static function init(string $path): void
    {
        self::$path = $path;
    }

    public static function path(): string
    {
        if (self::$path === null) {
            throw new \RuntimeException('Database::init() not called yet.');
        }
        return self::$path;
    }

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to open SQLite database at ' . $path . ': ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * Execute a callback inside a transaction. Rolls back on any throw.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Drop the singleton + force PDO destruction so SQLite releases its
     * file lock. Used by DatabaseBackup::restoreFromBackup() before
     * overwriting the live DB file. Safe to call from anywhere — the
     * next connection() rebuilds.
     */
    public static function closeConnection(): void
    {
        self::$pdo = null;
        // PHP refcount handles destruction; this gc cycle nudges it.
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}

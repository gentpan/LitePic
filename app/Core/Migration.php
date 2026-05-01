<?php
declare(strict_types=1);

namespace LitePic\Core;

use PDO;

/**
 * Runs pending schema migrations from `app/Migrations/`. Each migration is a
 * PHP file that returns a closure `function (PDO $pdo): void`. Filenames must
 * begin with a numeric prefix (e.g. `001_initial.php`) which doubles as the
 * version recorded in the `schema_migrations` table.
 */
final class Migration
{
    private string $migrationsDir;

    public function __construct(string $migrationsDir)
    {
        $this->migrationsDir = rtrim($migrationsDir, DIRECTORY_SEPARATOR);
    }

    public function run(): array
    {
        $pdo = Database::connection();
        $this->ensureMigrationsTable($pdo);
        $applied = $this->appliedVersions($pdo);
        $files = $this->discoverMigrations();

        $ran = [];
        foreach ($files as [$version, $name, $path]) {
            if (in_array($version, $applied, true)) {
                continue;
            }

            $callable = require $path;
            if (!is_callable($callable)) {
                throw new \RuntimeException("Migration {$name} did not return a callable.");
            }

            Database::transaction(function (PDO $pdo) use ($callable, $version, $name) {
                $callable($pdo);
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, name, applied_at) VALUES (:v, :n, :t)'
                );
                $stmt->execute([':v' => $version, ':n' => $name, ':t' => time()]);
            });

            $ran[] = $name;
        }

        return $ran;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                applied_at INTEGER NOT NULL
            )'
        );
    }

    /**
     * @return int[]
     */
    private function appliedVersions(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * @return array<int, array{0:int,1:string,2:string}> [version, name, path]
     */
    private function discoverMigrations(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $found = [];
        foreach (scandir($this->migrationsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!preg_match('/^(\d+)_(.+)\.php$/', $entry, $m)) continue;

            $found[] = [(int)$m[1], $entry, $this->migrationsDir . DIRECTORY_SEPARATOR . $entry];
        }

        usort($found, static fn($a, $b) => $a[0] <=> $b[0]);
        return $found;
    }
}

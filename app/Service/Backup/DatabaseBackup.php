<?php
declare(strict_types=1);

namespace LitePic\Service\Backup;

use LitePic\Core\Database;
use LitePic\Core\Logger;
use LitePic\Repository\SettingsRepository;
use LitePic\Service\Storage\RemoteStorage;
use Throwable;

/**
 * Backup the SQLite database file (settings, image metadata, tokens,
 * passkeys, queue — everything LitePic persists).
 *
 * Uses SQLite's `VACUUM INTO` which is the official recommended way to
 * snapshot a live DB:
 *   • Atomic — produces a properly closed file even while the source
 *     has open transactions / WAL pending writes
 *   • Defragmented — output is a fresh-packed file (often smaller than
 *     the source)
 *   • Plain SQL command, works through PDO, no special extension needed
 *
 * Backups land in `data/backups/litepic-YYYYMMDD-HHMMSS.sqlite`. The
 * old ones are pruned by `keepCount` so the directory doesn't grow
 * unbounded.
 *
 * Optional R2 / S3 upload — when remote storage is configured AND the
 * `DB_BACKUP_TO_REMOTE` setting is on, each new local backup is also
 * pushed to the remote bucket under `backups/` prefix as off-site
 * disaster recovery.
 *
 * Schedule lives in the worker.php / ImageProcessor::drain hot path —
 * see `runScheduledIfDue()`. Cheap (one settings read + one timestamp
 * compare per worker tick), so we can let every drain pass check.
 */
final class DatabaseBackup
{
    public const SETTING_ENABLED = 'DB_BACKUP_ENABLED';
    public const SETTING_INTERVAL_HOURS = 'DB_BACKUP_INTERVAL_HOURS';
    public const SETTING_KEEP_COUNT = 'DB_BACKUP_KEEP_COUNT';
    public const SETTING_TO_REMOTE = 'DB_BACKUP_TO_REMOTE';
    public const SETTING_LAST_RUN_AT = 'DB_BACKUP_LAST_RUN_AT';

    /** @var int default backup keep count if user never set it */
    public const DEFAULT_KEEP_COUNT = 7;

    /** @var int default backup interval (hours) — 24h = once a day */
    public const DEFAULT_INTERVAL_HOURS = 24;

    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function dbPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/data/litepic.sqlite';
    }

    public function backupDir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/data/backups';
    }

    /**
     * Snapshot the current DB into a new file under data/backups/.
     * Returns the full path. Throws on failure (caller logs / surfaces).
     */
    public function createBackup(): string
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('备份目录不可写：' . $dir);
        }

        $stamp = date('Ymd-His');
        $filename = 'litepic-' . $stamp . '.sqlite';
        $target = $dir . '/' . $filename;

        // SQLite 'VACUUM INTO' refuses to overwrite an existing file,
        // so on the (microscopically unlikely) collision we tag with a
        // random suffix.
        if (is_file($target)) {
            $target = $dir . '/' . substr($filename, 0, -7) . '-' . bin2hex(random_bytes(2)) . '.sqlite';
        }

        try {
            $pdo = Database::connection();
            // Quote the path — VACUUM INTO needs SQL string literal escaping
            $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");
        } catch (Throwable $e) {
            throw new \RuntimeException('SQLite VACUUM INTO 失败：' . $e->getMessage(), 0, $e);
        }

        if (!is_file($target) || filesize($target) === 0) {
            throw new \RuntimeException('备份文件未生成或为空：' . $target);
        }

        @chmod($target, 0600);
        return $target;
    }

    /**
     * @return array<int, array{name:string, path:string, size:int, size_text:string, mtime:int, mtime_text:string}>
     */
    public function listLocalBackups(): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir)) return [];

        $rows = [];
        foreach (glob($dir . '/litepic-*.sqlite') ?: [] as $path) {
            $size = (int)@filesize($path);
            $mtime = (int)@filemtime($path);
            $rows[] = [
                'name'       => basename($path),
                'path'       => $path,
                'size'       => $size,
                'size_text'  => \LitePic\Core\Format::filesize($size),
                'mtime'      => $mtime,
                'mtime_text' => date('Y-m-d H:i:s', $mtime ?: time()),
            ];
        }

        // Newest first
        usort($rows, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $rows;
    }

    /**
     * Delete oldest local backups until at most $keep remain.
     * Returns the number of files removed.
     */
    public function pruneLocalBackups(int $keep): int
    {
        $keep = max(1, $keep);
        $items = $this->listLocalBackups();
        if (count($items) <= $keep) return 0;
        $toRemove = array_slice($items, $keep);
        $removed = 0;
        foreach ($toRemove as $row) {
            if (@unlink($row['path'])) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Push a freshly-created backup to R2/S3 under `backups/<name>`.
     * Returns the resulting object key on success, null on failure
     * (errors are logged — caller usually doesn't fail the request).
     */
    public function uploadToRemote(string $localPath): ?string
    {
        $remote = new RemoteStorage();
        if (!$remote->isEnabled()) return null;
        if (!is_file($localPath)) return null;

        $name = basename($localPath);
        $objectKey = 'backups/' . $name;

        try {
            // RemoteStorage::uploadLocalFile expects a file under uploads/.
            // For backups we want a custom prefix — just use the underlying
            // putObject via the same client. Simplest: temporarily symlink
            // or just use the lower-level method if exposed. Otherwise build
            // the request directly here.
            $result = $this->putObjectDirect($remote, $objectKey, $localPath);
            return $result ? $objectKey : null;
        } catch (Throwable $e) {
            Logger::warning('Backup remote upload failed', [
                'file' => $name,
                'key'  => $objectKey,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Thin wrapper around RemoteStorage::uploadLocalFile but force
     * the object key to a custom prefix (backups/...) instead of the
     * uploads/ default. RemoteStorage exposes `uploadLocalFileAs` (we
     * add it next, see the patch in RemoteStorage.php) — fall back to
     * a direct copy via the existing uploadLocalFile if that method
     * isn't available yet.
     */
    private function putObjectDirect(RemoteStorage $remote, string $objectKey, string $localPath): bool
    {
        if (method_exists($remote, 'uploadLocalFileAs')) {
            $result = $remote->uploadLocalFileAs($localPath, $objectKey);
            return is_array($result) && !empty($result['ok']);
        }
        // Fallback (shouldn't happen post-patch)
        return false;
    }

    // ---------------------------------------------------------------
    // Scheduled / config helpers
    // ---------------------------------------------------------------

    public function isScheduleEnabled(): bool
    {
        return $this->settings->getBool(self::SETTING_ENABLED, false);
    }

    public function intervalSeconds(): int
    {
        $hours = $this->settings->getInt(self::SETTING_INTERVAL_HOURS, self::DEFAULT_INTERVAL_HOURS);
        return max(0, $hours) * 3600;
    }

    public function keepCount(): int
    {
        return max(1, $this->settings->getInt(self::SETTING_KEEP_COUNT, self::DEFAULT_KEEP_COUNT));
    }

    public function syncToRemote(): bool
    {
        return $this->settings->getBool(self::SETTING_TO_REMOTE, false);
    }

    public function lastRunAt(): int
    {
        return $this->settings->getInt(self::SETTING_LAST_RUN_AT, 0);
    }

    /**
     * Worker hot-path: if the schedule is on AND enough time has passed
     * since the last backup, run one. Cheap when nothing's due.
     *
     * @return array{ran:bool, reason:string, path?:string, remote_key?:?string, pruned?:int, error?:string}
     */
    public function runScheduledIfDue(): array
    {
        if (!$this->isScheduleEnabled()) {
            return ['ran' => false, 'reason' => 'schedule disabled'];
        }
        $interval = $this->intervalSeconds();
        if ($interval <= 0) {
            return ['ran' => false, 'reason' => 'interval=0 (manual only)'];
        }
        $age = time() - $this->lastRunAt();
        if ($age < $interval) {
            return ['ran' => false, 'reason' => 'next run in ' . ($interval - $age) . 's'];
        }

        return $this->runOnce(true);
    }

    /**
     * Force-run one backup pass: snapshot → optional remote upload → prune.
     * Used by both the schedule and the manual "立即备份" UI button.
     *
     * @return array{ran:bool, reason:string, path?:string, remote_key?:?string, pruned?:int, error?:string}
     */
    public function runOnce(bool $isScheduled = false): array
    {
        try {
            $path = $this->createBackup();
            $remoteKey = $this->syncToRemote() ? $this->uploadToRemote($path) : null;
            $pruned = $this->pruneLocalBackups($this->keepCount());
            $this->settings->set(self::SETTING_LAST_RUN_AT, (string)time());

            return [
                'ran'         => true,
                'reason'      => $isScheduled ? 'scheduled' : 'manual',
                'path'        => $path,
                'remote_key'  => $remoteKey,
                'pruned'      => $pruned,
            ];
        } catch (Throwable $e) {
            Logger::error('DatabaseBackup runOnce failed', ['error' => $e->getMessage()]);
            return [
                'ran'    => false,
                'reason' => 'error',
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Restore a local backup file over the live DB. The PDO connection
     * is closed first so the file can be safely overwritten on platforms
     * where SQLite holds an exclusive lock.
     *
     * Returns true on success. On failure throws — DB might be in a
     * partial state, caller should surface immediately and tell the
     * user to manually recover.
     */
    public function restoreFromBackup(string $backupName): bool
    {
        $dir = $this->backupDir();
        $safe = basename($backupName);
        if (!preg_match('/^litepic-[0-9\-]+\.sqlite$/', $safe)) {
            throw new \InvalidArgumentException('备份文件名不合法');
        }
        $src = $dir . '/' . $safe;
        if (!is_file($src)) {
            throw new \RuntimeException('备份文件不存在');
        }

        $dbPath = $this->dbPath();
        $tmp = $dbPath . '.restore-tmp';

        // Close PDO so SQLite releases the file (Windows / WAL safe)
        Database::closeConnection();

        if (!@copy($src, $tmp)) {
            throw new \RuntimeException('复制备份文件失败');
        }
        // Move WAL/journal files out of the way too
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');

        if (!@rename($tmp, $dbPath)) {
            @unlink($tmp);
            throw new \RuntimeException('替换数据库文件失败 — 检查文件权限');
        }
        @chmod($dbPath, 0600);
        return true;
    }

    public function deleteBackup(string $backupName): bool
    {
        $safe = basename($backupName);
        if (!preg_match('/^litepic-[0-9\-]+\.sqlite$/', $safe)) {
            return false;
        }
        $path = $this->backupDir() . '/' . $safe;
        if (!is_file($path)) return false;
        return @unlink($path);
    }
}

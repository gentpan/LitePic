<?php
declare(strict_types=1);

/**
 * Image-processing background worker — CLI / cron entry point.
 *
 * What it does
 *   Drains the SQLite `import_queue` table: thumbnail, compress,
 *   WebP / AVIF conversion, watermark, S3 sync. Same code path as
 *   the in-request worker that fires after every upload via
 *   ResponseDetacher — so this is just a safety-net / catchup
 *   mechanism for when the in-request drain didn't run (FPM not
 *   available, request killed mid-flight, server reboot left items
 *   stuck pending, etc.).
 *
 * How to schedule
 *   Cron (preferred):
 *     * * * * * cd /var/www/html && /usr/bin/php worker.php >> logs/worker.log 2>&1
 *
 *   Without cron: the in-request drain that runs after every upload
 *   already covers the common case. Stuck items get picked up the
 *   next time anyone uploads anything. Cron is for the "no upload
 *   for hours" tail.
 *
 * Flags / env
 *   --max-items=N    process at most N tasks this run (default 200)
 *   --max-seconds=S  hard wall-time stop in seconds (default 50)
 *   --quiet          suppress per-task log lines (final summary only)
 *
 * Exit codes
 *   0  normal — drain finished or no items
 *   1  fatal — uncaught exception (logged with stack trace)
 *   2  another worker holds the lock (silent re-queue handled by cron)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "worker.php is CLI-only. Use /api/v1/queue/drain for HTTP-triggered drain.\n";
    exit(1);
}

require __DIR__ . '/bootstrap.php';

// Parse flags
$opts = getopt('', ['max-items::', 'max-seconds::', 'quiet']);
$maxItems   = isset($opts['max-items'])   ? max(1, (int)$opts['max-items'])   : 200;
$maxSeconds = isset($opts['max-seconds']) ? max(1, (int)$opts['max-seconds']) : 50;
$quiet      = array_key_exists('quiet', $opts);

// File-based lock — prevents two cron runs from racing if a previous
// drain is still active. flock semantics: if another worker is alive
// the second one exits silently (cron will try again next minute).
$lockPath = APP_ROOT . '/data/.worker.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "worker: cannot open lock file at {$lockPath}\n");
    exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('c') . "] worker: another instance is running, skipping.\n");
    }
    fclose($lockHandle);
    exit(2);
}

// Make sure the lock is released even on fatal errors
register_shutdown_function(static function () use ($lockHandle, $lockPath) {
    try { @flock($lockHandle, LOCK_UN); } catch (\Throwable $_) {}
    try { @fclose($lockHandle); } catch (\Throwable $_) {}
    @unlink($lockPath);
});

$start = microtime(true);
try {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('c') . "] worker: drain start (max_items={$maxItems}, max_seconds={$maxSeconds})\n");
    }

    $result = (new \LitePic\Service\Image\ImageProcessor())->drain($maxItems, $maxSeconds);

    // 顺便检查一下定时备份 — DatabaseBackup 自己判断"是否到点"，
    // 没到点就立刻返回，到点了就跑一次（VACUUM INTO + 可选 R2 上传 + 修剪旧备份）
    try {
        $backup = (new \LitePic\Service\Backup\DatabaseBackup())->runScheduledIfDue();
        if (!empty($backup['ran'])) {
            $msg = 'DB backup done: ' . basename((string)($backup['path'] ?? ''))
                . (isset($backup['remote_key']) && $backup['remote_key'] !== null
                    ? ' → remote=' . $backup['remote_key']
                    : '')
                . ' pruned=' . (int)($backup['pruned'] ?? 0);
            if (!$quiet) {
                fwrite(STDOUT, '[' . date('c') . "] worker: {$msg}\n");
            }
        }
    } catch (\Throwable $e) {
        // 备份失败不影响 drain 主流程，记录到 logger 即可
        \LitePic\Core\Logger::error('worker: scheduled backup failed', ['error' => $e->getMessage()]);
    }

    $elapsed = number_format((microtime(true) - $start) * 1000, 1);
    fwrite(STDOUT, '[' . date('c') . "] worker: done — processed={$result['processed']} failed={$result['failed']} skipped={$result['skipped']} elapsed_ms={$result['elapsed_ms']} wall_ms={$elapsed}\n");

    // Stash the last drain summary into settings so the System tab can show "上次处理：3 张，1.2s 前"
    try {
        (new \LitePic\Repository\SettingsRepository())->setJson('worker_last_run', [
            'finished_at' => time(),
            'processed'   => (int)$result['processed'],
            'failed'      => (int)$result['failed'],
            'skipped'     => (int)$result['skipped'],
            'elapsed_ms'  => (int)$result['elapsed_ms'],
            'source'      => 'cron',
        ]);
    } catch (\Throwable $_) {
        // best-effort — DB write failure on this row should never make the worker fail
    }

    exit(0);
} catch (\Throwable $e) {
    \LitePic\Core\Logger::error('worker fatal', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, '[' . date('c') . "] worker: FATAL " . $e->getMessage() . "\n");
    exit(1);
}

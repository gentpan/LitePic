<?php
declare(strict_types=1);

/**
 * Image-processing background worker — CLI / cron entry point.
 *
 * What it does
 *   Drains the SQLite `import_queue` table: thumbnail, compress,
 *   WebP / AVIF conversion, watermark, S3 sync. Upload requests only
 *   save the original file and enqueue this work; a tiny response-after
 *   drain handles light traffic, while this worker is the preferred path
 *   for batches and production sites.
 *
 * How to schedule
 *   Cron (preferred):
 *     * * * * * cd /var/www/html && /usr/bin/php worker.php >> logs/worker.log 2>&1
 *
 *   Without cron: LitePic still runs a small response-after drain after
 *   uploads and has a heartbeat fallback. Cron is recommended for large
 *   batches so upload requests stay fast and image processing keeps
 *   moving independently.
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

/*
 * Opportunistic server-stats probing — every CLI worker run reads /proc
 * and caches the results to settings:
 *   - CPU_CORES_OVERRIDE (cached forever once detected, hardware constant)
 *   - SERVER_STATS_SNAPSHOT (memory used, uptime, load — refreshed each run)
 *
 * Why this matters: restricted PHP-FPM environments (BT panel etc.) block
 * /proc + shell_exec from HTTP requests, so the resource gauges would
 * otherwise show only PHP process memory + null uptime. CLI php has full
 * /proc access on the same host. Schedule worker.php as a cron and the
 * dashboard reflects real numbers within a minute.
 */
try {
    \LitePic\Service\Stats\ServerInfo::probeAndCacheCpuCoresIfMissing();
    \LitePic\Service\Stats\ServerInfo::probeAndCacheServerStats();
} catch (\Throwable $_) { /* best-effort */ }

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

    // 保留窗口外的小表清理 — 防止三张 idempotency / abuse-throttle 表
    // 无界增长。都是 best-effort,失败只是浪费一点磁盘,不影响主流程。
    //   - liveness_pings:    > 90d 之外的 ping(只在 ServerInfo::uptimeSeconds
    //                        不可用、需要 web-request 兜底的沙盒主机上才会写)
    //   - telegram_seen_updates: > 24h (Telegram retry 窗口)
    //   - album_visit_log:   > 1h (30min dedupe bucket + 30min 安全裕量)
    try {
        $pdo = \LitePic\Core\Database::connection();
        $pruneTs = time();
        $pruned = ['liveness' => 0, 'tg_seen' => 0, 'album_visit' => 0];

        $s = $pdo->prepare('DELETE FROM liveness_pings WHERE bucket_at < :cut');
        $s->execute([':cut' => $pruneTs - (90 * 86400)]);
        $pruned['liveness'] = $s->rowCount();

        $pruned['tg_seen'] = (new \LitePic\Repository\TelegramSeenUpdateRepository())->prune(86400);
        $pruned['album_visit'] = (new \LitePic\Repository\AlbumVisitLogRepository())->prune(3600);

        if (!$quiet && array_sum($pruned) > 0) {
            fwrite(STDOUT, '[' . date('c') . "] worker: pruned liveness={$pruned['liveness']} tg_seen={$pruned['tg_seen']} album_visit={$pruned['album_visit']}\n");
        }
    } catch (\Throwable $e) {
        \LitePic\Core\Logger::error('worker: retention prune failed', ['error' => $e->getMessage()]);
    }

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

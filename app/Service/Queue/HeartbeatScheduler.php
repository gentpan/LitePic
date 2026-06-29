<?php
declare(strict_types=1);

namespace LitePic\Service\Queue;

use LitePic\Core\Logger;
use LitePic\Core\ResponseDetacher;
use LitePic\Repository\SettingsRepository;
use LitePic\Service\Image\ImageProcessor;
use Throwable;

/**
 * Idle-site safety net for the async worker queue.
 *
 * Why
 *   Self-hosted LitePic instances often run on shared hosting or behind
 *   admin panels (BT / 1Panel / aaPanel / cPanel / …) where wiring up a
 *   real cron is fiddly and panel-specific. Upload requests run only a
 *   tiny response-after drain; this class covers the "owner uploads
 *   occasionally and otherwise no traffic" case.
 *
 * How
 *   Every web request arms a shutdown hook. If the last successful
 *   drain is older than HEARTBEAT_INTERVAL_HOURS (default 24h), the
 *   hook flushes the response, then runs a drain in the same PHP
 *   process via ResponseDetacher — same code path the upload endpoint
 *   uses. The user never waits.
 *
 *   flock on the same data/.worker.lock file as worker.php prevents
 *   races with a real cron worker if both happen to fire at once.
 *
 * Disabled when
 *   • SAPI is cli (worker.php loads bootstrap.php; we must not recurse).
 *   • LITEPIC_HEARTBEAT_DISABLED setting truthy (1 / true / yes / on).
 *   • Last drain (any source) was within the interval window.
 *
 * Knobs (DB settings table, first boot via migration 008)
 *   LITEPIC_HEARTBEAT_DISABLED            — opt-out switch
 *   LITEPIC_HEARTBEAT_INTERVAL_HOURS=24   — minimum gap between fires
 */
final class HeartbeatScheduler
{
    private const DEFAULT_INTERVAL_HOURS = 24;
    private const DRAIN_MAX_ITEMS = 200;
    private const DRAIN_MAX_SECONDS = 50;

    public static function arm(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (self::isDisabled()) {
            return;
        }

        register_shutdown_function([self::class, 'maybeFire']);
    }

    public static function maybeFire(): void
    {
        try {
            if (!self::isDrainStale()) {
                return;
            }

            ResponseDetacher::runAfterResponse(static function () {
                self::drain();
            });
        } catch (Throwable $e) {
            try {
                Logger::error('Heartbeat arm failed', ['error' => $e->getMessage()]);
            } catch (Throwable $_) {
                // Logger broken — nothing useful to do
            }
        }
    }

    private static function drain(): void
    {
        $lockPath = APP_ROOT . '/data/.worker.lock';
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            return;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            // A real cron worker (or another heartbeat) is mid-flight;
            // bail silently and let it finish.
            @fclose($fp);
            return;
        }

        try {
            $result = (new ImageProcessor())->drain(self::DRAIN_MAX_ITEMS, self::DRAIN_MAX_SECONDS);

            try {
                (new SettingsRepository())->setJson('worker_last_run', [
                    'finished_at' => time(),
                    'processed'   => (int)($result['processed'] ?? 0),
                    'failed'      => (int)($result['failed'] ?? 0),
                    'skipped'     => (int)($result['skipped'] ?? 0),
                    'elapsed_ms'  => (int)($result['elapsed_ms'] ?? 0),
                    'source'      => 'heartbeat',
                ]);
            } catch (Throwable $_) {
                // Best-effort persistence — don't fail the drain over a
                // bookkeeping write
            }

            try {
                Logger::info('Heartbeat drain done', [
                    'processed'  => (int)($result['processed'] ?? 0),
                    'failed'     => (int)($result['failed'] ?? 0),
                    'skipped'    => (int)($result['skipped'] ?? 0),
                    'elapsed_ms' => (int)($result['elapsed_ms'] ?? 0),
                ]);
            } catch (Throwable $_) {}
        } catch (Throwable $e) {
            try {
                Logger::error('Heartbeat drain failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (Throwable $_) {}
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            @unlink($lockPath);
        }
    }

    private static function isDisabled(): bool
    {
        $val = (string)\LitePic\Core\Config::get('LITEPIC_HEARTBEAT_DISABLED', '');
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    private static function isDrainStale(): bool
    {
        try {
            $lastRun = (new SettingsRepository())->getJson('worker_last_run');
        } catch (Throwable $_) {
            return true;
        }

        if (!is_array($lastRun) || empty($lastRun['finished_at'])) {
            return true;
        }

        $intervalSec = self::intervalHours() * 3600;
        return (time() - (int)$lastRun['finished_at']) >= $intervalSec;
    }

    private static function intervalHours(): int
    {
        $hours = (int)\LitePic\Core\Config::get('LITEPIC_HEARTBEAT_INTERVAL_HOURS', 0);
        return $hours > 0 ? $hours : self::DEFAULT_INTERVAL_HOURS;
    }
}

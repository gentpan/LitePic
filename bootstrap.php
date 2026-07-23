<?php
declare(strict_types=1);

/**
 * LitePic bootstrap.
 *
 * Single entry point that all PHP requests flow through. Responsibilities:
 *   1. Define APP_ROOT
 *   2. Register the PSR-4 autoloader
 *   3. Bring up Logger
 *   4. Load .env (optional — no .env required; DB is the canonical store)
 *   5. Open the SQLite connection and run any pending migrations
 *      (migration 008 seeds all defaults into the settings table)
 *   6. Warm the settings cache from the DB (one SELECT * FROM settings),
 *      then seed from .env on first boot only (one-time, idempotent)
 *   7. Pull in `config.php` constants (define()s read from settings cache
 *      first via env_value/bool/csv, then $_ENV, then defaults)
 *
 * FrankenPHP / long-lived workers: entrypoints should `require` (not
 * require_once) this file so the trailing warmSettings() re-runs every
 * request. One-time init is gated by LITEPIC_BOOTSTRAPPED.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

$litepicBootstrapFirst = !defined('LITEPIC_BOOTSTRAPPED');
if ($litepicBootstrapFirst) {
    define('LITEPIC_BOOTSTRAPPED', true);
}

if (PHP_SAPI !== 'cli' && is_file(APP_ROOT . '/.maintenance')) {
    $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($uriPath) ? $uriPath : '/';
    $isUpdater = str_starts_with($path, '/api/v1/update');
    if (!$isUpdater) {
        http_response_code(503);
        header('Retry-After: 120');
        if (str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'LitePic 正在更新，请稍后再试'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LitePic 正在更新</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f1116;color:#e6eef8;font-family:-apple-system,BlinkMacSystemFont,"Noto Sans SC",sans-serif}.box{border:1px solid #ffffff14;padding:28px 34px;background:#151a24}h1{margin:0 0 8px;font-size:22px}p{margin:0;color:#9aa0a6}</style><div class="box"><h1>LitePic 正在更新</h1><p>程序文件正在替换，请稍后刷新页面。</p></div>';
        }
        exit;
    }
}

if ($litepicBootstrapFirst) {
    require_once APP_ROOT . '/app/Core/Autoloader.php';
    \LitePic\Core\Autoloader::register('LitePic\\', APP_ROOT . '/app');

    \LitePic\Core\Logger::init(APP_ROOT . '/logs');

    // .env is now optional — load it into $_ENV so first-boot installs and
    // CLI scripts that ship a .env still work, but every save via the
    // settings UI persists to the DB and shadows the .env value from then on.
    \LitePic\Core\Config::init(APP_ROOT . '/.env');

    \LitePic\Core\Database::init(APP_ROOT . '/data/litepic.sqlite');

    try {
        $migrator = new \LitePic\Core\Migration(APP_ROOT . '/app/Migrations');
        $migrator->run();
    } catch (\Throwable $e) {
        \LitePic\Core\Logger::error('Migration failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
        if (\LitePic\Core\Config::bool('DEBUG', false)) {
            throw $e;
        }
    }

    // First warm + first-boot .env → DB seed. Subsequent requests only
    // re-warm (below) so SITE_URL / feature flags pick up settings UI
    // changes without restarting FrankenPHP workers.
    \LitePic\Core\Config::warmSettings();
    \LitePic\Core\Config::seedFromEnvIfEmpty();

    // Constants (SITE_NAME, MAX_FILE_SIZE, WATERMARK_*, S3_*, ENABLE_*).
    // Views and templates reference these directly. The env_value/bool/csv
    // helpers consult the settings cache first, so define() effectively
    // loads from SQLite without any caller changes.
    // NOTE: define() values stay frozen for the worker lifetime — code that
    // must react to live settings (e.g. ImageUrl SITE_URL) should call
    // Config::get() / Config::siteUrl() instead of the constant.
    require_once APP_ROOT . '/config.php';
}

// Always re-warm settings (cheap SELECT *) so long-lived workers see
// admin changes to SITE_URL / hotlink / view-counter without a restart.
\LitePic\Core\Config::warmSettings();

// All /api/* responses are auth- or state-sensitive — forbid CDN caching.
if (PHP_SAPI !== 'cli') {
    $bootstrapUriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $bootstrapPath = is_string($bootstrapUriPath) && $bootstrapUriPath !== '' ? $bootstrapUriPath : '/';
    if (str_starts_with($bootstrapPath, '/api/')) {
        \LitePic\Core\HttpCache::preventPrivateCaching();
    }
}

// Idle-site safety net for the worker queue. Arms a shutdown hook on
// every web request that fires a drain at most once per 24h (default)
// when no real cron is configured. No-op on CLI.
\LitePic\Service\Queue\HeartbeatScheduler::arm();

// Liveness sample for the runtime-info uptime strip. Every PHP request
// records one ping per minute (PRIMARY KEY in liveness_pings collapses
// concurrent inserts). Skip on CLI so background workers don't flood it.
if (PHP_SAPI !== 'cli') {
    \LitePic\Service\Stats\LivenessTracker::recordOnce();
} elseif ($litepicBootstrapFirst) {
    // CLI fast-path: snapshot all the server-side stats that restricted
    // PHP-FPM can't read (BT panel locks /proc + shell_exec from HTTP, but
    // CLI has full access). Cached to settings; HTTP requests fall back
    // to the snapshot when their own probes fail.
    //   - CPU cores: cached once forever (hardware constant)
    //   - Memory total/used + uptime + load: refreshed every CLI run
    try {
        \LitePic\Service\Stats\ServerInfo::probeAndCacheCpuCoresIfMissing();
        \LitePic\Service\Stats\ServerInfo::probeAndCacheServerStats();
    } catch (\Throwable $_) { /* best-effort */ }
}

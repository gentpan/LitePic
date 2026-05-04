<?php
declare(strict_types=1);

/**
 * LitePic bootstrap.
 *
 * Single entry point that all PHP requests flow through. Responsibilities:
 *   1. Define APP_ROOT
 *   2. Register the PSR-4 autoloader
 *   3. Bring up Logger
 *   4. Load .env (first-boot fallback only — DB is the canonical store)
 *   5. Open the SQLite connection and run any pending migrations
 *   6. Warm the settings cache from the DB (one SELECT * FROM settings),
 *      then seed it from .env if the table is still empty (one-time)
 *   7. Pull in `config.php` constants (define()s read from settings cache
 *      first via env_value/bool/csv, then $_ENV, then defaults)
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

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

// Warm the settings cache (DB → static map). On a fresh install where
// the settings table is still empty, seedFromEnvIfEmpty() copies any
// values present in .env into the DB so subsequent UI saves don't get
// silently shadowed. After this point env_value/bool/csv read DB-first.
\LitePic\Core\Config::warmSettings();
\LitePic\Core\Config::seedFromEnvIfEmpty();

// Constants (SITE_NAME, MAX_FILE_SIZE, WATERMARK_*, S3_*, ENABLE_*).
// Views and templates reference these directly. The env_value/bool/csv
// helpers consult the settings cache first, so define() effectively
// loads from SQLite without any caller changes.
require_once APP_ROOT . '/config.php';

// Idle-site safety net for the worker queue. Arms a shutdown hook on
// every web request that fires a drain at most once per 24h (default)
// when no real cron is configured. No-op on CLI.
\LitePic\Service\Queue\HeartbeatScheduler::arm();

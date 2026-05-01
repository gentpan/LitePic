<?php
declare(strict_types=1);

/**
 * LitePic bootstrap.
 *
 * Single entry point that all PHP requests flow through. Responsibilities:
 *   1. Define APP_ROOT
 *   2. Register the PSR-4 autoloader
 *   3. Load .env into Config
 *   4. Bring up Logger
 *   5. Open the SQLite connection and run any pending migrations
 *   6. Pull in `config.php` constants (consumed by views/templates)
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

require_once APP_ROOT . '/app/Core/Autoloader.php';
\LitePic\Core\Autoloader::register('LitePic\\', APP_ROOT . '/app');

\LitePic\Core\Config::init(APP_ROOT . '/.env');
\LitePic\Core\Logger::init(APP_ROOT . '/logs');

// Constants (SITE_NAME, MAX_FILE_SIZE, WATERMARK_*, S3_*, ENABLE_*).
// Views and templates reference these directly.
require_once APP_ROOT . '/config.php';

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


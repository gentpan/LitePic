<?php
declare(strict_types=1);

/**
 * Legacy bootstrap shim.
 *
 * The real entry point is /bootstrap.php at the project root. This file is
 * kept around so existing `require dirname(__DIR__, 2) . '/app/Core/bootstrap.php'`
 * statements in the views keep working during the OOP migration.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/bootstrap.php';

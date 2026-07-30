<?php
declare(strict_types=1);

/**
 * Compat shim for Nginx / Baota installs without try_files rewrite.
 * /gallery and /gallery/ hit this file; force the app router path.
 */
$_SERVER['LITEPIC_FORCE_PATH'] = '/gallery';
require dirname(__DIR__) . '/index.php';

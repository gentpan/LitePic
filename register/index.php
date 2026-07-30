<?php
declare(strict_types=1);

/**
 * Compat shim for Nginx / Baota installs without try_files rewrite.
 */
$_SERVER['LITEPIC_FORCE_PATH'] = '/register';
require dirname(__DIR__) . '/index.php';

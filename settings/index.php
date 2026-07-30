<?php
declare(strict_types=1);

/**
 * Compat shim for Nginx / Baota installs without try_files rewrite.
 * Tab paths (/settings/<tab>) still need try_files → root index.php.
 */
$_SERVER['LITEPIC_FORCE_PATH'] = '/settings';
require dirname(__DIR__) . '/index.php';

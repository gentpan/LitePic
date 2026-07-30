<?php
declare(strict_types=1);

/**
 * Compat shim for Nginx / Baota installs without try_files rewrite.
 * Nested routes (/albums/new, /albums/<id>/edit) still need try_files.
 */
$_SERVER['LITEPIC_FORCE_PATH'] = '/albums';
require dirname(__DIR__) . '/index.php';

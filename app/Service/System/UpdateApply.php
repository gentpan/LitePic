<?php
declare(strict_types=1);

namespace LitePic\Service\System;

use RuntimeException;

/**
 * Thin wrapper around update_sync.php for backups / local calls.
 * Online install prefers requiring update_sync.php from the ZIP directly.
 */
final class UpdateApply
{
    /** @var string[] */
    public const MANAGED_DIRS = [
        'api',
        'app',
        'assets',
        'static/favicon',
    ];

    /** @var string[] */
    public const MANAGED_FILES = [
        '.env.example',
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'action.php',
        'bootstrap.php',
        'config.php',
        'favicon.ico',
        'footer.php',
        'header.php',
        'image.php',
        'index.php',
        'nginx-litepic.conf',
        'Caddyfile',
        '.user.ini.example',
        'package-lock.json',
        'package.json',
        'worker.php',
        'static/logo.png',
        'static/logo-dark.png',
        'gallery/index.php',
        'upload/index.php',
        'settings/index.php',
        'stats/index.php',
        'albums/index.php',
        'register/index.php',
    ];

    /**
     * @return list<string>
     */
    public static function syncFromPackage(string $sourceRoot, string $appRoot): array
    {
        $syncFile = __DIR__ . '/update_sync.php';
        if (!is_file($syncFile)) {
            throw new RuntimeException('缺少 update_sync.php');
        }
        /** @var callable(string,string):list<string> $sync */
        $sync = require $syncFile;
        if (!is_callable($sync)) {
            throw new RuntimeException('update_sync.php 未返回可调用对象');
        }
        return $sync($sourceRoot, $appRoot);
    }
}

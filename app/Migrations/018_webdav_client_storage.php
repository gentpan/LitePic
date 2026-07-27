<?php
declare(strict_types=1);

/**
 * Remove LitePic-as-WebDAV-server tables/settings and seed WebDAV *client*
 * remote-storage keys (backup / storage modes, parallel to S3/R2).
 */
return function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS dav_locks');
    $pdo->exec('DROP TABLE IF EXISTS dav_entries');

    $keys = [
        'WEBDAV_ENABLED',
        'WEBDAV_USERNAME',
        'WEBDAV_PASSWORD_HASH',
        'WEBDAV_READONLY',
        'WEBDAV_ALLOW_ADMIN_LOGIN',
        'WEBDAV_ALLOW_TOKEN_LOGIN',
        'WEBDAV_MAX_LISTING',
    ];
    $del = $pdo->prepare('DELETE FROM settings WHERE key = ?');
    foreach ($keys as $key) {
        $del->execute([$key]);
    }

    $defaults = [
        'REMOTE_STORAGE_DRIVER' => 's3',
        'REMOTE_WEBDAV_URL' => '',
        'REMOTE_WEBDAV_USERNAME' => '',
        'REMOTE_WEBDAV_PASSWORD' => '',
        'REMOTE_WEBDAV_PATH_PREFIX' => 'uploads',
        'REMOTE_WEBDAV_PUBLIC_BASE_URL' => '',
    ];
    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, ?)'
    );
    $now = time();
    foreach ($defaults as $key => $value) {
        $ins->execute([$key, $value, $now]);
    }
};

<?php
declare(strict_types=1);

namespace LitePic\Service\Storage;

/**
 * Factory for the active remote storage backend.
 *
 * Driver is {@see REMOTE_STORAGE_DRIVER}: `s3` (default) or `webdav`.
 * Usage modes (`backup` / `storage`) are shared across both backends.
 */
final class Remotes
{
    public static function driver(): string
    {
        $driver = defined('REMOTE_STORAGE_DRIVER')
            ? strtolower(trim((string)REMOTE_STORAGE_DRIVER))
            : 's3';
        return $driver === 'webdav' ? 'webdav' : 's3';
    }

    public static function active(): RemoteBackendInterface
    {
        return self::driver() === 'webdav'
            ? new WebDavRemoteStorage()
            : new RemoteStorage();
    }

    /**
     * True when whichever driver is selected has usable credentials.
     */
    public static function isEnabled(): bool
    {
        return self::active()->isEnabled();
    }
}

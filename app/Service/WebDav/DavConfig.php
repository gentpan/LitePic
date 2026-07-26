<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Core\Config;

/**
 * Typed accessors for the `WEBDAV_*` settings rows, plus the two limits that
 * are derived rather than configured.
 *
 * Reads go through {@see Config} (settings table, `.env` fallback) rather than
 * constants: under FrankenPHP a `define()` is frozen for the worker's lifetime,
 * so toggling WebDAV off in the settings UI has to take effect without a
 * restart — which only the live cache lookup gives.
 */
final class DavConfig
{
    /** Lock lifetime granted when the client asks for no particular timeout. */
    public const LOCK_DEFAULT_TIMEOUT = 1800;

    /**
     * Ceiling for a requested lock timeout. Finder asks for effectively
     * infinite locks; capping at an hour means an abandoned client can't wedge
     * a path until the database is edited by hand.
     */
    public const LOCK_MAX_TIMEOUT = 3600;

    public static function enabled(): bool
    {
        return Config::bool('WEBDAV_ENABLED', false);
    }

    public static function readOnly(): bool
    {
        return Config::bool('WEBDAV_READONLY', false);
    }

    public static function username(): string
    {
        $value = trim(Config::string('WEBDAV_USERNAME', 'litepic'));
        return $value === '' ? 'litepic' : $value;
    }

    /** bcrypt hash of the dedicated WebDAV password; '' when unset. */
    public static function passwordHash(): string
    {
        return trim(Config::string('WEBDAV_PASSWORD_HASH', ''));
    }

    public static function hasDedicatedPassword(): bool
    {
        return self::passwordHash() !== '';
    }

    /** Accept the admin password as the WebDAV password. */
    public static function allowAdminLogin(): bool
    {
        return Config::bool('WEBDAV_ALLOW_ADMIN_LOGIN', true);
    }

    /** Accept a managed API token (`ltp_…`) as the WebDAV password. */
    public static function allowTokenLogin(): bool
    {
        return Config::bool('WEBDAV_ALLOW_TOKEN_LOGIN', true);
    }

    /**
     * Hard cap on children returned for one `PROPFIND Depth: 1`.
     *
     * Needed because the synthetic "unfiled" collection can hold the entire
     * library. Finder issues a Depth-1 PROPFIND on every folder it displays, so
     * an uncapped listing of 100k images would be a multi-megabyte XML document
     * built in memory on a single request.
     */
    public static function maxListing(): int
    {
        $value = Config::int('WEBDAV_MAX_LISTING', 2000);
        return max(50, min($value, 20000));
    }

    /**
     * Ceiling for a PUT body, in bytes. Mirrors the upload pipeline's own limit
     * so a WebDAV write can't sidestep the size rule the browser upload obeys —
     * {@see \LitePic\Service\Upload\UploadService::maxBytes()} enforces it again
     * on ingest, this is just the earlier, cheaper rejection.
     */
    public static function maxPutBytes(): int
    {
        $limit = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 0;
        return $limit > 0 ? $limit : 20 * 1024 * 1024;
    }

    /**
     * True when WebDAV should answer at all. A configuration with no usable
     * credential is treated as disabled rather than as an open mount.
     */
    public static function usable(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        return self::hasDedicatedPassword() || self::allowAdminLogin() || self::allowTokenLogin();
    }
}

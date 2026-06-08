<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * Cache-Control helpers for dynamic HTML and authenticated responses.
 *
 * CDN (Cloudflare / Bunny) must not cache login-gated pages or auth redirects —
 * otherwise a cached 302 from an unauthenticated /upload visit blocks every
 * logged-in user from reaching the upload UI.
 */
final class HttpCache
{
    public static function preventPrivateCaching(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Vary: Cookie');
    }

    /**
     * Redirect after sending private no-store headers so edge caches never
     * store the Location response.
     */
    public static function redirect(string $location, int $status = 302): never
    {
        self::preventPrivateCaching();
        header('Location: ' . $location, true, $status);
        exit;
    }
}

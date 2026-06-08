<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * Request metadata that must survive reverse proxies and CDNs.
 */
final class RequestContext
{
    public static function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ||
            (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ||
            (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
    }

    /**
     * Best-effort client IP when the site sits behind Cloudflare / Bunny / nginx.
     */
    public static function clientIp(): string
    {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        $trueClient = trim((string)($_SERVER['HTTP_TRUE_CLIENT_IP'] ?? ''));
        if ($trueClient !== '' && filter_var($trueClient, FILTER_VALIDATE_IP)) {
            return $trueClient;
        }

        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function requestOrigin(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

        return (self::isHttps() ? 'https' : 'http') . '://' . $host;
    }
}

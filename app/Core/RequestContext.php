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

    /**
     * Resolve the public request path for routing.
     *
     * Handles:
     *   - normal REQUEST_URI (/gallery)
     *   - LITEPIC_FORCE_PATH (compat shims under /gallery/index.php etc.)
     *   - /index.php/gallery PATH_INFO style (some Baota / PHP-FPM setups)
     *   - /index.php?s=/gallery ThinkPHP-style rewrites common on Baota
     */
    public static function path(string $default = '/'): string
    {
        if (isset($_SERVER['LITEPIC_FORCE_PATH']) && is_string($_SERVER['LITEPIC_FORCE_PATH']) && $_SERVER['LITEPIC_FORCE_PATH'] !== '') {
            $forced = $_SERVER['LITEPIC_FORCE_PATH'];
            return $forced === '' ? $default : $forced;
        }

        $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? $default), PHP_URL_PATH);
        $path = is_string($uriPath) && $uriPath !== '' ? $uriPath : $default;

        // /index.php/gallery → /gallery
        if (preg_match('#/index\.php(/.*)$#', $path, $m)) {
            $path = $m[1] !== '' ? $m[1] : '/';
        } elseif (preg_match('#/index\.php$#', $path)) {
            $pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
            if ($pathInfo !== '') {
                $path = $pathInfo;
            } elseif (isset($_GET['s']) && is_string($_GET['s']) && $_GET['s'] !== '') {
                // Baota / ThinkPHP: rewrite ^(.*)$ /index.php?s=$1
                $path = '/' . ltrim($_GET['s'], '/');
            } else {
                $path = '/';
            }
        }

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        return $path;
    }
}

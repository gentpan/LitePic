<?php
declare(strict_types=1);

namespace LitePic\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // If headers are already out (e.g. a stray notice printed
        // before the page hit a controller), session_start() will warn.
        // Open an output buffer so the cookie can still be flushed,
        // and log where headers leaked from for diagnosis.
        if (headers_sent($file, $line)) {
            error_log("[LitePic] Session start delayed: headers already sent at {$file}:{$line}");
            if (!ob_get_level()) {
                ob_start();
            }
        } else {
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => $cookieParams['lifetime'] ?? 0,
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                // Prefer explicit COOKIE_SECURE; also mark Secure when the
                // current request is HTTPS so session cookies stick behind CDN.
                'secure' => (defined('COOKIE_SECURE') ? (bool)COOKIE_SECURE : false)
                    || RequestContext::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        @session_start();
    }
}

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
        if (headers_sent()) {
            return;
        }

        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'] ?? 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => Config::bool('COOKIE_SECURE', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        @session_start();
    }
}

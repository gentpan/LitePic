<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * CSRF tokens for state-changing requests.
 *
 * Preferred source: HMAC of ADMIN_SESSION_SECRET. Admin UI auth already
 * relies on that secret via the login cookie, so tying CSRF to the same
 * material keeps saves working even when the PHP session cookie is
 * missing (common behind CDN / FrankenPHP / Secure-cookie mismatches).
 *
 * Fallback: classic per-session random token in $_SESSION, for early
 * install paths where ADMIN_SESSION_SECRET has not been created yet.
 */
final class Csrf
{
    // Matches the legacy procedural code's session key so existing
    // sessions don't lose their token when this class first runs.
    private const SESSION_KEY = 'csrf_token';
    private const HMAC_INFO = 'litepic.csrf.v1';

    public static function token(): string
    {
        $derived = self::derivedToken();
        if ($derived !== '') {
            return $derived;
        }

        Session::start();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            $token = self::requestToken();
        }
        if ($token === '') {
            return false;
        }

        $derived = self::derivedToken();
        if ($derived !== '' && hash_equals($derived, $token)) {
            return true;
        }

        Session::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Token from POST body or X-CSRF-Token header.
     */
    public static function requestToken(): string
    {
        $fromPost = $_POST['csrf_token'] ?? null;
        if (is_string($fromPost) && $fromPost !== '') {
            return $fromPost;
        }

        $fromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }

        return '';
    }

    public static function inputField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    private static function derivedToken(): string
    {
        $secret = '';
        try {
            $secret = (string)Config::get('ADMIN_SESSION_SECRET', '');
        } catch (\Throwable) {
            $secret = '';
        }
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', self::HMAC_INFO, $secret);
    }
}

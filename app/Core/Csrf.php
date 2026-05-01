<?php
declare(strict_types=1);

namespace LitePic\Core;

final class Csrf
{
    // Matches the legacy procedural code's session key so existing
    // sessions don't lose their token when this class first runs.
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        Session::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function inputField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

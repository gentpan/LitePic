<?php
declare(strict_types=1);

namespace LitePic\Service\Auth;

use LitePic\Repository\ApiTokenRepository;

/**
 * Decides whether the current request is authorised — for the admin UI,
 * for the upload API, or for back-office endpoints.
 *
 * Three credentials are supported:
 *   1. Admin cookie (sha256 of ADMIN_API_KEY in the API_KEY_COOKIE)
 *   2. Master API key (ADMIN_API_KEY itself, sent as X-API-Key or Bearer)
 *   3. Per-app tokens issued via ApiTokenRepository
 */
final class AuthService
{
    private ApiTokenRepository $tokens;

    public function __construct(?ApiTokenRepository $tokens = null)
    {
        $this->tokens = $tokens ?? new ApiTokenRepository();
    }

    public function isAdmin(): bool
    {
        $cookieName = defined('API_KEY_COOKIE') ? API_KEY_COOKIE : 'img_api_key';
        $masterKey = defined('ADMIN_API_KEY') ? (string)ADMIN_API_KEY : '';
        if (!isset($_COOKIE[$cookieName]) || $masterKey === '') {
            return false;
        }
        return hash_equals(hash('sha256', $masterKey), (string)$_COOKIE[$cookieName]);
    }

    /**
     * Used by all back-office endpoints (admin UI, action.php). Master key
     * with no scoped capability accepted; per-app tokens are *not* — those
     * are upload-only.
     */
    public function isApiRequestAuthorized(): bool
    {
        if ($this->isAdmin()) return true;
        $apiKey = self::requestApiKey();
        if ($apiKey === null) return false;
        $masterKey = defined('ADMIN_API_KEY') ? (string)ADMIN_API_KEY : '';
        return $masterKey !== '' && hash_equals($masterKey, $apiKey);
    }

    /**
     * Used by the upload API. Accepts admin cookie, master key, configured
     * third-party keys (legacy `.env` allowlist), or any active managed token.
     */
    public function hasUploadApiAccess(): bool
    {
        if ($this->isAdmin()) return true;
        $apiKey = self::requestApiKey();
        if ($apiKey === null) return false;

        $masterKey = defined('ADMIN_API_KEY') ? (string)ADMIN_API_KEY : '';
        if ($masterKey !== '' && hash_equals($masterKey, $apiKey)) return true;

        if (defined('THIRD_PARTY_API_KEYS') && is_array(THIRD_PARTY_API_KEYS)) {
            foreach (THIRD_PARTY_API_KEYS as $allowed) {
                if (is_string($allowed) && $allowed !== '' && hash_equals($allowed, $apiKey)) {
                    return true;
                }
            }
        }

        return $this->tokens->verify($apiKey);
    }

    public static function requestHeader(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$serverKey])) {
            return trim((string)$_SERVER[$serverKey]);
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) {
                    return trim((string)$v);
                }
            }
        }
        return null;
    }

    public static function requestApiKey(): ?string
    {
        $key = self::requestHeader('X-API-Key');
        if ($key !== null && $key !== '') return $key;
        $auth = self::requestHeader('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\Auth;

use LitePic\Core\Config;
use LitePic\Core\Session;
use LitePic\Repository\UserRepository;

/**
 * Request-scoped multi-user context.
 *
 * Layered on top of the legacy single-admin auth:
 *   - When MULTI_USER_MODE = 'off' (default) everything behaves exactly as
 *     before: only the admin cookie/master key counts, and no content is
 *     filtered by owner.
 *   - When enabled ('invite' | 'open'), a regular user logs in via PHP
 *     session (litepic_uid). The administrator keeps the existing cookie;
 *     inside this context the admin resolves to user id 1.
 *
 * Scope semantics for content queries:
 *   scopeUserId() === null  → no filtering (mode off, or admin viewing all)
 *   scopeUserId() === N     → only rows owned by user N
 */
final class UserContext
{
    private const SESSION_KEY = 'litepic_uid';

    /** Acting user for this request when authenticated via a managed API
     *  token (upload API) — set by AuthService once the token resolves. */
    private static ?int $actingUserId = null;

    /** @var array<int, array<string,mixed>|null> per-request user cache */
    private static array $cache = [];

    // ------------------------------------------------------------------
    // Mode
    // ------------------------------------------------------------------

    /** off | invite | open */
    public static function mode(): string
    {
        $mode = strtolower(trim((string)Config::get('MULTI_USER_MODE', 'off')));
        return in_array($mode, ['invite', 'open'], true) ? $mode : 'off';
    }

    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    /** Registration possible without an invite code. */
    public static function openRegistration(): bool
    {
        return self::mode() === 'open';
    }

    // ------------------------------------------------------------------
    // Current user
    // ------------------------------------------------------------------

    /**
     * The currently logged-in user row, or null for guests.
     * The legacy admin cookie maps to user id 1 (seeded admin).
     */
    public static function currentUser(): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        Session::start();
        $uid = isset($_SESSION[self::SESSION_KEY]) ? (int)$_SESSION[self::SESSION_KEY] : 0;
        if ($uid > 0) {
            $user = self::loadUser($uid);
            if ($user !== null && $user['status'] === 'active') {
                return $user;
            }
            // Disabled mid-session — drop the session marker.
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        if ((new AuthService())->isAdmin()) {
            return self::loadUser(1);
        }
        return null;
    }

    /** 0 = guest. */
    public static function currentUserId(): int
    {
        $user = self::currentUser();
        return $user !== null ? (int)$user['id'] : 0;
    }

    /**
     * Owner filter for content queries. null means "no restriction"
     * (single-user mode, or an administrator). Regular users always get
     * their own id back, so they can only ever see their own content.
     */
    public static function scopeUserId(): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        // Managed API token in play (upload/list API): scope to the token
        // owner — unless the request ALSO carries the admin cookie, which
        // always sees everything.
        if (self::$actingUserId !== null) {
            return (new AuthService())->isAdmin() ? null : self::$actingUserId;
        }
        $user = self::currentUser();
        if ($user === null) {
            return null; // guest pages (public albums etc.) — no filtering
        }
        return $user['role'] === 'admin' ? null : (int)$user['id'];
    }

    public static function isAdmin(): bool
    {
        if ((new AuthService())->isAdmin()) {
            return true;
        }
        $user = self::currentUser();
        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * Any authenticated principal (admin cookie OR regular user session).
     * Pages like /upload and /gallery should gate on this instead of
     * AuthService::isAdmin() when multi-user is on.
     */
    public static function isLoggedIn(): bool
    {
        if ((new AuthService())->isAdmin()) {
            return true;
        }
        return self::currentUser() !== null;
    }

    // ------------------------------------------------------------------
    // Acting user (API-token uploads)
    // ------------------------------------------------------------------

    /**
     * When a managed API token authenticates an upload, AuthService records
     * the token owner's id here so ImageRepository attributes the images to
     * that user. Returns null when no token resolution happened (web upload
     * falls back to currentUserId()).
     */
    public static function setActingUserId(?int $userId): void
    {
        self::$actingUserId = $userId !== null && $userId > 0 ? $userId : null;
    }

    public static function actingUserId(): ?int
    {
        return self::$actingUserId;
    }

    /**
     * The user id that should own newly-created content in this request:
     * token owner > session user > admin (1) > 0 (guest/system).
     */
    public static function contentOwnerId(): int
    {
        if (!self::enabled()) {
            return 1;
        }
        if (self::$actingUserId !== null) {
            return self::$actingUserId;
        }
        $uid = self::currentUserId();
        return $uid > 0 ? $uid : 1;
    }

    // ------------------------------------------------------------------
    // Session login / logout (regular users)
    // ------------------------------------------------------------------

    public static function loginUser(int $userId): void
    {
        Session::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $userId;
        (new UserRepository())->update($userId, ['last_login_at' => time()]);
    }

    public static function logoutUser(): void
    {
        Session::start();
        unset($_SESSION[self::SESSION_KEY]);
    }

    // ------------------------------------------------------------------

    private static function loadUser(int $id): ?array
    {
        if (!array_key_exists($id, self::$cache)) {
            try {
                self::$cache[$id] = (new UserRepository())->find($id);
            } catch (\Throwable $e) {
                // users table missing (migration pending) — behave as guest.
                self::$cache[$id] = null;
            }
        }
        return self::$cache[$id];
    }
}

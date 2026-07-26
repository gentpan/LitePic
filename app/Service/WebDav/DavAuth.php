<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Core\Logger;
use LitePic\Repository\ApiTokenRepository;
use LitePic\Repository\LoginAttemptRepository;
use LitePic\Service\Auth\AuthService;

/**
 * HTTP Basic authentication for the WebDAV mount.
 *
 * Basic is the only scheme every WebDAV client agrees on — Finder, Windows
 * Explorer, rclone, Cyberduck and the mobile clients all speak it, and several
 * speak nothing else. That makes the mount a password oracle, so failures with
 * supplied credentials feed the same per-IP throttle as the admin login.
 * A request with *no* credentials is not a failure: every client's first request
 * is unauthenticated by design, and counting those would lock out normal use.
 *
 * Three passwords are accepted, each independently toggleable:
 *   1. The dedicated WebDAV password (bcrypt in `WEBDAV_PASSWORD_HASH`), which
 *      requires the username to match `WEBDAV_USERNAME`. This is the one to
 *      hand out, because revoking it doesn't disturb admin access.
 *   2. The admin password. Username is not checked — the password is the secret,
 *      and requiring a particular username here only produces support tickets.
 *   3. A managed API token (`ltp_…`). Same reasoning on the username.
 *
 * The admin session cookie is also honoured, which is what makes `/dav/` browsable
 * in a logged-in browser tab for debugging.
 */
final class DavAuth
{
    private LoginAttemptRepository $attempts;
    private ApiTokenRepository $tokens;
    private AuthService $auth;

    public function __construct(
        ?LoginAttemptRepository $attempts = null,
        ?ApiTokenRepository $tokens = null,
        ?AuthService $auth = null
    ) {
        $this->attempts = $attempts ?? new LoginAttemptRepository();
        $this->tokens = $tokens ?? new ApiTokenRepository();
        $this->auth = $auth ?? new AuthService();
    }

    /**
     * Outcome of authenticating a request.
     *
     * `challenge` — send `401` with `WWW-Authenticate`
     * `throttled` — send `429`; too many bad passwords from this IP
     * `ok`        — proceed
     */
    public function authenticate(DavRequest $request): string
    {
        if ($this->auth->isAdmin()) {
            return 'ok';
        }

        $credentials = self::parseBasic($request->header('Authorization'));
        if ($credentials === null) {
            return 'challenge';
        }

        // Only gate requests that actually carry a password, so an
        // unauthenticated probe can never contribute to a lockout.
        if (!$this->attempts->isAllowedForCurrentIp()) {
            return 'throttled';
        }

        [$username, $password] = $credentials;
        if ($password !== '' && $this->verify($username, $password)) {
            return 'ok';
        }

        $this->attempts->recordFailureForCurrentIp();
        Logger::warning('WebDAV authentication failed', [
            'username' => $username,
            'ip' => \LitePic\Core\RequestContext::clientIp(),
        ]);
        return 'challenge';
    }

    private function verify(string $username, string $password): bool
    {
        $hash = DavConfig::passwordHash();
        if ($hash !== '') {
            $expected = DavConfig::username();
            if (hash_equals($expected, $username) && password_verify($password, $hash)) {
                return true;
            }
        }

        if (DavConfig::allowAdminLogin() && AuthService::verifyPassword($password)) {
            return true;
        }

        if (DavConfig::allowTokenLogin() && $this->tokens->verify($password)) {
            return true;
        }

        return false;
    }

    /**
     * `Basic base64(user:pass)` → [username, password], or null when the header
     * is absent or not Basic. A password containing `:` is preserved: only the
     * first colon separates the two fields (RFC 7617).
     *
     * @return array{0:string,1:string}|null
     */
    public static function parseBasic(?string $authorization): ?array
    {
        if ($authorization === null || preg_match('/^Basic\s+(.+)$/i', trim($authorization), $m) !== 1) {
            return null;
        }
        $decoded = base64_decode(trim($m[1]), true);
        if (!is_string($decoded)) {
            return null;
        }
        $separator = strpos($decoded, ':');
        if ($separator === false) {
            return null;
        }
        return [substr($decoded, 0, $separator), substr($decoded, $separator + 1)];
    }

    /**
     * Emit the challenge. `Basic` must be offered before anything else or
     * Windows Explorer will not attempt a Basic exchange at all.
     */
    public static function emitChallenge(): void
    {
        // No `charset=` — Finder's WebDAVFS has been observed to abort the
        // whole mount with a generic "problem connecting" dialog when the
        // challenge carries parameters beyond `realm`.
        DavResponse::emitStatus(401, [
            'WWW-Authenticate' => 'Basic realm="LitePic WebDAV"',
        ]);
    }
}

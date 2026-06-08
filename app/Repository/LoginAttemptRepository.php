<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Per-IP login throttling. Replaces the session-only state in the
 * legacy code (which got reset every time the session expired and was
 * useless across PHP-FPM workers without sticky sessions).
 *
 * The "5 failures in 5 minutes" policy from the legacy code is preserved
 * via the WINDOW_SECONDS / MAX_FAILURES constants.
 */
final class LoginAttemptRepository
{
    public const WINDOW_SECONDS = 300;
    public const MAX_FAILURES = 5;

    /** Convenience: check the current request's IP. */
    public function isAllowedForCurrentIp(): bool
    {
        return $this->isAllowed(\LitePic\Core\RequestContext::clientIp());
    }

    /** Convenience: record a failure against the current request's IP. */
    public function recordFailureForCurrentIp(): void
    {
        $this->recordFailure(\LitePic\Core\RequestContext::clientIp());
    }

    public function isAllowed(string $ip): bool
    {
        $row = $this->find($ip);
        if ($row === null) return true;
        if ($row['blocked_until'] !== null && $row['blocked_until'] > time()) {
            return false;
        }
        // Stale window — treat as fresh.
        if (time() - $row['last_failure_at'] > self::WINDOW_SECONDS) {
            return true;
        }
        return $row['failed_count'] < self::MAX_FAILURES;
    }

    public function recordFailure(string $ip): void
    {
        $now = time();
        $row = $this->find($ip);

        if ($row === null) {
            Database::connection()
                ->prepare('INSERT INTO login_attempts (ip, failed_count, last_failure_at, blocked_until)
                           VALUES (:ip, 1, :t, NULL)')
                ->execute([':ip' => $ip, ':t' => $now]);
            return;
        }

        $count = ($now - $row['last_failure_at'] > self::WINDOW_SECONDS) ? 1 : $row['failed_count'] + 1;
        $blockedUntil = $count >= self::MAX_FAILURES ? $now + self::WINDOW_SECONDS : null;

        Database::connection()
            ->prepare('UPDATE login_attempts
                       SET failed_count = :n, last_failure_at = :t, blocked_until = :b
                       WHERE ip = :ip')
            ->execute([':n' => $count, ':t' => $now, ':b' => $blockedUntil, ':ip' => $ip]);
    }

    private function find(string $ip): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ip, failed_count, last_failure_at, blocked_until
             FROM login_attempts WHERE ip = :ip LIMIT 1'
        );
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return [
            'ip' => (string)$row['ip'],
            'failed_count' => (int)$row['failed_count'],
            'last_failure_at' => (int)$row['last_failure_at'],
            'blocked_until' => isset($row['blocked_until']) ? (int)$row['blocked_until'] : null,
        ];
    }
}

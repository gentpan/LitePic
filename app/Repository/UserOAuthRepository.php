<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * OAuth identity bindings — one row per (provider, provider_user_id),
 * pointing at the local user it logs in as. A user can bind both Google
 * and GitHub; an OAuth login with an unknown identity either auto-creates
 * a user (open mode) or is rejected (invite mode requires the code first).
 */
final class UserOAuthRepository
{
    public function findUserId(string $provider, string $providerUserId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM user_oauth_accounts
             WHERE provider = :p AND provider_user_id = :u LIMIT 1'
        );
        $stmt->execute([':p' => $provider, ':u' => $providerUserId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function bind(int $userId, string $provider, string $providerUserId, string $email = ''): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO user_oauth_accounts (user_id, provider, provider_user_id, email, created_at)
             VALUES (:uid, :p, :pid, :e, :t)
             ON CONFLICT(provider, provider_user_id) DO UPDATE SET user_id = :uid, email = :e'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':p' => $provider,
            ':pid' => $providerUserId,
            ':e' => trim($email),
            ':t' => time(),
        ]);
    }

    /**
     * @return array<int, array{provider:string, provider_user_id:string, email:string, created_at:int}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT provider, provider_user_id, email, created_at
             FROM user_oauth_accounts WHERE user_id = :u ORDER BY created_at ASC'
        );
        $stmt->execute([':u' => $userId]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(static fn ($r) => [
            'provider' => (string)$r['provider'],
            'provider_user_id' => (string)$r['provider_user_id'],
            'email' => (string)($r['email'] ?? ''),
            'created_at' => (int)$r['created_at'],
        ], $rows);
    }

    public function unbind(int $userId, string $provider): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM user_oauth_accounts WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute([':u' => $userId, ':p' => $provider]);
        return $stmt->rowCount() > 0;
    }
}

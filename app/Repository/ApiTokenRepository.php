<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Personal access tokens for the upload API. Tokens are stored as
 * sha256 hashes; the plain-text value is shown to the user exactly once
 * (when create() returns it) and is never recoverable afterwards.
 */
final class ApiTokenRepository
{
    private const PREFIX = 'ltp_';

    /**
     * @return array<int, array{id:string,name:string,created_at:int,last_used_at:?int}>
     */
    public function all(): array
    {
        // Multi-user: regular users only see their own tokens; admin and
        // single-user installs see every token (legacy behaviour).
        $scopeUid = \LitePic\Service\Auth\UserContext::scopeUserId();
        if ($scopeUid === null) {
            $rows = Database::connection()
                ->query('SELECT id, name, created_at, last_used_at FROM managed_api_tokens ORDER BY created_at DESC')
                ->fetchAll() ?: [];
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT id, name, created_at, last_used_at FROM managed_api_tokens
                 WHERE user_id = :uid ORDER BY created_at DESC'
            );
            $stmt->execute([':uid' => $scopeUid]);
            $rows = $stmt->fetchAll() ?: [];
        }
        return array_map(static fn ($r) => [
            'id' => (string)$r['id'],
            'name' => (string)$r['name'],
            'created_at' => (int)$r['created_at'],
            'last_used_at' => isset($r['last_used_at']) ? (int)$r['last_used_at'] : null,
        ], $rows);
    }

    /**
     * Card-shaped representation: keeps the legacy `token_hash`,
     * `revoked_at`, and ISO-8601 timestamp fields so the settings
     * Token tab renders without bespoke formatting at the call site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForDisplay(): array
    {
        return array_map(static function (array $row): array {
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'token_hash' => '',
                'created_at' => $row['created_at'] > 0 ? date('c', $row['created_at']) : '-',
                'last_used_at' => $row['last_used_at'] !== null ? date('c', $row['last_used_at']) : null,
                'revoked_at' => null,
            ];
        }, $this->all());
    }

    /**
     * Wrap `create()` in a try/catch — random_bytes can fail on
     * exotic systems and we don't want the settings page to 500 over it.
     */
    public function createSafely(string $name = 'token'): ?string
    {
        try {
            return $this->create($name);
        } catch (\Throwable $e) {
            error_log('createManagedToken failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate, persist, and return a new token. The plain-text returned
     * here is the only time the caller can see it.
     */
    public function create(string $name = 'token'): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'token';
        }
        $plain = self::PREFIX . bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $id = uniqid('tok_', true);

        $stmt = Database::connection()->prepare(
            'INSERT INTO managed_api_tokens (id, name, token_hash, created_at, last_used_at, user_id)
             VALUES (:id, :name, :hash, :created, NULL, :uid)'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':hash' => $hash,
            ':created' => time(),
            ':uid' => \LitePic\Service\Auth\UserContext::contentOwnerId(),
        ]);

        return $plain;
    }

    public function revoke(string $tokenId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM managed_api_tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verify a plain-text token. On a hit, bumps last_used_at and returns true.
     */
    public function verify(string $plain): bool
    {
        return $this->verifyAndGetUserId($plain) !== null;
    }

    /**
     * Verify a plain-text token and return the owning user id (multi-user).
     * Returns null on miss. Single-user installs always get 1 (admin).
     */
    public function verifyAndGetUserId(string $plain): ?int
    {
        if ($plain === '') return null;
        $hash = hash('sha256', $plain);
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id FROM managed_api_tokens WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if ($row === false) return null;

        $update = Database::connection()->prepare(
            'UPDATE managed_api_tokens SET last_used_at = :t WHERE id = :id'
        );
        $update->execute([':t' => time(), ':id' => $row['id']]);
        return max(1, (int)($row['user_id'] ?? 1));
    }

    /**
     * Owner of a token id — used by the settings UI to decide whether the
     * current user may revoke it.
     */
    public function ownerOf(string $tokenId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM managed_api_tokens WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $tokenId]);
        $uid = $stmt->fetchColumn();
        return $uid === false ? 0 : max(1, (int)$uid);
    }
}

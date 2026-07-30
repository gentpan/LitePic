<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Invite codes for the invitation-only registration mode.
 *
 * A code is redeemable while: not revoked, not expired, and
 * used_count < max_uses (or max_uses = 0 for unlimited). redeem() bumps
 * used_count with a conditional UPDATE so two concurrent registrations
 * can't spend the last use twice.
 */
final class InviteRepository
{
    public function create(string $note = '', int $maxUses = 1, ?int $expiresAt = null, int $createdBy = 1): string
    {
        $code = bin2hex(random_bytes(8)); // 16 hex chars — typeable, unguessable
        $stmt = Database::connection()->prepare(
            'INSERT INTO invites (code, note, max_uses, used_count, expires_at, created_by, created_at, revoked_at)
             VALUES (:c, :n, :m, 0, :e, :b, :t, NULL)'
        );
        $stmt->execute([
            ':c' => $code,
            ':n' => trim($note),
            ':m' => max(0, $maxUses),
            ':e' => $expiresAt,
            ':b' => max(1, $createdBy),
            ':t' => time(),
        ]);
        return $code;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, code, note, max_uses, used_count, expires_at, created_by, created_at, revoked_at
             FROM invites WHERE code = :c'
        );
        $stmt->execute([':c' => trim($code)]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    /**
     * True when the code can be spent right now. Does NOT mutate state —
     * use redeem() for the atomic check-and-spend.
     */
    public function isRedeemable(string $code): bool
    {
        $invite = $this->findByCode($code);
        if ($invite === null) return false;
        if ($invite['revoked_at'] !== null) return false;
        if ($invite['expires_at'] !== null && $invite['expires_at'] < time()) return false;
        if ($invite['max_uses'] > 0 && $invite['used_count'] >= $invite['max_uses']) return false;
        return true;
    }

    /**
     * Atomically spend one use. Returns false when the code is no longer
     * redeemable (race-safe: the UPDATE only lands while limits hold).
     */
    public function redeem(string $code): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE invites SET used_count = used_count + 1
             WHERE code = :c
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at >= :now)
               AND (max_uses = 0 OR used_count < max_uses)'
        );
        $stmt->execute([':c' => trim($code), ':now' => time()]);
        return $stmt->rowCount() > 0;
    }

    public function revoke(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE invites SET revoked_at = :t WHERE id = :i AND revoked_at IS NULL'
        );
        $stmt->execute([':t' => time(), ':i' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = Database::connection()
            ->query('SELECT id, code, note, max_uses, used_count, expires_at, created_by, created_at, revoked_at
                     FROM invites ORDER BY id DESC')
            ->fetchAll() ?: [];
        return array_map([self::class, 'cast'], $rows);
    }

    private static function cast(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'code' => (string)$row['code'],
            'note' => (string)($row['note'] ?? ''),
            'max_uses' => (int)$row['max_uses'],
            'used_count' => (int)$row['used_count'],
            'expires_at' => isset($row['expires_at']) ? (int)$row['expires_at'] : null,
            'created_by' => (int)($row['created_by'] ?? 1),
            'created_at' => (int)$row['created_at'],
            'revoked_at' => isset($row['revoked_at']) ? (int)$row['revoked_at'] : null,
        ];
    }
}

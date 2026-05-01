<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Read/write WebAuthn (Passkey) state — challenges and registered
 * credentials. Both used to live in `data/*.json` files; moved here
 * during the SQLite-only refactor so all app state sits in one DB.
 */
final class WebAuthnRepository
{
    // -----------------------------------------------------------
    // Challenges (short-lived, 5 min TTL)
    // -----------------------------------------------------------

    public function putChallenge(string $type, string $challenge, int $ttlSeconds = 300): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO webauthn_challenges (type, challenge, expires_at)
             VALUES (:t, :c, :e)
             ON CONFLICT(type) DO UPDATE SET challenge = :c, expires_at = :e'
        );
        $stmt->execute([
            ':t' => $type,
            ':c' => $challenge,
            ':e' => time() + max(1, $ttlSeconds),
        ]);
    }

    /**
     * Look up the stored challenge for `$type`, delete the row, and
     * return the challenge if it hasn't expired. Single-use semantics
     * match the legacy file-based contract.
     */
    public function takeChallenge(string $type): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT challenge, expires_at FROM webauthn_challenges WHERE type = :t LIMIT 1'
        );
        $stmt->execute([':t' => $type]);
        $row = $stmt->fetch();

        // Always consume — expired or not — so a malicious caller can't
        // burn through stale challenges as a probe.
        $del = $pdo->prepare('DELETE FROM webauthn_challenges WHERE type = :t');
        $del->execute([':t' => $type]);

        if ($row === false) return null;
        if ((int)$row['expires_at'] < time()) return null;
        return (string)$row['challenge'];
    }

    /**
     * Garbage-collect expired challenges. Cheap (one DELETE on a tiny
     * table) — safe to call from any request that touches the table.
     */
    public function purgeExpiredChallenges(): void
    {
        Database::connection()
            ->prepare('DELETE FROM webauthn_challenges WHERE expires_at < :now')
            ->execute([':now' => time()]);
    }

    // -----------------------------------------------------------
    // Registered credentials
    // -----------------------------------------------------------

    /**
     * @return array<int, array{credentialId:string, publicKey:array{x:string,y:string},
     *                          signCount:int, createdAt:string, lastUsedAt:?string}>
     */
    public function listCredentials(): array
    {
        $rows = Database::connection()
            ->query('SELECT credential_id, public_key_x, public_key_y, sign_count,
                            created_at, last_used_at
                     FROM webauthn_credentials
                     ORDER BY created_at DESC')
            ->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = self::cast($r);
        }
        return $out;
    }

    /**
     * @return array{credentialId:string, publicKey:array{x:string,y:string},
     *               signCount:int, createdAt:string, lastUsedAt:?string}|null
     */
    public function findCredential(string $credentialId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT credential_id, public_key_x, public_key_y, sign_count,
                    created_at, last_used_at
             FROM webauthn_credentials WHERE credential_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $credentialId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function saveCredential(string $credentialId, string $publicKeyX, string $publicKeyY, int $signCount): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO webauthn_credentials
                (credential_id, public_key_x, public_key_y, sign_count, created_at, last_used_at)
             VALUES (:id, :px, :py, :cnt, :ct, NULL)
             ON CONFLICT(credential_id) DO UPDATE SET
                public_key_x = :px,
                public_key_y = :py,
                sign_count = :cnt'
        );
        $stmt->execute([
            ':id'  => $credentialId,
            ':px'  => $publicKeyX,
            ':py'  => $publicKeyY,
            ':cnt' => $signCount,
            ':ct'  => time(),
        ]);
    }

    public function recordUsage(string $credentialId, int $signCount): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE webauthn_credentials
             SET sign_count = :cnt, last_used_at = :now
             WHERE credential_id = :id'
        );
        $stmt->execute([
            ':id'  => $credentialId,
            ':cnt' => $signCount,
            ':now' => time(),
        ]);
    }

    public function deleteCredential(string $credentialId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM webauthn_credentials WHERE credential_id = :id'
        );
        $stmt->execute([':id' => $credentialId]);
        return $stmt->rowCount() > 0;
    }

    public function countCredentials(): int
    {
        return (int)Database::connection()
            ->query('SELECT COUNT(*) FROM webauthn_credentials')
            ->fetchColumn();
    }

    /**
     * Hydrate a DB row into the legacy nested shape WebAuthn class
     * expects (publicKey is an {x, y} map, timestamps are ISO 8601).
     *
     * @return array{credentialId:string, publicKey:array{x:string,y:string},
     *               signCount:int, createdAt:string, lastUsedAt:?string}
     */
    private static function cast(array $row): array
    {
        $createdAt = (int)($row['created_at'] ?? time());
        $lastUsedAt = $row['last_used_at'] ?? null;
        return [
            'credentialId' => (string)$row['credential_id'],
            'publicKey' => [
                'x' => (string)$row['public_key_x'],
                'y' => (string)$row['public_key_y'],
            ],
            'signCount' => (int)$row['sign_count'],
            'createdAt' => date('c', $createdAt),
            'lastUsedAt' => $lastUsedAt !== null ? date('c', (int)$lastUsedAt) : null,
        ];
    }
}

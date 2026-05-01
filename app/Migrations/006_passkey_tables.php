<?php
declare(strict_types=1);

/**
 * Move WebAuthn / Passkey state out of `data/challenges/*.json` and
 * `data/passkeys.json` into SQLite. After this migration runs the
 * service classes write/read these tables instead of touching disk,
 * making the entire app's persistent state live in a single sqlite file.
 *
 * Schema:
 *   webauthn_challenges  — short-lived (5 min) challenges keyed by type
 *                          ('register' | 'authenticate'). One row per
 *                          type — saving a new one replaces the previous.
 *   webauthn_credentials — registered passkey credentials (the public
 *                          half of each ES256 keypair). credential_id
 *                          is the WebAuthn-issued identifier, base64url.
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS webauthn_challenges (
            type TEXT PRIMARY KEY,
            challenge TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            credential_id TEXT PRIMARY KEY,
            public_key_x TEXT NOT NULL,
            public_key_y TEXT NOT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_used_at INTEGER
        )
    SQL);

    // Best-effort import of any legacy passkeys.json so existing
    // registered devices keep working post-migration. Silent on
    // failure — fresh installs have nothing to import.
    $legacyPath = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/data/passkeys.json';
    if (is_file($legacyPath)) {
        $raw = @file_get_contents($legacyPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO webauthn_credentials
                 (credential_id, public_key_x, public_key_y, sign_count, created_at, last_used_at)
                 VALUES (:id, :px, :py, :cnt, :ct, :lu)'
            );
            foreach ($data as $cred) {
                if (!is_array($cred)) continue;
                $id = (string)($cred['credentialId'] ?? '');
                $x = (string)($cred['publicKey']['x'] ?? '');
                $y = (string)($cred['publicKey']['y'] ?? '');
                if ($id === '' || $x === '' || $y === '') continue;
                $createdAt = isset($cred['createdAt']) ? strtotime((string)$cred['createdAt']) : time();
                $lastUsedAt = isset($cred['lastUsedAt']) ? strtotime((string)$cred['lastUsedAt']) : null;
                $stmt->execute([
                    ':id'  => $id,
                    ':px'  => $x,
                    ':py'  => $y,
                    ':cnt' => (int)($cred['signCount'] ?? 0),
                    ':ct'  => (int)($createdAt ?: time()),
                    ':lu'  => $lastUsedAt ?: null,
                ]);
            }
            // Rename rather than delete so the user can recover if anything
            // went wrong with the import.
            @rename($legacyPath, $legacyPath . '.migrated');
        }
    }
};

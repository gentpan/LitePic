<?php
declare(strict_types=1);

/**
 * Multi-user schema.
 *
 * Adds a `users` table plus OAuth account bindings and invite codes, and
 * grows a `user_id` ownership column on every per-user content table.
 *
 * Design:
 *   - LitePic stays single-user by default (MULTI_USER_MODE = 'off'). All
 *     existing rows are owned by user id 1 — the site administrator — via
 *     the column DEFAULT, so no UPDATE backfill is needed.
 *   - `users` id 1 is seeded as the admin. Its password is NOT stored here:
 *     the administrator keeps using the existing master password
 *     (ADMIN_API_KEY / ADMIN_PASSWORD_HASH settings + admin cookie). The
 *     users row exists so every content table has a valid owner reference
 *     and the admin shows up in the user-management UI.
 *   - OAuth identities live in `user_oauth_accounts` so one user can bind
 *     both Google and GitHub (and future providers).
 *   - Invites are single- or multi-use codes with optional expiry; the
 *     `redeem` path increments used_count atomically in the registration
 *     service.
 *
 * Idempotent: CREATE IF NOT EXISTS + pragma-guarded ALTER TABLE so
 * re-running never fails on duplicate columns.
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            email         TEXT UNIQUE,
            display_name  TEXT NOT NULL DEFAULT '',
            password_hash TEXT NOT NULL DEFAULT '',
            role          TEXT NOT NULL DEFAULT 'user'
                          CHECK (role IN ('admin', 'user')),
            status        TEXT NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active', 'disabled')),
            quota_bytes   INTEGER NOT NULL DEFAULT 0,   -- 0 = unlimited
            created_at    INTEGER NOT NULL,
            last_login_at INTEGER
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS user_oauth_accounts (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id          INTEGER NOT NULL,
            provider         TEXT NOT NULL CHECK (provider IN ('google', 'github')),
            provider_user_id TEXT NOT NULL,
            email            TEXT NOT NULL DEFAULT '',
            created_at       INTEGER NOT NULL,
            UNIQUE (provider, provider_user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_oauth_user ON user_oauth_accounts(user_id)');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS invites (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code       TEXT NOT NULL UNIQUE,
            note       TEXT NOT NULL DEFAULT '',
            max_uses   INTEGER NOT NULL DEFAULT 1,      -- 0 = unlimited
            used_count INTEGER NOT NULL DEFAULT 0,
            expires_at INTEGER,                          -- NULL = never
            created_by INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            revoked_at INTEGER,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    SQL);

    // ---- ownership columns -------------------------------------------------
    // SQLite has no IF NOT EXISTS for ADD COLUMN — guard via table_info.
    $addColumn = static function (PDO $pdo, string $table, string $column, string $ddl): void {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $cols = array_map(
            static fn ($r) => (string)$r['name'],
            $stmt ? ($stmt->fetchAll() ?: []) : []
        );
        if (!in_array($column, $cols, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
        }
    };

    // Default 1 = everything that exists today belongs to the administrator.
    $addColumn($pdo, 'images', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");
    $addColumn($pdo, 'albums', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");
    $addColumn($pdo, 'managed_api_tokens', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");
    $addColumn($pdo, 'webauthn_credentials', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");
    $addColumn($pdo, 'compression_api_keys', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");
    $addColumn($pdo, 'telegram_user_state', 'user_id', "user_id INTEGER NOT NULL DEFAULT 1");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_user_id ON images(user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_albums_user_id ON albums(user_id)');

    // ---- seed the administrator (user id 1) --------------------------------
    // INSERT OR IGNORE: a fresh install gets the row; an upgraded install
    // where the row somehow exists keeps it untouched.
    $pdo->exec(
        "INSERT OR IGNORE INTO users (id, username, display_name, password_hash, role, status, created_at)
         VALUES (1, 'admin', '管理员', '', 'admin', 'active', " . time() . ")"
    );

    // ---- default settings ---------------------------------------------------
    // Seeded with INSERT OR IGNORE so admin changes are never clobbered.
    $defaults = [
        'MULTI_USER_MODE'                 => 'off',   // off | invite | open
        'REGISTRATION_DEFAULT_QUOTA_MB'   => '0',     // 0 = unlimited
        'OAUTH_GOOGLE_ENABLED'            => 'false',
        'OAUTH_GOOGLE_CLIENT_ID'          => '',
        'OAUTH_GOOGLE_CLIENT_SECRET'      => '',
        'OAUTH_GITHUB_ENABLED'            => 'false',
        'OAUTH_GITHUB_CLIENT_ID'          => '',
        'OAUTH_GITHUB_CLIENT_SECRET'      => '',
        'SMTP_HOST'                       => '',
        'SMTP_PORT'                       => '465',
        'SMTP_USERNAME'                   => '',
        'SMTP_PASSWORD'                   => '',
        'SMTP_ENCRYPTION'                 => 'ssl',   // none | ssl | starttls
        'SMTP_FROM_EMAIL'                 => '',
        'SMTP_FROM_NAME'                  => '',
    ];
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (:k, :v, :t)'
    );
    foreach ($defaults as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => $v, ':t' => time()]);
    }
};

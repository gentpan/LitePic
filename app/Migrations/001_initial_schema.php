<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    // Image library — replaces directory scanning of uploads/.
    $pdo->exec(<<<'SQL'
        CREATE TABLE images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,        -- relative path under uploads/, e.g. 2026/04/abc.jpg
            original_name TEXT,
            mime TEXT,
            ext TEXT,
            size INTEGER NOT NULL DEFAULT 0,
            width INTEGER,
            height INTEGER,
            hash TEXT,
            created_at INTEGER NOT NULL,
            has_thumbnail INTEGER NOT NULL DEFAULT 0,
            has_webp INTEGER NOT NULL DEFAULT 0,
            has_avif INTEGER NOT NULL DEFAULT 0,
            remote_synced INTEGER NOT NULL DEFAULT 0,
            watermarked INTEGER NOT NULL DEFAULT 0,
            view_count INTEGER NOT NULL DEFAULT 0
        )
    SQL);
    $pdo->exec('CREATE INDEX idx_images_created_at ON images(created_at DESC)');
    $pdo->exec('CREATE INDEX idx_images_hash ON images(hash)');
    $pdo->exec('CREATE INDEX idx_images_ext ON images(ext)');

    // Async S3 deletion queue (was data/remote_delete_queue.json).
    $pdo->exec(<<<'SQL'
        CREATE TABLE remote_delete_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            object_key TEXT NOT NULL,
            available_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at INTEGER NOT NULL
        )
    SQL);
    $pdo->exec('CREATE INDEX idx_rdq_available_at ON remote_delete_queue(available_at)');

    // Background import/processing queue.
    $pdo->exec(<<<'SQL'
        CREATE TABLE import_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            options TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )
    SQL);
    $pdo->exec('CREATE INDEX idx_iq_status ON import_queue(status)');

    // TinyPNG (and friends) compression API key registry.
    $pdo->exec(<<<'SQL'
        CREATE TABLE compression_api_keys (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL DEFAULT '',
            api_key TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            usage_success INTEGER NOT NULL DEFAULT 0,
            usage_failure INTEGER NOT NULL DEFAULT 0,
            last_status_code INTEGER,
            last_error TEXT,
            last_used_at INTEGER,
            created_at INTEGER NOT NULL
        )
    SQL);

    // Personal access tokens for the upload API.
    $pdo->exec(<<<'SQL'
        CREATE TABLE managed_api_tokens (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL DEFAULT '',
            token_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            last_used_at INTEGER
        )
    SQL);

    // Generic key/value store for app settings that don't belong in .env
    // (e.g. flash flags, "first-run done" markers, cache snapshots).
    $pdo->exec(<<<'SQL'
        CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at INTEGER NOT NULL
        )
    SQL);

    // Per-IP login throttling (was in-memory only).
    $pdo->exec(<<<'SQL'
        CREATE TABLE login_attempts (
            ip TEXT PRIMARY KEY,
            failed_count INTEGER NOT NULL DEFAULT 0,
            last_failure_at INTEGER NOT NULL,
            blocked_until INTEGER
        )
    SQL);
};

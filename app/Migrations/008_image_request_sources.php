<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS image_request_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            source_key TEXT NOT NULL,
            source_url TEXT NOT NULL DEFAULT '',
            source_host TEXT NOT NULL DEFAULT '',
            request_count INTEGER NOT NULL DEFAULT 0,
            last_requested_at INTEGER NOT NULL,
            UNIQUE(filename, source_key),
            FOREIGN KEY(filename) REFERENCES images(filename) ON DELETE CASCADE
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_irs_filename_count ON image_request_sources(filename, request_count DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_irs_last_requested_at ON image_request_sources(last_requested_at DESC)');
};

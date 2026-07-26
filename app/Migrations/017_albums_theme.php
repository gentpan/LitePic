<?php
declare(strict_types=1);

/**
 * Album public-page layout theme.
 *
 *   grid     — equal-cell viewport grid (default, TimePlus-style)
 *   masonry  — waterfall / Pinterest columns (natural aspect ratios)
 *
 * Idempotent: skips when `theme` already exists.
 */
return function (PDO $pdo): void {
    foreach ($pdo->query('PRAGMA table_info(albums)')->fetchAll() as $col) {
        if (($col['name'] ?? '') === 'theme') {
            return;
        }
    }

    $pdo->exec(
        "ALTER TABLE albums ADD COLUMN theme TEXT NOT NULL DEFAULT 'grid'"
    );
};

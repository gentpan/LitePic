<?php
declare(strict_types=1);

/**
 * Per-image description + EXIF snapshot.
 *
 * Compression / format conversion strips EXIF from the file on disk
 * (ImageMagick `-strip`, GD re-encode, TinyPNG). Capture GPS / shoot
 * time / camera into the images row *before* that happens so the
 * gallery and public album can still show location without keeping the
 * original bytes.
 *
 * Idempotent ALTER — each column is added only if missing.
 */
return function (PDO $pdo): void {
    $existing = [];
    foreach ($pdo->query('PRAGMA table_info(images)')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $name = (string)($col['name'] ?? '');
        if ($name !== '') {
            $existing[$name] = true;
        }
    }

    $alters = [
        'description'   => "ALTER TABLE images ADD COLUMN description TEXT NOT NULL DEFAULT ''",
        'exif_lat'      => 'ALTER TABLE images ADD COLUMN exif_lat REAL',
        'exif_lng'      => 'ALTER TABLE images ADD COLUMN exif_lng REAL',
        'exif_taken_at' => 'ALTER TABLE images ADD COLUMN exif_taken_at INTEGER',
        'exif_camera'   => 'ALTER TABLE images ADD COLUMN exif_camera TEXT',
        'exif_scanned'  => 'ALTER TABLE images ADD COLUMN exif_scanned INTEGER NOT NULL DEFAULT 0',
    ];

    foreach ($alters as $column => $sql) {
        if (!isset($existing[$column])) {
            $pdo->exec($sql);
        }
    }
};

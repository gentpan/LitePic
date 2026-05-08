<?php
declare(strict_types=1);

/**
 * Make `albums.slug` optional.
 *
 * New URL contract:
 *   - blank slug → public URL is /a/<id>     (numeric, default for new albums)
 *   - typed slug → public URL is /a/<slug>   (admin opt-in)
 *
 * Why writable_schema instead of a table rebuild:
 *   The Migration runner already wraps each migration in a transaction
 *   ({@see \LitePic\Core\Migration::run()}), and SQLite ignores
 *   `PRAGMA foreign_keys = OFF` once a transaction is open. With FK on,
 *   `DROP TABLE albums` would either be blocked by `album_images.album_id`
 *   FK or trip cascade behaviour — neither is what we want for a simple
 *   NOT-NULL relaxation.
 *
 *   Editing `sqlite_master.sql` directly via writable_schema=1 is the
 *   officially-supported (if "advanced") path for this exact scenario.
 *   We're only relaxing a column constraint — no PK/FK/index changes —
 *   so the existing rows and references stay intact.
 *
 * Idempotent: bails cleanly when slug is already nullable.
 */
return function (PDO $pdo): void {
    foreach ($pdo->query('PRAGMA table_info(albums)')->fetchAll() as $col) {
        if (($col['name'] ?? '') === 'slug' && (int)($col['notnull'] ?? 1) === 0) {
            return;
        }
    }

    // Replace the exact NOT NULL clause on the slug column. The CREATE TABLE
    // text stored in sqlite_master is whatever the original migration wrote,
    // verbatim — see migration 010_albums.php.
    $pdo->exec('PRAGMA writable_schema = 1');
    $stmt = $pdo->prepare(
        "UPDATE sqlite_master
            SET sql = REPLACE(sql,
                              'slug            TEXT NOT NULL UNIQUE',
                              'slug            TEXT UNIQUE')
          WHERE type = 'table' AND name = 'albums'"
    );
    $stmt->execute();
    // Bumping schema_version invalidates the per-connection schema cache —
    // without this, `PRAGMA table_info(albums)` (and PDO's prepared-statement
    // metadata) still see the old NOT NULL constraint until the connection
    // is closed and reopened.
    $currentVersion = (int)$pdo->query('PRAGMA schema_version')->fetchColumn();
    $pdo->exec('PRAGMA schema_version = ' . ($currentVersion + 1));
    $pdo->exec('PRAGMA writable_schema = 0');

    // Coerce empty-string slugs to NULL so callers see one canonical
    // "no slug" representation. Pre-existing albums with a slug stay as-is.
    $pdo->exec("UPDATE albums SET slug = NULL WHERE slug = ''");
};

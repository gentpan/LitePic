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
    // Already nullable? Bail — idempotent.
    foreach ($pdo->query('PRAGMA table_info(albums)')->fetchAll() as $col) {
        if (($col['name'] ?? '') === 'slug' && (int)($col['notnull'] ?? 1) === 0) {
            return;
        }
    }

    // Read the current CREATE TABLE statement so we can rewrite it. Using a
    // regex on the actual stored DDL is more robust than the previous
    // literal-string REPLACE — any whitespace tweak in migration 010 would
    // have made that REPLACE a silent no-op, leaving slug NOT NULL while
    // the rest of the app assumes it's nullable.
    $currentSql = (string)$pdo
        ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'albums'")
        ->fetchColumn();
    if ($currentSql === '') {
        throw new \RuntimeException('Migration 012: albums table not found in sqlite_master');
    }

    // Match "slug<whitespace>TEXT<...optional...>NOT NULL<...optional...>"
    // and strip the NOT NULL clause. Allows for variations in whitespace,
    // column-level constraint order (UNIQUE before/after NOT NULL), etc.
    $rewritten = preg_replace(
        '/(\bslug\s+TEXT\b[^,\n)]*?)\bNOT\s+NULL\s*/i',
        '$1',
        $currentSql,
        1,
        $count
    );
    if ($rewritten === null || $count === 0 || $rewritten === $currentSql) {
        throw new \RuntimeException(
            'Migration 012: could not locate slug NOT NULL clause to relax. '
            . 'sqlite_master.sql for albums: ' . $currentSql
        );
    }

    $pdo->exec('PRAGMA writable_schema = 1');
    $stmt = $pdo->prepare(
        "UPDATE sqlite_master SET sql = :sql WHERE type = 'table' AND name = 'albums'"
    );
    $stmt->execute([':sql' => $rewritten]);
    // Bumping schema_version invalidates the per-connection schema cache —
    // without this, `PRAGMA table_info(albums)` (and PDO's prepared-statement
    // metadata) still see the old NOT NULL constraint until the connection
    // is closed and reopened.
    $currentVersion = (int)$pdo->query('PRAGMA schema_version')->fetchColumn();
    $pdo->exec('PRAGMA schema_version = ' . ($currentVersion + 1));
    $pdo->exec('PRAGMA writable_schema = 0');

    // Post-write assertion — surface failure loudly instead of silently
    // leaving slug NOT NULL while callers assume otherwise.
    $stillNotNull = false;
    foreach ($pdo->query('PRAGMA table_info(albums)')->fetchAll() as $col) {
        if (($col['name'] ?? '') === 'slug') {
            $stillNotNull = (int)($col['notnull'] ?? 1) === 1;
            break;
        }
    }
    if ($stillNotNull) {
        throw new \RuntimeException(
            'Migration 012: albums.slug remained NOT NULL after schema rewrite. '
            . 'Rewritten SQL was: ' . $rewritten
        );
    }

    // Coerce empty-string slugs to NULL so callers see one canonical
    // "no slug" representation. Pre-existing albums with a slug stay as-is.
    $pdo->exec("UPDATE albums SET slug = NULL WHERE slug = ''");
};

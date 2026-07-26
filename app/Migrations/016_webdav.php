<?php
declare(strict_types=1);

/**
 * WebDAV support — name mapping table, lock table, and settings defaults.
 *
 * Why a mapping table at all:
 *   LitePic stores images under a generated identifier (`2026/07/AbCdEf123456.jpg`)
 *   and keeps the uploader's filename only as `images.original_name`, which is
 *   neither unique nor path-safe. WebDAV clients require the opposite contract:
 *   a name they PUT must be the exact name a subsequent PROPFIND / GET returns,
 *   and names must be unique within their collection. `dav_entries` is that
 *   bridge — one row per (collection, visible name) pointing at an image.
 *
 * Collection model — "an album is a folder":
 *   `album_id > 0`  → the WebDAV folder for that album
 *   `album_id = 0`  → the synthetic "unfiled" folder (images in no album)
 *   The read-only date view (`/按日期/2026/07/...`) is derived straight from
 *   `images` and deliberately has no rows here; it exposes system identifiers.
 *
 * No backfill:
 *   Rows are materialised lazily by DavIndex reconciliation the first time a
 *   collection is listed (or on a path-lookup miss), so this migration stays
 *   instant even on libraries with 100k images, and the WebDAV view can never
 *   drift away from what the web UI did to albums behind its back.
 *
 * `filename IS NULL` means an empty placeholder: Finder and Office both PUT a
 * 0-byte file to probe writability before sending real bytes, and that probe
 * must survive until the follow-up PUT overwrites it. A row can never become
 * NULL by other means — deleting an image cascades the whole row away.
 *
 * Idempotent: CREATE ... IF NOT EXISTS, INSERT OR IGNORE.
 */
return function (PDO $pdo): void {
    // ---- visible-name ↔ image mapping ----------------------------------------
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dav_entries (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            album_id   INTEGER NOT NULL DEFAULT 0,
            name       TEXT    NOT NULL,
            filename   TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE (album_id, name),
            FOREIGN KEY (filename) REFERENCES images(filename) ON DELETE CASCADE
        )
    SQL);

    // Collection listing (PROPFIND Depth: 1) and reconciliation both scan by album.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dav_entries_album
                ON dav_entries(album_id, name)');

    // Reverse lookup: "which WebDAV names point at this image?" — needed when an
    // image is deleted through the gallery and when reconciling a collection.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dav_entries_filename
                ON dav_entries(filename)');

    // ---- locks (DAV class 2) --------------------------------------------------
    // macOS Finder downgrades a mount to read-only when the server doesn't
    // advertise class 2, so LOCK/UNLOCK is not optional for a usable mount.
    // `depth` is 0 or -1 (RFC 4918 only allows those two for LOCK).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dav_locks (
            token      TEXT PRIMARY KEY,
            path       TEXT NOT NULL,
            depth      INTEGER NOT NULL DEFAULT 0,
            scope      TEXT NOT NULL DEFAULT 'exclusive',
            type       TEXT NOT NULL DEFAULT 'write',
            owner      TEXT NOT NULL DEFAULT '',
            timeout    INTEGER NOT NULL DEFAULT 3600,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )
    SQL);

    // Lock discovery walks ancestors of a path; expiry sweep scans by deadline.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dav_locks_path ON dav_locks(path)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dav_locks_expires ON dav_locks(expires_at)');

    // ---- settings defaults ---------------------------------------------------
    // Off by default: WebDAV exposes write access over Basic auth, so it has to
    // be a deliberate opt-in rather than something a new install ships open.
    $defaults = [
        'WEBDAV_ENABLED'             => 'false',
        'WEBDAV_USERNAME'            => 'litepic',
        'WEBDAV_PASSWORD_HASH'       => '',     // bcrypt; empty = dedicated account unusable
        'WEBDAV_READONLY'            => 'false',
        'WEBDAV_ALLOW_ADMIN_LOGIN'   => 'true', // admin password as the WebDAV password
        'WEBDAV_ALLOW_TOKEN_LOGIN'   => 'true', // managed API token as the WebDAV password
        'WEBDAV_MAX_LISTING'         => '2000', // per-collection cap for PROPFIND Depth: 1
    ];
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (:k, :v, :t)'
    );
    $now = time();
    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value, ':t' => $now]);
    }
};

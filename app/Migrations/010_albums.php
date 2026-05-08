<?php
declare(strict_types=1);

/**
 * Albums + album_images schema.
 *
 * Design:
 *   - `albums` is the metadata table (slug, name, visibility, password, embed
 *     token, denormalised image_count, view_count).
 *   - `album_images` is the M:N junction — an image can live in 0+ albums and
 *     an album has 0+ images. Adding an image to an album never moves the file
 *     and never removes it from the global gallery; albums are an additive
 *     "tag view" over the existing image library.
 *   - `ON DELETE CASCADE` on the FK to images means deleting an image from
 *     the library auto-removes it from every album it's in. The denormalised
 *     `image_count` is then drifted-corrected by the AlbumService whenever an
 *     album is opened (cheap COUNT against the index).
 *
 * Visibility levels:
 *   - public      — listed everywhere, in sitemap, embeddable
 *   - unlisted    — secret URL only, noindex, embeddable with token
 *   - password    — bcrypt gate before viewing, NOT embeddable
 *   - private     — admin-only, never publicly accessible, NOT embeddable
 *
 * Idempotent: every CREATE uses IF NOT EXISTS so re-running is safe.
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS albums (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            slug            TEXT NOT NULL UNIQUE,
            name            TEXT NOT NULL,
            description     TEXT NOT NULL DEFAULT '',
            cover_filename  TEXT,
            visibility      TEXT NOT NULL DEFAULT 'public'
                            CHECK (visibility IN ('public', 'unlisted', 'password', 'private')),
            password_hash   TEXT,
            embed_token     TEXT NOT NULL DEFAULT '',
            image_count     INTEGER NOT NULL DEFAULT 0,
            view_count      INTEGER NOT NULL DEFAULT 0,
            sort_order      INTEGER NOT NULL DEFAULT 0,
            created_at      INTEGER NOT NULL,
            updated_at      INTEGER NOT NULL,
            FOREIGN KEY (cover_filename) REFERENCES images(filename) ON DELETE SET NULL
        )
    SQL);

    // sort_order DESC + created_at DESC = natural admin list ordering.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_albums_sort
                ON albums(sort_order DESC, created_at DESC)');

    // Visibility filter — admin "show all" vs public "show listable" both hit this.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_albums_visibility
                ON albums(visibility)');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS album_images (
            album_id    INTEGER NOT NULL,
            filename    TEXT    NOT NULL,
            sort_order  INTEGER NOT NULL DEFAULT 0,
            added_at    INTEGER NOT NULL,
            PRIMARY KEY (album_id, filename),
            FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
            FOREIGN KEY (filename) REFERENCES images(filename) ON DELETE CASCADE
        )
    SQL);

    // Album page render: ORDER BY sort_order ASC, added_at ASC.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_album_images_order
                ON album_images(album_id, sort_order, added_at)');

    // Reverse lookup: "which albums is this image in?" (gallery card badge,
    // and ON DELETE CASCADE doesn't need an index but the lookup query does).
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_album_images_filename
                ON album_images(filename)');
};

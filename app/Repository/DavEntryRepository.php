<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * The `dav_entries` mapping: one row per (collection, client-visible name)
 * pointing at an image.
 *
 * Collection ids follow the WebDAV tree, not the album table alone:
 * `album_id > 0` is that album's folder, `album_id = 0` is the synthetic
 * "unfiled" folder holding images that belong to no album.
 *
 * Rows are not written when images are uploaded or filed through the web UI.
 * They are materialised by {@see \LitePic\Service\WebDav\DavIndex} when a
 * collection is listed, which is why this class exposes the two reconciliation
 * queries (`missingIn…` / `staleIn…`) rather than plain CRUD. Keeping the
 * mapping derived means the WebDAV view cannot drift out of sync with album
 * changes made elsewhere, and no other service needs to know WebDAV exists.
 *
 * `filename IS NULL` marks an empty placeholder — the 0-byte file Finder and
 * Office PUT to probe writability before sending real content.
 */
final class DavEntryRepository
{
    /**
     * @return array<int,array{id:int,album_id:int,name:string,filename:?string,created_at:int,updated_at:int}>
     */
    public function listByAlbum(int $albumId, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, album_id, name, filename, created_at, updated_at
             FROM dav_entries
             WHERE album_id = :aid
             ORDER BY name COLLATE NOCASE
             LIMIT :lim'
        );
        $stmt->bindValue(':aid', $albumId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'hydrate'], $stmt->fetchAll() ?: []);
    }

    /**
     * @return array{id:int,album_id:int,name:string,filename:?string,created_at:int,updated_at:int}|null
     */
    public function findByName(int $albumId, string $name): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, album_id, name, filename, created_at, updated_at
             FROM dav_entries WHERE album_id = :aid AND name = :name LIMIT 1'
        );
        $stmt->execute([':aid' => $albumId, ':name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @return array{id:int,album_id:int,name:string,filename:?string,created_at:int,updated_at:int}|null
     */
    public function findByFilename(int $albumId, string $filename): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, album_id, name, filename, created_at, updated_at
             FROM dav_entries WHERE album_id = :aid AND filename = :fn LIMIT 1'
        );
        $stmt->execute([':aid' => $albumId, ':fn' => $filename]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Names already used in a collection, as a lookup set for name allocation.
     *
     * @return array<string,true>
     */
    public function takenNames(int $albumId): array
    {
        $stmt = Database::connection()->prepare('SELECT name FROM dav_entries WHERE album_id = :aid');
        $stmt->execute([':aid' => $albumId]);
        $taken = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
            $taken[(string)$name] = true;
        }
        return $taken;
    }

    /**
     * Insert a mapping. Returns null when the name is already taken in that
     * collection — the UNIQUE constraint is the concurrency guard, so two
     * parallel PUTs of the same name can't both win.
     */
    public function insert(int $albumId, string $name, ?string $filename): ?int
    {
        $now = time();
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO dav_entries (album_id, name, filename, created_at, updated_at)
                 VALUES (:aid, :name, :fn, :t, :t)'
            );
            $stmt->execute([':aid' => $albumId, ':name' => $name, ':fn' => $filename, ':t' => $now]);
        } catch (\PDOException $e) {
            return null;
        }
        return (int)Database::connection()->lastInsertId();
    }

    public function updateFilename(int $id, ?string $filename): void
    {
        Database::connection()
            ->prepare('UPDATE dav_entries SET filename = :fn, updated_at = :t WHERE id = :id')
            ->execute([':fn' => $filename, ':t' => time(), ':id' => $id]);
    }

    /**
     * Move / rename a mapping. Returns false when the target name is taken.
     */
    public function relocate(int $id, int $albumId, string $name): bool
    {
        try {
            Database::connection()
                ->prepare('UPDATE dav_entries SET album_id = :aid, name = :name, updated_at = :t WHERE id = :id')
                ->execute([':aid' => $albumId, ':name' => $name, ':t' => time(), ':id' => $id]);
        } catch (\PDOException $e) {
            return false;
        }
        return true;
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM dav_entries WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * @param int[] $ids
     */
    public function deleteMany(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::connection()
            ->prepare('DELETE FROM dav_entries WHERE id IN (' . $placeholders . ')')
            ->execute($ids);
    }

    /** Drop every mapping for a collection. Used when an album is deleted. */
    public function deleteByAlbum(int $albumId): void
    {
        Database::connection()
            ->prepare('DELETE FROM dav_entries WHERE album_id = :aid')
            ->execute([':aid' => $albumId]);
    }

    /**
     * Placeholders older than $olderThan, so an abandoned 0-byte probe doesn't
     * squat on a name forever.
     *
     * @return int[] Entry ids.
     */
    public function stalePlaceholderIds(int $olderThan): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM dav_entries WHERE filename IS NULL AND updated_at < :t'
        );
        $stmt->execute([':t' => $olderThan]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // ---- reconciliation -------------------------------------------------------

    /**
     * Images in an album that have no mapping row yet, in album display order.
     *
     * @return array<int,array{filename:string,original_name:string}>
     */
    public function missingInAlbum(int $albumId, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ai.filename AS filename, COALESCE(i.original_name, ai.filename) AS original_name
             FROM album_images ai
             JOIN images i ON i.filename = ai.filename
             LEFT JOIN dav_entries de ON de.album_id = :aid AND de.filename = ai.filename
             WHERE ai.album_id = :aid AND de.id IS NULL
             ORDER BY ai.sort_order ASC, ai.added_at ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':aid', $albumId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return self::hydrateCandidates($stmt->fetchAll() ?: []);
    }

    /**
     * Mappings in an album whose image is no longer a member of it — the image
     * was removed from the album (or deleted) through the web UI.
     *
     * @return int[] Entry ids.
     */
    public function staleInAlbum(int $albumId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT de.id
             FROM dav_entries de
             LEFT JOIN album_images ai ON ai.album_id = de.album_id AND ai.filename = de.filename
             WHERE de.album_id = :aid AND de.filename IS NOT NULL AND ai.filename IS NULL'
        );
        $stmt->execute([':aid' => $albumId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Images in no album that have no mapping in the unfiled collection,
     * newest first.
     *
     * @return array<int,array{filename:string,original_name:string}>
     */
    public function missingInUnfiled(int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.filename AS filename, COALESCE(i.original_name, i.filename) AS original_name
             FROM images i
             LEFT JOIN album_images ai ON ai.filename = i.filename
             LEFT JOIN dav_entries de ON de.album_id = 0 AND de.filename = i.filename
             WHERE ai.filename IS NULL AND de.id IS NULL
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return self::hydrateCandidates($stmt->fetchAll() ?: []);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{filename:string,original_name:string}>
     */
    private static function hydrateCandidates(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'filename' => (string)$row['filename'],
            'original_name' => (string)$row['original_name'],
        ], $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,album_id:int,name:string,filename:?string,created_at:int,updated_at:int}
     */
    private static function hydrate(array $row): array
    {
        $filename = $row['filename'] ?? null;
        return [
            'id' => (int)$row['id'],
            'album_id' => (int)$row['album_id'],
            'name' => (string)$row['name'],
            'filename' => is_string($filename) && $filename !== '' ? $filename : null,
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}

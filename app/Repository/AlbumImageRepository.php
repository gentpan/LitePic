<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Album ↔ image M:N junction.
 *
 * Inserts use INSERT OR IGNORE so re-adding an image to the same album is
 * a no-op rather than an error. Sort order is appended to the end (max+1)
 * unless the caller explicitly supplies one — keeps "drag to add" simple.
 */
final class AlbumImageRepository
{
    /**
     * Filenames belonging to an album, in display order.
     *
     * @return array<int, string>
     */
    public function listFilenames(int $albumId, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT filename FROM album_images
                 WHERE album_id = :a
              ORDER BY sort_order ASC, added_at ASC, filename ASC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . max(0, $offset);
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([':a' => $albumId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Reverse lookup — every album a given image is in (id + slug + name).
     * `slug` may be null for albums that opt out of a custom URL slug;
     * callers that need the public URL key should run it through
     * {@see \LitePic\Service\Album\AlbumService::urlKey()}.
     *
     * @return array<int, array{id:int, slug:?string, name:string}>
     */
    public function albumsForFilename(string $filename): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.id, a.slug, a.name
               FROM album_images ai
               JOIN albums a ON a.id = ai.album_id
              WHERE ai.filename = :f
           ORDER BY a.name ASC'
        );
        $stmt->execute([':f' => $filename]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $slug = $row['slug'] ?? null;
            if ($slug !== null && (string)$slug === '') $slug = null;
            $out[] = [
                'id'   => (int)$row['id'],
                'slug' => $slug !== null ? (string)$slug : null,
                'name' => (string)$row['name'],
            ];
        }
        return $out;
    }

    /**
     * Same shape as {@see albumsForFilename} but with the album's visibility
     * tier — used by ImageServeService to gate direct `/i/<filename>` access
     * against private/password album membership. Splitting the query keeps
     * the public-facing reverse-lookup (which legitimately needs the name)
     * from leaking visibility into surfaces that don't need it.
     *
     * @return array<int, array{id:int, slug:?string, visibility:string}>
     */
    public function visibilityFor(string $filename): array
    {
        if ($filename === '') return [];
        $stmt = Database::connection()->prepare(
            'SELECT a.id, a.slug, a.visibility
               FROM album_images ai
               JOIN albums a ON a.id = ai.album_id
              WHERE ai.filename = :f'
        );
        $stmt->execute([':f' => $filename]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $slug = $row['slug'] ?? null;
            if ($slug !== null && (string)$slug === '') $slug = null;
            $out[] = [
                'id'         => (int)$row['id'],
                'slug'       => $slug !== null ? (string)$slug : null,
                'visibility' => (string)$row['visibility'],
            ];
        }
        return $out;
    }

    public function contains(int $albumId, string $filename): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM album_images WHERE album_id = :a AND filename = :f LIMIT 1'
        );
        $stmt->execute([':a' => $albumId, ':f' => $filename]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Append a single image to an album. Idempotent — re-adding is a no-op.
     * Returns true iff a new row was actually inserted.
     */
    public function add(int $albumId, string $filename, ?int $sortOrder = null): bool
    {
        if ($filename === '') return false;
        $sortOrder ??= $this->nextSortOrder($albumId);
        $stmt = Database::connection()->prepare(
            'INSERT OR IGNORE INTO album_images
                (album_id, filename, sort_order, added_at)
             VALUES (:a, :f, :s, :t)'
        );
        $stmt->execute([
            ':a' => $albumId,
            ':f' => $filename,
            ':s' => $sortOrder,
            ':t' => time(),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Bulk-add. Returns the count actually inserted (skipped duplicates not
     * counted). Wrapped in a single transaction for speed on large picks.
     *
     * @param array<int, string> $filenames
     */
    public function addMany(int $albumId, array $filenames): int
    {
        $clean = array_values(array_filter(array_map('strval', $filenames), 'strlen'));
        if ($clean === []) return 0;

        $pdo = Database::connection();
        $next = $this->nextSortOrder($albumId);
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO album_images
                (album_id, filename, sort_order, added_at)
             VALUES (:a, :f, :s, :t)'
        );
        $now = time();
        $inserted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($clean as $filename) {
                $stmt->execute([
                    ':a' => $albumId, ':f' => $filename,
                    ':s' => $next, ':t' => $now,
                ]);
                if ($stmt->rowCount() > 0) {
                    $inserted++;
                    $next++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $inserted;
    }

    public function remove(int $albumId, string $filename): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM album_images WHERE album_id = :a AND filename = :f'
        );
        $stmt->execute([':a' => $albumId, ':f' => $filename]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int, string> $filenames
     */
    public function removeMany(int $albumId, array $filenames): int
    {
        $clean = array_values(array_filter(array_map('strval', $filenames), 'strlen'));
        if ($clean === []) return 0;
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = Database::connection()->prepare(
            "DELETE FROM album_images
              WHERE album_id = ? AND filename IN ($placeholders)"
        );
        $stmt->execute(array_merge([$albumId], $clean));
        return $stmt->rowCount();
    }

    /**
     * Replace the sort order with the supplied list. Filenames not in the
     * list are left untouched (so partial reorders are safe).
     *
     * @param array<int, string> $orderedFilenames
     */
    public function reorder(int $albumId, array $orderedFilenames): void
    {
        $clean = array_values(array_filter(array_map('strval', $orderedFilenames), 'strlen'));
        if ($clean === []) return;
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE album_images SET sort_order = :s
              WHERE album_id = :a AND filename = :f'
        );
        $pdo->beginTransaction();
        try {
            foreach ($clean as $i => $filename) {
                $stmt->execute([':a' => $albumId, ':f' => $filename, ':s' => $i]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Next sort_order = max + 1, or 0 if album is empty. Lets us append.
     */
    private function nextSortOrder(int $albumId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1
               FROM album_images WHERE album_id = :a'
        );
        $stmt->execute([':a' => $albumId]);
        return (int)$stmt->fetchColumn();
    }
}

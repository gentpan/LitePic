<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;

/**
 * Album metadata storage.
 *
 * Pure data-access layer — slug normalisation, password hashing, and
 * cross-cutting validation live in {@see \LitePic\Service\Album\AlbumService}.
 */
final class AlbumRepository
{
    private const SELECT = 'SELECT id, slug, name, description, cover_filename,
                                   COALESCE(cover_filename,
                                       (SELECT ai.filename FROM album_images ai
                                         WHERE ai.album_id = albums.id
                                         ORDER BY ai.sort_order ASC, ai.added_at ASC
                                         LIMIT 1)
                                   ) AS cover_effective,
                                   visibility, password_hash, embed_token,
                                   image_count, view_count, sort_order,
                                   created_at, updated_at
                            FROM albums';

    /**
     * @return array<int, array<string,mixed>>
     */
    public function all(): array
    {
        $rows = Database::connection()
            ->query(self::SELECT . ' ORDER BY sort_order DESC, created_at DESC')
            ->fetchAll() ?: [];
        return array_map([self::class, 'cast'], $rows);
    }

    /**
     * Visibility-filtered list — used by public sitemap / index pages.
     *
     * @param  array<int,string> $visibilities  e.g. ['public']
     * @return array<int, array<string,mixed>>
     */
    public function listByVisibility(array $visibilities): array
    {
        $visibilities = array_values(array_filter(array_unique($visibilities), 'is_string'));
        if ($visibilities === []) return [];
        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));

        $stmt = Database::connection()->prepare(
            self::SELECT . " WHERE visibility IN ($placeholders)
                             ORDER BY sort_order DESC, created_at DESC"
        );
        $stmt->execute($visibilities);
        $rows = $stmt->fetchAll() ?: [];
        return array_map([self::class, 'cast'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function findBySlug(string $slug): ?array
    {
        if ($slug === '') return null; // empty slug isn't a valid lookup
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    /**
     * Resolve an album by its public URL key — either the numeric id
     * (for albums with no slug) or a string slug.
     *
     *   - all-digit input (e.g. "5") → look up by id
     *   - anything else              → look up by slug
     *
     * We deliberately do NOT fall back from id to slug or vice versa: a
     * digit-only key means "the album whose id is N", and a slug-shaped
     * key means "the album whose slug is X". This keeps URLs unambiguous.
     */
    public function findByKey(string $key): ?array
    {
        if ($key === '') return null;
        if (ctype_digit($key)) {
            return $this->find((int)$key);
        }
        return $this->findBySlug($key);
    }

    /**
     * Insert and return the new id, or null on failure (e.g. UNIQUE slug clash).
     *
     * @param array{
     *   slug?:?string, name:string, description?:string, cover_filename?:?string,
     *   visibility?:string, password_hash?:?string, embed_token?:string,
     *   sort_order?:int
     * } $data
     */
    public function create(array $data): ?int
    {
        $now = time();
        $stmt = Database::connection()->prepare(
            'INSERT INTO albums
                (slug, name, description, cover_filename, visibility,
                 password_hash, embed_token, image_count, view_count,
                 sort_order, created_at, updated_at)
             VALUES
                (:slug, :name, :description, :cover, :visibility,
                 :password_hash, :embed_token, 0, 0,
                 :sort_order, :created_at, :updated_at)'
        );
        try {
            $slug = $data['slug'] ?? null;
            // Empty string → NULL so the UNIQUE constraint allows multiple
            // "no slug" rows (NULLs aren't equal to each other in SQLite).
            if ($slug === '') $slug = null;
            $stmt->execute([
                ':slug'          => $slug,
                ':name'          => $data['name'],
                ':description'   => (string)($data['description'] ?? ''),
                ':cover'         => $data['cover_filename'] ?? null,
                ':visibility'    => $data['visibility'] ?? 'public',
                ':password_hash' => $data['password_hash'] ?? null,
                ':embed_token'   => (string)($data['embed_token'] ?? ''),
                ':sort_order'    => (int)($data['sort_order'] ?? 0),
                ':created_at'    => $now,
                ':updated_at'    => $now,
            ]);
            return (int)Database::connection()->lastInsertId();
        } catch (\PDOException $_) {
            return null;
        }
    }

    /**
     * Partial update — pass only the keys you want to change. Returns true
     * iff one row was updated (id existed).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'slug', 'name', 'description', 'cover_filename', 'visibility',
            'password_hash', 'embed_token', 'sort_order',
        ];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => time()];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $data[$col];
            }
        }
        if ($sets === []) return false;
        $sets[] = 'updated_at = :updated_at';

        $stmt = Database::connection()->prepare(
            'UPDATE albums SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        try {
            return $stmt->execute($params) && $stmt->rowCount() > 0;
        } catch (\PDOException $_) {
            return false;
        }
    }

    public function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM albums WHERE id = :id');
        return $stmt->execute([':id' => $id]) && $stmt->rowCount() > 0;
    }

    /**
     * Recompute image_count from album_images. Cheap COUNT — we run this
     * after add/remove ops instead of trusting incremental bookkeeping
     * (which would drift if an FK cascade fires). Intended to be called
     * from AlbumService, never directly from a controller.
     */
    public function refreshImageCount(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE albums
                SET image_count = (SELECT COUNT(*) FROM album_images WHERE album_id = :a),
                    updated_at  = :t
              WHERE id = :id'
        )->execute([':a' => $id, ':id' => $id, ':t' => time()]);
    }

    public function incrementViewCount(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE albums SET view_count = view_count + 1 WHERE id = :id'
        )->execute([':id' => $id]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        // slug is now nullable. Normalise empty strings → null too so callers
        // only have to handle one "no-slug" representation.
        $slug = $row['slug'] ?? null;
        if ($slug !== null && (string)$slug === '') $slug = null;
        return [
            'id'             => (int)$row['id'],
            'slug'           => $slug !== null ? (string)$slug : null,
            'name'           => (string)$row['name'],
            'description'    => (string)$row['description'],
            'cover_filename' => $row['cover_filename'] !== null ? (string)$row['cover_filename'] : null,
            // 显式封面 或 第一张图(供列表/公开页兜底显示);可能不存在(旧查询)
            'cover_effective' => isset($row['cover_effective']) && $row['cover_effective'] !== null ? (string)$row['cover_effective'] : null,
            'visibility'     => (string)$row['visibility'],
            'password_hash'  => $row['password_hash'] !== null ? (string)$row['password_hash'] : null,
            'embed_token'    => (string)$row['embed_token'],
            'image_count'    => (int)$row['image_count'],
            'view_count'     => (int)$row['view_count'],
            'sort_order'     => (int)$row['sort_order'],
            'created_at'     => (int)$row['created_at'],
            'updated_at'     => (int)$row['updated_at'],
        ];
    }
}

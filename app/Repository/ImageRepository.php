<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * Read/write the `images` table — the canonical source of truth for the
 * image library since the SQLite migration.
 *
 * Identifiers are stored as relative paths under `uploads/` (e.g.
 * `2026/04/abc123.jpg`). Companion files (`.thumb.*`, `.webp`, `.avif`)
 * are tracked as boolean flags on the parent row, not as separate entries.
 */
final class ImageRepository
{
    private const ALL_COLUMNS = 'id, filename, original_name, mime, ext, size, width, height, hash, created_at, has_thumbnail, has_webp, has_avif, remote_synced, watermarked, view_count';

    public function find(string $filename): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM images WHERE filename = :f'
        );
        $stmt->execute([':f' => $filename]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM images WHERE id = :i'
        );
        $stmt->execute([':i' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function findByHash(string $hash): ?array
    {
        if ($hash === '') return null;
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::ALL_COLUMNS . ' FROM images WHERE hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        return $row === false ? null : self::cast($row);
    }

    public function exists(string $filename): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM images WHERE filename = :f');
        $stmt->execute([':f' => $filename]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Insert a new row. `filename` and `created_at` are required; everything
     * else is optional. Returns the new row id (0 on conflict — caller
     * should check with `find()` if collisions are possible).
     */
    public function insert(array $data): int
    {
        $row = $this->normalizeForWrite($data);
        if (!isset($row['filename']) || $row['filename'] === '') {
            throw new \InvalidArgumentException('ImageRepository::insert() requires `filename`.');
        }
        $row['created_at'] = $row['created_at'] ?? time();

        $columns = array_keys($row);
        $placeholders = array_map(static fn($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT OR IGNORE INTO images (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = Database::connection()->prepare($sql);
        $params = [];
        foreach ($row as $k => $v) {
            $params[':' . $k] = $v;
        }
        $stmt->execute($params);
        return (int)Database::connection()->lastInsertId();
    }

    public function update(string $filename, array $data): void
    {
        $row = $this->normalizeForWrite($data);
        unset($row['filename']); // can't rename via update()
        if ($row === []) return;

        $assignments = [];
        $params = [':f' => $filename];
        foreach ($row as $k => $v) {
            $assignments[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        $sql = 'UPDATE images SET ' . implode(', ', $assignments) . ' WHERE filename = :f';
        Database::connection()->prepare($sql)->execute($params);
    }

    /**
     * Update boolean variant flags only. Convenience wrapper around update().
     *
     * @param array<string,bool> $flags  e.g. ['has_webp' => true, 'has_avif' => false]
     */
    public function setFlags(string $filename, array $flags): void
    {
        $writable = ['has_thumbnail', 'has_webp', 'has_avif', 'remote_synced', 'watermarked'];
        $payload = [];
        foreach ($flags as $key => $value) {
            if (in_array($key, $writable, true)) {
                $payload[$key] = $value ? 1 : 0;
            }
        }
        if ($payload !== []) {
            $this->update($filename, $payload);
        }
    }

    public function incrementViews(string $filename, int $by = 1): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE images SET view_count = view_count + :n WHERE filename = :f'
        );
        $stmt->execute([':n' => $by, ':f' => $filename]);
    }

    public function delete(string $filename): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM images WHERE filename = :f');
        $stmt->execute([':f' => $filename]);
    }

    public function totalCount(string $query = ''): int
    {
        $query = trim($query);
        if ($query === '') {
            return (int)Database::connection()->query('SELECT COUNT(*) FROM images')->fetchColumn();
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM images WHERE filename LIKE :q OR original_name LIKE :q'
        );
        $stmt->execute([':q' => '%' . $query . '%']);
        return (int)$stmt->fetchColumn();
    }

    public function totalSize(): int
    {
        return (int)Database::connection()->query('SELECT COALESCE(SUM(size), 0) FROM images')->fetchColumn();
    }

    /**
     * Paginate identifiers. Returns ['items' => [...rows...], 'total' => int].
     */
    public function paginate(int $page, int $perPage, string $sort = 'date-desc', string $query = ''): array
    {
        $orderBy = $this->orderClause($sort);
        $params = [];
        $where = '';
        $query = trim($query);
        if ($query !== '') {
            $where = ' WHERE filename LIKE :q OR original_name LIKE :q';
            $params[':q'] = '%' . $query . '%';
        }

        $perPage = max(1, min(500, $perPage));
        $countSql = 'SELECT COUNT(*) FROM images' . $where;
        $stmt = Database::connection()->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $totalPages = $total === 0 ? 0 : (int)ceil($total / $perPage);
        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT ' . self::ALL_COLUMNS . ' FROM images' . $where . ' ORDER BY ' . $orderBy
             . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Return all rows (no pagination). Useful for export and full sweeps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(string $sort = 'date-desc', string $query = ''): array
    {
        $orderBy = $this->orderClause($sort);
        $params = [];
        $where = '';
        $query = trim($query);
        if ($query !== '') {
            $where = ' WHERE filename LIKE :q OR original_name LIKE :q';
            $params[':q'] = '%' . $query . '%';
        }
        $sql = 'SELECT ' . self::ALL_COLUMNS . ' FROM images' . $where . ' ORDER BY ' . $orderBy;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    /**
     * Return just the filename identifiers (back-compat for older code that
     * processes a list of identifiers and re-fetches per-image metadata).
     *
     * @return array<int, string>
     */
    public function listIdentifiers(string $sort = 'date-desc'): array
    {
        $orderBy = $this->orderClause($sort);
        $sql = 'SELECT filename FROM images ORDER BY ' . $orderBy;
        return Database::connection()->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function orderClause(string $sort): string
    {
        return match ($sort) {
            'name-asc' => 'filename ASC',
            'name-desc' => 'filename DESC',
            'size-asc' => 'size ASC, id ASC',
            'size-desc' => 'size DESC, id DESC',
            'date-asc' => 'created_at ASC, id ASC',
            'date-desc', '' => 'created_at DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };
    }

    /**
     * @return array<string,mixed> only the writable, recognised columns
     */
    private function normalizeForWrite(array $data): array
    {
        $allowed = [
            'filename', 'original_name', 'mime', 'ext', 'size',
            'width', 'height', 'hash', 'created_at',
            'has_thumbnail', 'has_webp', 'has_avif',
            'remote_synced', 'watermarked', 'view_count',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if (in_array($key, ['has_thumbnail', 'has_webp', 'has_avif', 'remote_synced', 'watermarked'], true)) {
                $value = $value ? 1 : 0;
            } elseif (in_array($key, ['size', 'width', 'height', 'created_at', 'view_count'], true)) {
                $value = $value === null ? null : (int)$value;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function cast(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)($row['size'] ?? 0);
        $row['width'] = isset($row['width']) ? (int)$row['width'] : null;
        $row['height'] = isset($row['height']) ? (int)$row['height'] : null;
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['has_thumbnail'] = (bool)($row['has_thumbnail'] ?? false);
        $row['has_webp'] = (bool)($row['has_webp'] ?? false);
        $row['has_avif'] = (bool)($row['has_avif'] ?? false);
        $row['remote_synced'] = (bool)($row['remote_synced'] ?? false);
        $row['watermarked'] = (bool)($row['watermarked'] ?? false);
        $row['view_count'] = (int)($row['view_count'] ?? 0);
        return $row;
    }
}

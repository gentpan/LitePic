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

    public function findByHashWithBackfill(string $hash): ?array
    {
        if ($hash === '') return null;
        $row = $this->findByHash($hash);
        if ($row !== null) return $row;

        foreach ($this->listAll() as $candidate) {
            if ((string)($candidate['hash'] ?? '') !== '') continue;
            $filename = (string)($candidate['filename'] ?? '');
            if ($filename === '') continue;
            $path = \LitePic\Service\Image\PathService::resolveFilePath($filename);
            if (!is_file($path) || !is_readable($path)) continue;
            $actual = @sha1_file($path);
            if (!is_string($actual) || $actual === '') continue;
            $this->update($filename, ['hash' => $actual]);
            if (hash_equals($actual, $hash)) {
                return $this->find($filename) ?? self::cast(array_merge($candidate, ['hash' => $actual]));
            }
        }

        return null;
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

    public function recordViewRequest(string $filename, string $referer = '', int $by = 1): void
    {
        $by = max(1, $by);
        [$sourceKey, $sourceUrl, $sourceHost] = self::normalizeRequestSource($referer);
        $now = time();

        Database::transaction(static function (PDO $pdo) use ($filename, $by, $sourceKey, $sourceUrl, $sourceHost, $now): void {
            $stmt = $pdo->prepare('UPDATE images SET view_count = view_count + :n WHERE filename = :f');
            $stmt->execute([':n' => $by, ':f' => $filename]);
            if ($stmt->rowCount() < 1) {
                return;
            }

            $source = $pdo->prepare(
                'INSERT INTO image_request_sources
                    (filename, source_key, source_url, source_host, request_count, last_requested_at)
                 VALUES
                    (:filename, :source_key, :source_url, :source_host, :count, :last_requested_at)
                 ON CONFLICT(filename, source_key) DO UPDATE SET
                    request_count = request_count + :count,
                    last_requested_at = :last_requested_at'
            );
            $source->execute([
                ':filename' => $filename,
                ':source_key' => $sourceKey,
                ':source_url' => $sourceUrl,
                ':source_host' => $sourceHost,
                ':count' => $by,
                ':last_requested_at' => $now,
            ]);
        });
    }

    /**
     * Sum of view_count across the whole library — used by the stats page
     * "图片请求" total. Cheap (full scan of one column).
     */
    public function totalViews(): int
    {
        return (int)Database::connection()
            ->query('SELECT COALESCE(SUM(view_count), 0) FROM images')
            ->fetchColumn();
    }

    /**
     * Most-requested images, ordered by view_count desc. Returns rows
     * with `filename`, `original_name`, and `view_count` only — callers
     * that need URLs/sizes should hydrate via ImageInfo.
     *
     * @return array<int, array{filename:string, original_name:string, view_count:int, source_url:string, source_host:string, source_count:int}>
     */
    public function topByViews(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT
                    i.filename,
                    i.original_name,
                    i.view_count,
                    COALESCE(src.source_url, \'\') AS source_url,
                    COALESCE(src.source_host, \'\') AS source_host,
                    COALESCE(src.request_count, 0) AS source_count
                FROM images i
                LEFT JOIN image_request_sources src
                  ON src.id = (
                      SELECT s.id
                      FROM image_request_sources s
                      WHERE s.filename = i.filename
                      ORDER BY s.request_count DESC, s.last_requested_at DESC
                      LIMIT 1
                  )
                WHERE i.view_count > 0
                ORDER BY i.view_count DESC, i.created_at DESC
                LIMIT ' . $limit;
        $rows = Database::connection()->query($sql)->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'filename' => (string)$r['filename'],
                'original_name' => (string)($r['original_name'] ?? $r['filename']),
                'view_count' => (int)$r['view_count'],
                'source_url' => (string)($r['source_url'] ?? ''),
                'source_host' => (string)($r['source_host'] ?? ''),
                'source_count' => (int)($r['source_count'] ?? 0),
            ];
        }
        return $out;
    }

    public function delete(string $filename): void
    {
        Database::connection()
            ->prepare('DELETE FROM image_request_sources WHERE filename = :f')
            ->execute([':f' => $filename]);
        $stmt = Database::connection()->prepare('DELETE FROM images WHERE filename = :f');
        $stmt->execute([':f' => $filename]);
    }

    /**
     * @return array{0:string,1:string,2:string} [sourceKey, sourceUrl, sourceHost]
     */
    private static function normalizeRequestSource(string $referer): array
    {
        $referer = trim($referer);
        if ($referer === '') {
            return [sha1('direct'), '', ''];
        }

        $referer = substr($referer, 0, 2048);
        $parts = @parse_url($referer);
        if (is_array($parts) && !empty($parts['host'])) {
            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $scheme = 'https';
            }
            $host = strtolower((string)$parts['host']);
            $path = (string)($parts['path'] ?? '');
            $url = $scheme . '://' . $host . $path;
            return [sha1($url), $url, $host];
        }

        $fallback = substr(preg_replace('/\s+/', ' ', $referer) ?? $referer, 0, 512);
        return [sha1($fallback), $fallback, ''];
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

    /**
     * `listIdentifiers` wrapped with a swallow-and-log fallback. Used
     * by templates that need a never-throws contract — better to render
     * an empty gallery than crash the whole page on a transient PDO blip.
     *
     * @return array<int, string>
     */
    public function listIdentifiersSafe(string $sort = 'date-desc'): array
    {
        try {
            return $this->listIdentifiers($sort);
        } catch (\Throwable $e) {
            \LitePic\Core\Logger::error('Error listing image identifiers', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Set/clear `original_name` for an image. Inserts a minimal row if
     * the image isn't tracked yet (size + mtime read from disk).
     */
    public function recordOriginalName(string $systemName, string $originalName): void
    {
        $normalized = \LitePic\Service\Image\PathService::normalizeIdentifier($systemName);
        $filename = $normalized !== '' ? $normalized : basename($systemName);
        if ($filename === '') return;

        if ($this->exists($filename)) {
            $this->update($filename, ['original_name' => $originalName]);
            return;
        }
        $absolute = \LitePic\Service\Image\PathService::resolveFilePath($filename);
        $size = is_file($absolute) ? (int)@filesize($absolute) : 0;
        $mtime = is_file($absolute) ? (int)@filemtime($absolute) : time();

        $this->insert([
            'filename' => $filename,
            'original_name' => $originalName,
            'ext' => strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)),
            'size' => $size,
            'created_at' => $mtime,
        ]);
    }

    /**
     * Return the human-readable original_name for `$systemName`,
     * or null if there's no record / the column is empty.
     */
    public function originalNameFor(string $systemName): ?string
    {
        $normalized = \LitePic\Service\Image\PathService::normalizeIdentifier($systemName);
        $filename = $normalized !== '' ? $normalized : basename($systemName);
        if ($filename === '') return null;
        $row = $this->find($filename);
        if ($row === null) return null;
        $original = $row['original_name'] ?? null;
        return ($original === null || $original === '') ? null : (string)$original;
    }

    /**
     * Paginated/searched query that returns the API-card shape used by
     * /api/v1/list and the gallery page. Hydration goes through
     * ImageInfo so dimensions/format/url are filled in once per row.
     *
     * @return array{items: array<int,array<string,mixed>>, pagination: array<string,int>}
     */
    public function queryForApi(int $page = 1, int $perPage = 20, string $query = '', string $sort = 'date-desc', bool $all = false): array
    {
        $info = new \LitePic\Service\Image\ImageInfo($this);

        $toItems = static function (array $names) use ($info): array {
            $items = [];
            foreach ($names as $name) {
                $row = $info->get((string)$name);
                if ($row === null) continue;
                $items[] = [
                    'filename' => (string)$row['filename'],
                    'original_name' => (string)($row['original_name'] ?? $row['filename']),
                    'url' => (string)$row['url'],
                    'thumb_url' => (string)($row['thumb_url'] ?? $row['url']),
                    'size' => (int)($row['size'] ?? 0),
                    'size_text' => \LitePic\Core\Format::filesize((int)($row['size'] ?? 0)),
                    'dimensions' => (string)($row['dimensions'] ?? ''),
                    'width' => (int)($row['width'] ?? 0),
                    'height' => (int)($row['height'] ?? 0),
                    'format' => (string)($row['format'] ?? ''),
                    'time' => (int)($row['time'] ?? 0),
                    'time_text' => date('Y-m-d H:i', (int)($row['time'] ?? time())),
                    'request_count' => (int)($row['request_count'] ?? 0),
                ];
            }
            return $items;
        };

        if ($all) {
            $rows = $this->listAll($sort, $query);
            $names = array_map(static fn ($r) => (string)$r['filename'], $rows);
            $total = count($names);
            return [
                'items' => $toItems($names),
                'pagination' => [
                    'page' => 1,
                    'per_page' => $total > 0 ? $total : 0,
                    'total' => $total,
                    'total_pages' => $total > 0 ? 1 : 0,
                ],
            ];
        }

        $pageData = $this->paginate($page, $perPage, $sort, $query);
        $names = array_map(static fn ($r) => (string)$r['filename'], $pageData['items']);
        return [
            'items' => $toItems($names),
            'pagination' => [
                'page' => $pageData['page'],
                'per_page' => $pageData['per_page'],
                'total' => $pageData['total'],
                'total_pages' => $pageData['total_pages'],
            ],
        ];
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

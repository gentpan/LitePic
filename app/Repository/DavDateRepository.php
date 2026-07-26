<?php
declare(strict_types=1);

namespace LitePic\Repository;

use LitePic\Core\Database;
use PDO;

/**
 * Year / month buckets for the read-only `按日期` WebDAV view.
 *
 * Buckets come from the *identifier* (`2026/07/AbCdEf123456.jpg`) rather than
 * from `created_at`, because the view mirrors where the file physically lives.
 * Those can disagree: an image imported from an existing directory keeps the
 * path it was found at while getting an import-time `created_at`, and browsing
 * by date is only useful if it matches what an `ls` on the server would show.
 *
 * Images whose identifier has no `YYYY/MM/` prefix — flat files picked up by the
 * directory-scan importer — are simply absent from this view. They remain fully
 * visible in the album and unfiled collections, which are the writable ones.
 */
final class DavDateRepository
{
    /** Matches `dddd/dd/` at the start of an identifier. */
    private const DATED = "filename GLOB '[0-9][0-9][0-9][0-9]/[0-9][0-9]/*'";

    /**
     * @return string[] Four-digit years, newest first.
     */
    public function years(): array
    {
        $stmt = Database::connection()->query(
            'SELECT DISTINCT substr(filename, 1, 4) AS bucket
             FROM images WHERE ' . self::DATED . '
             ORDER BY bucket DESC'
        );
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return string[] Two-digit months present in $year, newest first.
     */
    public function months(string $year): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT substr(filename, 6, 2) AS bucket
             FROM images WHERE ' . self::DATED . ' AND substr(filename, 1, 4) = :year
             ORDER BY bucket DESC'
        );
        $stmt->execute([':year' => $year]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function yearExists(string $year): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM images WHERE ' . self::DATED . ' AND substr(filename, 1, 4) = :year LIMIT 1'
        );
        $stmt->execute([':year' => $year]);
        return (bool)$stmt->fetchColumn();
    }

    public function monthExists(string $year, string $month): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM images
             WHERE ' . self::DATED . ' AND substr(filename, 1, 4) = :year AND substr(filename, 6, 2) = :month
             LIMIT 1'
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Image rows stored under `$year/$month/`, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function imagesIn(string $year, string $month, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT filename, original_name, mime, ext, size, width, height, created_at, has_thumbnail
             FROM images
             WHERE ' . self::DATED . ' AND substr(filename, 1, 4) = :year AND substr(filename, 6, 2) = :month
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':year', $year);
        $stmt->bindValue(':month', $month);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}

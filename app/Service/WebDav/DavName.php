<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * Derivation of client-visible names from LitePic's own identifiers.
 *
 * Two naming problems, one rule set:
 *
 *   Files — `images.original_name` is what the uploader called it. It is not
 *   unique, not path-safe, and may carry an extension that no longer matches
 *   the stored bytes (upload rewrites the suffix when the declared type and the
 *   detected MIME disagree). The visible name is therefore *derived*: sanitised
 *   stem of the original name, plus the extension of the stored identifier.
 *
 *   Collections — album titles are free text and not unique either, and two of
 *   the top-level names are reserved for the synthetic views.
 *
 * Collisions are resolved by appending the image identifier's stem (files) or
 * the album id (collections) rather than a sequence number. That keeps name
 * assignment a pure function of the row itself: a `(2)` suffix would depend on
 * iteration order, so the same image could be `holiday (2).jpg` in one listing
 * and `holiday (3).jpg` after an unrelated deletion — and a client that had
 * cached the old name would 404 on its next GET.
 */
final class DavName
{
    /** Bytes, matching what DavPath accepts and what Windows/macOS allow. */
    private const MAX_NAME_BYTES = 200;

    /**
     * Visible filename for an image.
     *
     * @param array<string,true> $taken Names already used in the collection.
     */
    public static function forImage(string $identifier, string $originalName, array $taken): string
    {
        $extension = strtolower((string)pathinfo($identifier, PATHINFO_EXTENSION));
        $identifierStem = (string)pathinfo($identifier, PATHINFO_FILENAME);
        $stem = self::sanitiseStem((string)pathinfo($originalName, PATHINFO_FILENAME));
        if ($stem === '') {
            $stem = $identifierStem;
        }

        $candidate = self::compose($stem, $extension);
        if (!isset($taken[$candidate])) {
            return $candidate;
        }

        // Disambiguate with the identifier stem, which is globally unique.
        $candidate = self::compose($stem . '-' . $identifierStem, $extension);
        if (!isset($taken[$candidate])) {
            return $candidate;
        }

        // The identifier alone. Only reachable if a name was hand-assigned to
        // exactly this string via MOVE, which the counter below then resolves.
        $candidate = self::compose($identifierStem, $extension);
        if (!isset($taken[$candidate])) {
            return $candidate;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = self::compose($identifierStem . '-' . $n, $extension);
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return self::compose($identifierStem . '-' . bin2hex(random_bytes(4)), $extension);
    }

    /**
     * Visible folder name for an album.
     *
     * @param array<string,true> $taken Names already used at the top level,
     *                                  including the reserved view names.
     */
    public static function forAlbum(int $albumId, string $albumName, array $taken): string
    {
        $stem = self::sanitiseStem($albumName);
        if ($stem === '') {
            $stem = '相册-' . $albumId;
        }

        if (!isset($taken[$stem])) {
            return $stem;
        }

        $candidate = self::truncate($stem . '-' . $albumId);
        if (!isset($taken[$candidate])) {
            return $candidate;
        }

        return '相册-' . $albumId;
    }

    /**
     * Names that can never belong to an album folder, so the synthetic views
     * always win the top level.
     *
     * @return array<string,true>
     */
    public static function reservedTopLevel(): array
    {
        return [
            DavPath::DIR_DATE => true,
            DavPath::DIR_UNFILED => true,
        ];
    }

    /**
     * Strip everything that would make a name unusable as a single path
     * segment, then trim to length. Returns '' when nothing usable remains,
     * which callers replace with an identifier-derived fallback.
     */
    public static function sanitiseStem(string $raw): string
    {
        // Take the last path component first: an original_name of
        // `C:\Users\me\pic.jpg` (what old Windows browsers sent) must not
        // contribute directory parts to the visible name.
        $raw = str_replace('\\', '/', $raw);
        $slash = strrpos($raw, '/');
        if ($slash !== false) {
            $raw = substr($raw, $slash + 1);
        }

        // Control characters, and the characters Windows refuses in filenames.
        $raw = preg_replace('/[\x00-\x1f\x7f<>:"|?*]+/u', '', $raw) ?? '';
        if (!self::isValidUtf8($raw)) {
            return '';
        }

        // Collapse runs of whitespace so a name can't rely on invisible
        // differences to look distinct from another.
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? '';
        $raw = trim($raw, " .\t\n\r\0\x0B");

        if ($raw === '' || $raw === '.' || $raw === '..') {
            return '';
        }

        $raw = self::truncate($raw);

        // Truncation can re-expose a trailing dot or space.
        $raw = rtrim($raw, ' .');

        return DavPath::isSafeSegment($raw) ? $raw : '';
    }

    private static function compose(string $stem, string $extension): string
    {
        $suffix = $extension === '' ? '' : '.' . $extension;
        $stem = self::truncate($stem, self::MAX_NAME_BYTES - strlen($suffix));
        $stem = rtrim($stem, ' .');
        if ($stem === '') {
            $stem = 'image';
        }
        return $stem . $suffix;
    }

    /**
     * Byte-bounded truncation that never splits a UTF-8 sequence — a half
     * character would fail the `preg_match('//u')` validity check downstream.
     */
    private static function truncate(string $value, ?int $maxBytes = null): string
    {
        $maxBytes ??= self::MAX_NAME_BYTES;
        if ($maxBytes < 1) {
            return '';
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        $cut = substr($value, 0, $maxBytes);
        // Drop trailing bytes until the result is valid UTF-8 again.
        for ($i = 0; $i < 4 && $cut !== '' && !self::isValidUtf8($cut); $i++) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }

    private static function isValidUtf8(string $value): bool
    {
        return $value === '' || preg_match('//u', $value) === 1;
    }
}

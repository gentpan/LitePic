<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * Parsing and rendering of WebDAV paths, and the security boundary for them.
 *
 * Two representations are in play and they must not be confused:
 *
 *   *internal path* — `/`, `/风景照`, `/风景照/holiday.jpg`. Decoded UTF-8,
 *   always leading-slash, never trailing-slash (except the root, which is
 *   exactly `/`). This is what every other Dav* class takes and returns, and
 *   what gets stored in `dav_locks.path`.
 *
 *   *href* — what goes into `<D:href>` and `Destination`. Mount prefix plus
 *   percent-encoded segments, with a trailing slash on collections. RFC 4918
 *   §8.3 lets a server return either absolute URIs or absolute paths; absolute
 *   paths are used here because they stay correct behind a reverse proxy that
 *   rewrites scheme or host.
 *
 * Rejected outright (as 400, never sanitised into something "close enough"):
 * `.` and `..` segments, empty segments, backslashes, control characters,
 * NUL bytes, and anything longer than {@see self::MAX_SEGMENT}. Silent
 * sanitisation is how directory traversal gets in — a name that isn't exactly
 * representable is an error, not something to guess at.
 */
final class DavPath
{
    /** Mount point. Kept fixed so links, docs and client configs stay stable. */
    public const MOUNT = '/dav';

    private const MAX_SEGMENT = 255;
    private const MAX_DEPTH = 8;

    /**
     * Reserved top-level collection names. Album folders that would collide
     * with one of these get a disambiguating suffix instead (see DavTree).
     */
    public const DIR_DATE = '按日期';
    public const DIR_UNFILED = '未分类';

    /**
     * Internal path of the current request, or null when the URI escapes the
     * mount or contains something unrepresentable.
     */
    public static function fromRequestUri(string $requestUri): ?string
    {
        $raw = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return self::fromAbsolutePath($raw);
    }

    /**
     * Internal path from an absolute URL path (`/dav/x/y.jpg`) or a full URL
     * (`https://host/dav/x/y.jpg`). Full URLs occur in `Destination` headers,
     * which most clients send absolute.
     */
    public static function fromAbsolutePath(string $absolute): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $absolute) === 1) {
            $parsed = parse_url($absolute, PHP_URL_PATH);
            if (!is_string($parsed) || $parsed === '') {
                return null;
            }
            $absolute = $parsed;
        }

        if ($absolute === self::MOUNT) {
            return '/';
        }
        if (!str_starts_with($absolute, self::MOUNT . '/')) {
            return null;
        }

        $rest = substr($absolute, strlen(self::MOUNT));
        return self::normalise($rest);
    }

    /**
     * Validate and canonicalise an already-mount-relative path.
     * Returns null when any segment is unacceptable.
     */
    public static function normalise(string $relative): ?string
    {
        $trimmed = trim($relative, '/');
        if ($trimmed === '') {
            return '/';
        }

        $segments = [];
        foreach (explode('/', $trimmed) as $encoded) {
            if ($encoded === '') {
                // A `//` in the middle is a client bug we refuse rather than collapse.
                return null;
            }
            $segment = rawurldecode($encoded);
            if (!self::isSafeSegment($segment)) {
                return null;
            }
            $segments[] = $segment;
        }

        if (count($segments) > self::MAX_DEPTH) {
            return null;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * A single path segment is acceptable as a file or collection name.
     * Also used to vet names arriving from MKCOL / MOVE destinations and
     * names derived from album titles.
     */
    public static function isSafeSegment(string $segment): bool
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
        if (strlen($segment) > self::MAX_SEGMENT) {
            return false;
        }
        if (str_contains($segment, '/') || str_contains($segment, '\\')) {
            return false;
        }
        // Control characters and DEL. `[[:cntrl:]]` on raw bytes also rejects
        // truncated UTF-8 sequences that could decode differently downstream.
        if (preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
            return false;
        }
        if (!self::isValidUtf8($segment)) {
            return false;
        }
        // Trailing dots and spaces are unrepresentable on Windows clients and
        // are a classic way to shadow an existing name.
        return rtrim($segment, " .") === $segment;
    }

    private static function isValidUtf8(string $value): bool
    {
        return $value === '' || preg_match('//u', $value) === 1;
    }

    /** @return string[] Decoded segments; empty array for the root. */
    public static function segments(string $internal): array
    {
        $trimmed = trim($internal, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /** Last segment, or '' for the root. */
    public static function basename(string $internal): string
    {
        $segments = self::segments($internal);
        return $segments === [] ? '' : (string)end($segments);
    }

    /** Parent internal path; the root's parent is itself. */
    public static function parent(string $internal): string
    {
        $segments = self::segments($internal);
        if (count($segments) <= 1) {
            return '/';
        }
        array_pop($segments);
        return '/' . implode('/', $segments);
    }

    public static function join(string $parent, string $name): string
    {
        return $parent === '/' ? '/' . $name : rtrim($parent, '/') . '/' . $name;
    }

    /** True when $descendant is at or below $ancestor. */
    public static function isAtOrBelow(string $descendant, string $ancestor): bool
    {
        if ($ancestor === '/') {
            return true;
        }
        return $descendant === $ancestor
            || str_starts_with($descendant, rtrim($ancestor, '/') . '/');
    }

    /**
     * Internal path → `<D:href>` value. Collections get the trailing slash
     * that Windows Explorer and Finder rely on to tell a folder from a file
     * before reading `<D:resourcetype>`.
     */
    public static function href(string $internal, bool $isCollection): string
    {
        $encoded = array_map(
            static fn (string $segment): string => self::encodeSegment($segment),
            self::segments($internal)
        );
        $path = self::MOUNT . ($encoded === [] ? '/' : '/' . implode('/', $encoded));
        if ($isCollection && !str_ends_with($path, '/')) {
            $path .= '/';
        }
        return $path;
    }

    /**
     * Percent-encode one segment. `rawurlencode` escapes everything RFC 3986
     * calls unreserved-safe, then a few sub-delims are restored: clients quote
     * hrefs verbatim and over-escaping `(` `)` `!` `'` breaks name round-trips
     * on some of them (older Cyberduck, WinSCP).
     */
    public static function encodeSegment(string $segment): string
    {
        return str_replace(
            ['%21', '%27', '%28', '%29', '%2A', '%2C', '%3A', '%3B', '%3D', '%40', '%2B', '%24'],
            ['!', "'", '(', ')', '*', ',', ':', ';', '=', '@', '+', '$'],
            rawurlencode($segment)
        );
    }
}

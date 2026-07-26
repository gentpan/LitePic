<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * Response emission for WebDAV: status lines, `207 Multi-Status` documents,
 * and RFC 4918 error bodies.
 *
 * XML is built by string concatenation rather than DOM/XMLWriter. The output
 * shape here is small and fully known, LitePic only assumes `simplexml` is
 * present (used for parsing), and hand-building keeps the exact prefix
 * declarations that quirky clients depend on — notably `xmlns:D="DAV:"` with
 * the `D` prefix, which Windows Explorer historically mishandled when a
 * default namespace was used instead.
 *
 * Property names are handled as canonical `{namespace}local` strings so a
 * client asking for `Win32FileAttributes` in the Microsoft namespace can never
 * be confused with a hypothetical DAV property of the same local name.
 */
final class DavResponse
{
    public const NS_DAV = 'DAV:';
    public const NS_MICROSOFT = 'urn:schemas-microsoft-com:';
    public const NS_APACHE = 'http://apache.org/dav/props/';

    /** Namespace => prefix, declared on every multistatus root. */
    private const PREFIXES = [
        self::NS_DAV => 'D',
        self::NS_MICROSOFT => 'Z',
        self::NS_APACHE => 'A',
    ];

    private const STATUS_TEXT = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        301 => 'Moved Permanently',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        507 => 'Insufficient Storage',
    ];

    /** Canonical property name from a namespace and local name. */
    public static function prop(string $namespace, string $local): string
    {
        return '{' . $namespace . '}' . $local;
    }

    public static function statusLine(int $code): string
    {
        return 'HTTP/1.1 ' . $code . ' ' . (self::STATUS_TEXT[$code] ?? 'Unknown');
    }

    /**
     * Bare status response with no body. Used for 201/204/403/404/… where a
     * body would only be noise. `Content-Length: 0` is explicit because some
     * clients hang waiting for a body otherwise.
     *
     * @param array<string,string> $headers
     */
    public static function emitStatus(int $code, array $headers = []): void
    {
        http_response_code($code);
        // FrankenPHP/Caddy re-injects text/html when Content-Type is absent;
        // Finder then treats an empty OPTIONS/401 as a website and aborts.
        header('Content-Type: text/plain; charset=utf-8');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Length: 0');
    }

    /**
     * RFC 4918 §11-style error with a machine-readable condition element, e.g.
     * `lock-token-submitted` for a 423. Clients rarely surface these to the
     * user but rclone and Cyberduck log them, which makes debugging a live
     * mount far less guesswork.
     *
     * @param array<string,string> $headers
     */
    public static function emitError(int $code, string $condition = '', array $headers = []): void
    {
        http_response_code($code);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($condition === '') {
            header('Content-Length: 0');
            return;
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:error xmlns:D="DAV:"><D:' . $condition . '/></D:error>';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    /**
     * `207 Multi-Status`.
     *
     * Each entry is one `<D:response>`:
     *   href     — already-encoded href (see DavPath::href)
     *   found    — canonical prop name => inner XML (pre-escaped) or '' for an
     *              empty element; reported under `200 OK`
     *   notFound — canonical prop names reported under `404 Not Found`
     *   status   — when set, the entry becomes a bare `<D:status>` response
     *              instead of propstats (used by COPY/MOVE/DELETE failures)
     *
     * @param array<int,array{href:string,found?:array<string,string>,notFound?:string[],status?:int}> $entries
     */
    public static function emitMultiStatus(array $entries): void
    {
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<D:multistatus';
        foreach (self::PREFIXES as $namespace => $prefix) {
            $xml .= ' xmlns:' . $prefix . '="' . $namespace . '"';
        }
        $xml .= '>' . "\n";

        foreach ($entries as $entry) {
            $xml .= self::renderResponse($entry);
        }

        $xml .= '</D:multistatus>' . "\n";

        header('Content-Length: ' . strlen($xml));
        echo $xml;
    }

    /**
     * @param array{href:string,found?:array<string,string>,notFound?:string[],status?:int} $entry
     */
    private static function renderResponse(array $entry): string
    {
        $xml = '  <D:response><D:href>' . self::escape($entry['href']) . '</D:href>' . "\n";

        if (isset($entry['status'])) {
            $xml .= '    <D:status>' . self::statusLine((int)$entry['status']) . '</D:status>' . "\n";
            return $xml . '  </D:response>' . "\n";
        }

        $found = $entry['found'] ?? [];
        if ($found !== []) {
            $xml .= '    <D:propstat><D:prop>' . "\n";
            foreach ($found as $name => $inner) {
                $xml .= '      ' . self::renderProp($name, $inner) . "\n";
            }
            $xml .= '    </D:prop><D:status>' . self::statusLine(200) . '</D:status></D:propstat>' . "\n";
        }

        $notFound = $entry['notFound'] ?? [];
        if ($notFound !== []) {
            $xml .= '    <D:propstat><D:prop>' . "\n";
            foreach ($notFound as $name) {
                $xml .= '      ' . self::renderProp($name, null) . "\n";
            }
            $xml .= '    </D:prop><D:status>' . self::statusLine(404) . '</D:status></D:propstat>' . "\n";
        }

        return $xml . '  </D:response>' . "\n";
    }

    /**
     * One property element. `$inner === null` renders the empty form used in
     * `404` propstats, where the value must be omitted entirely.
     */
    private static function renderProp(string $canonical, ?string $inner): string
    {
        [$namespace, $local] = self::splitProp($canonical);
        $prefix = self::PREFIXES[$namespace] ?? null;

        // Unknown namespace: declare it inline on the element itself, which is
        // legal and avoids polluting the root with client-supplied namespaces.
        if ($prefix === null) {
            $tag = 'ns0:' . $local;
            $declaration = ' xmlns:ns0="' . self::escape($namespace) . '"';
        } else {
            $tag = $prefix . ':' . $local;
            $declaration = '';
        }

        if ($inner === null || $inner === '') {
            return '<' . $tag . $declaration . '/>';
        }
        return '<' . $tag . $declaration . '>' . $inner . '</' . $tag . '>';
    }

    /**
     * @return array{0:string,1:string} [namespace, local name]
     */
    public static function splitProp(string $canonical): array
    {
        if (preg_match('/^\{([^}]*)\}(.+)$/', $canonical, $m) === 1) {
            return [$m[1], $m[2]];
        }
        return [self::NS_DAV, $canonical];
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** RFC 1123 date, as required for `getlastmodified`. */
    public static function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }

    /** ISO 8601 date, as required for `creationdate`. */
    public static function isoDate(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

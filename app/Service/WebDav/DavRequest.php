<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * The incoming WebDAV request, normalised.
 *
 * Header access goes through {@see self::header()} rather than `getallheaders()`
 * at each call site because the same header arrives under different keys
 * depending on SAPI: FrankenPHP and PHP-FPM both populate `$_SERVER['HTTP_*']`,
 * but `Authorization` in particular is dropped by some FastCGI configurations
 * and only reappears as `REDIRECT_HTTP_AUTHORIZATION` — see {@see DavAuth}.
 *
 * Bodies are read lazily. PROPFIND / PROPPATCH / LOCK bodies are small and get
 * buffered in memory with a hard cap; PUT bodies are streamed straight to a
 * temp file by {@see self::streamBodyToTempFile()} and never held in memory,
 * because a 20MB photo through `file_get_contents('php://input')` would double
 * the memory cost of every upload for no reason.
 */
final class DavRequest
{
    /** Cap for XML request bodies. Real-world PROPFIND bodies are <2KB. */
    private const MAX_XML_BODY = 262144;

    private string $method;
    private string $path;
    /** @var array<string,string> Lowercase header name => value. */
    private array $headers;
    private ?string $xmlBody = null;

    /**
     * @param array<string,string> $headers
     */
    private function __construct(string $method, string $path, array $headers)
    {
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
    }

    /**
     * Build from PHP superglobals. Returns null when the request URI does not
     * resolve to a valid path inside the mount, which the caller answers 400.
     */
    public static function capture(): ?self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = DavPath::fromRequestUri((string)($_SERVER['REQUEST_URI'] ?? DavPath::MOUNT));
        if ($path === null) {
            return null;
        }
        return new self($method, $path, self::collectHeaders());
    }

    /**
     * @return array<string,string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = trim((string)$value);
                continue;
            }
            // Content-Length / Content-Type arrive without the HTTP_ prefix.
            if ($key === 'CONTENT_LENGTH' || $key === 'CONTENT_TYPE') {
                $headers[strtolower(str_replace('_', '-', $key))] = trim((string)$value);
            }
        }

        // FastCGI setups that strip Authorization often re-expose it prefixed.
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $fallback) {
            if (!isset($headers['authorization']) && !empty($_SERVER[$fallback])) {
                $headers['authorization'] = trim((string)$_SERVER[$fallback]);
            }
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * `Depth`, as 0, 1, or -1 for `infinity`. Defaults per RFC 4918: infinity
     * for PROPFIND and LOCK, 0 for everything else. COPY / MOVE on collections
     * treat anything but infinity as an error, which the handlers check.
     */
    public function depth(int $default = 0): int
    {
        $raw = $this->header('Depth');
        if ($raw === null) {
            return $default;
        }
        $raw = strtolower(trim($raw));
        if ($raw === 'infinity') {
            return -1;
        }
        if ($raw === '0') {
            return 0;
        }
        if ($raw === '1') {
            return 1;
        }
        return $default;
    }

    /**
     * `Overwrite` — true unless the client explicitly sent `F`.
     * RFC 4918 §10.6: absent means T.
     */
    public function overwrite(): bool
    {
        $raw = $this->header('Overwrite');
        return $raw === null || strtoupper(trim($raw)) !== 'F';
    }

    /**
     * `Destination` resolved to an internal path. Null when the header is
     * missing (→ 400) or points outside the mount (→ 502 per §9.9.4).
     */
    public function destination(): ?string
    {
        $raw = $this->header('Destination');
        if ($raw === null) {
            return null;
        }
        return DavPath::fromAbsolutePath($raw);
    }

    public function hasDestinationHeader(): bool
    {
        return $this->header('Destination') !== null;
    }

    /**
     * `Timeout` in seconds for LOCK, clamped to $max. `Second-4100000000` (the
     * "effectively infinite" value Finder likes to send) becomes $max rather
     * than an error.
     */
    public function lockTimeout(int $default, int $max): int
    {
        $raw = $this->header('Timeout');
        if ($raw === null) {
            return $default;
        }
        foreach (explode(',', $raw) as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === 'infinite') {
                return $max;
            }
            if (preg_match('/^second-(\d+)$/', $candidate, $m) === 1) {
                $seconds = (int)$m[1];
                return $seconds < 1 ? $default : min($seconds, $max);
            }
        }
        return $default;
    }

    /**
     * Lock tokens the client is presenting, from `Lock-Token` (UNLOCK) and from
     * every `(<token>)` group in an `If` header (all other methods). Returned
     * bare, without the angle brackets.
     *
     * The `If` header is a full conditional-expression grammar; only the
     * state-token form is honoured here. Entity-tag conditions are ignored,
     * which is the conservative direction: worst case a lock check passes that
     * a stricter server would have failed, and the operation still has to
     * satisfy the lock owner check.
     *
     * @return string[]
     */
    public function submittedLockTokens(): array
    {
        $tokens = [];

        $lockToken = $this->header('Lock-Token');
        if ($lockToken !== null && preg_match('/<([^>]+)>/', $lockToken, $m) === 1) {
            $tokens[] = trim($m[1]);
        }

        $if = $this->header('If');
        if ($if !== null && preg_match_all('/<([^>]+)>/', $if, $matches) > 0) {
            foreach ($matches[1] as $candidate) {
                $candidate = trim($candidate);
                // An If header interleaves resource-tags (URLs) with state
                // tokens; only the opaque lock tokens are of interest.
                if (str_starts_with($candidate, 'opaquelocktoken:') || str_starts_with($candidate, 'urn:uuid:')) {
                    $tokens[] = $candidate;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    public function contentLength(): ?int
    {
        $raw = $this->header('Content-Length');
        if ($raw === null || !ctype_digit($raw)) {
            return null;
        }
        return (int)$raw;
    }

    /**
     * True when the client sent a partial PUT. Answering these with 501 is
     * required — silently writing a range as if it were the whole body would
     * corrupt the stored file.
     */
    public function isPartialPut(): bool
    {
        return $this->header('Content-Range') !== null;
    }

    /**
     * Buffered XML request body, '' when there is none. Reads at most
     * MAX_XML_BODY bytes; a longer body yields '' so the handler falls back to
     * `allprop` behaviour rather than parsing a truncated document.
     */
    public function xmlBody(): string
    {
        if ($this->xmlBody !== null) {
            return $this->xmlBody;
        }

        $declared = $this->contentLength();
        if ($declared === 0) {
            return $this->xmlBody = '';
        }
        if ($declared !== null && $declared > self::MAX_XML_BODY) {
            return $this->xmlBody = '';
        }

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return $this->xmlBody = '';
        }
        $body = stream_get_contents($stream, self::MAX_XML_BODY);
        fclose($stream);

        return $this->xmlBody = is_string($body) ? $body : '';
    }

    /**
     * Stream the request body into a fresh temp file and return its path, or
     * null if a temp file could not be created. Caller owns the file and must
     * unlink it on every exit path.
     *
     * `php://input` is re-readable and unbuffered for PUT because PHP only
     * consumes the body itself for `application/x-www-form-urlencoded` and
     * `multipart/form-data` POSTs, so `post_max_size` does not apply here — the
     * effective limit is whatever the web server enforces plus LitePic's own
     * check further down the ingest path.
     *
     * @return array{0:?string,1:int} [temp path, bytes written]
     */
    public function streamBodyToTempFile(int $maxBytes): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'litepic_dav_');
        if ($temp === false) {
            return [null, 0];
        }

        $in = fopen('php://input', 'rb');
        if ($in === false) {
            @unlink($temp);
            return [null, 0];
        }
        $out = fopen($temp, 'wb');
        if ($out === false) {
            fclose($in);
            @unlink($temp);
            return [null, 0];
        }

        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                fclose($in);
                fclose($out);
                @unlink($temp);
                return [null, $written];
            }
            if (fwrite($out, $chunk) === false) {
                fclose($in);
                fclose($out);
                @unlink($temp);
                return [null, $written];
            }
        }

        fclose($in);
        fclose($out);

        return [$temp, $written];
    }
}

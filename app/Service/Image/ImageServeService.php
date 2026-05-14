<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Repository\AlbumImageRepository;
use LitePic\Repository\ImageRepository;
use LitePic\Service\Auth\AuthService;
use LitePic\Service\Hotlink\HotlinkProtection;

/**
 * Streams an uploaded image through PHP for the `/i/<identifier>` route.
 *
 * Two reasons we route through PHP instead of letting Apache/Nginx serve
 * uploads/ directly:
 *   1. View counting — every successful serve increments
 *      `images.view_count`, which feeds the stats page.
 *   2. Hotlink protection — when enabled, requests with disallowed
 *      Referers get a 403 before we touch the file.
 *
 * Either feature being on is enough to make ImageUrl point public links
 * at `/i/<identifier>` instead of the raw `/uploads/<identifier>` path.
 */
final class ImageServeService
{
    private ImageRepository $repo;
    private HotlinkProtection $hotlink;

    public function __construct(?ImageRepository $repo = null, ?HotlinkProtection $hotlink = null)
    {
        $this->repo = $repo ?? new ImageRepository();
        $this->hotlink = $hotlink ?? new HotlinkProtection();
    }

    /**
     * Globally enabled when EITHER view counting OR hotlink protection
     * is configured on. Used by ImageUrl to decide between `/i/...`
     * (PHP-served) and `/uploads/...` (web-server-served) URLs.
     */
    public static function isRoutedThroughPhp(): bool
    {
        return self::isViewCounterEnabled() || self::isHotlinkEnabled();
    }

    public static function isViewCounterEnabled(): bool
    {
        return defined('IMAGE_VIEW_COUNTER_ENABLED') && IMAGE_VIEW_COUNTER_ENABLED;
    }

    public static function isHotlinkEnabled(): bool
    {
        return defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED;
    }

    /**
     * Resolve the identifier, enforce the hotlink gate (if on), increment
     * view_count (if on), and stream the file. Sends its own status
     * codes — caller should not echo anything else.
     */
    public function serve(string $identifier): void
    {
        $identifier = PathService::normalizeIdentifier(rawurldecode($identifier));
        if ($identifier === '') {
            http_response_code(404);
            echo 'Image not found';
            return;
        }

        $path = PathService::resolveFilePath($identifier);
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Image not found';
            return;
        }

        // Album visibility gate — if every album that owns this image is
        // private/password-protected, refuse to serve directly unless the
        // requester is admin or carries a matching password cookie. Images
        // not in any album, or in at least one public/unlisted album, stay
        // freely fetchable (the "additive tag" intent of album membership).
        if (!self::isAlbumAccessAllowed($identifier)) {
            // 404 not 403 — same response as a missing image so attackers
            // can't enumerate which filenames belong to restricted albums.
            http_response_code(404);
            echo 'Image not found';
            return;
        }

        if (self::isHotlinkEnabled() && !$this->hotlink->isRequestAllowed()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Hotlink denied';
            return;
        }

        if (self::isViewCounterEnabled() && !self::isCountableRequest()) {
            // Skip counting for HEAD / range / If-Modified-Since revalidation
            // so the number reflects actual image displays, not cache pings.
        } elseif (self::isViewCounterEnabled()) {
            try {
                $this->repo->recordViewRequest($identifier, (string)($_SERVER['HTTP_REFERER'] ?? ''));
            } catch (\Throwable $e) {
                // Counter is best-effort — don't fail the image serve.
                error_log('ImageServeService: view increment failed for ' . $identifier . ': ' . $e->getMessage());
            }
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($path);
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $size = (int)@filesize($path);
        header('Content-Type: ' . $mime);
        if ($size > 0) {
            header('Content-Length: ' . (string)$size);
        }
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        readfile($path);
    }

    /**
     * Album-membership-based access gate. Returns true if the requester is
     * allowed to fetch this image:
     *   - image not in any album → public (current behaviour, unchanged)
     *   - image in any public/unlisted album → public
     *   - image only in private/password albums → admin OR matching
     *     `lp_album_<key>` HMAC cookie required
     *
     * Best-effort: any DB error returns true so a broken albums table can't
     * black-hole the entire image-serving path.
     */
    private static function isAlbumAccessAllowed(string $identifier): bool
    {
        try {
            $memberships = (new AlbumImageRepository())->visibilityFor($identifier);
        } catch (\Throwable $_) {
            return true;
        }
        if ($memberships === []) return true;

        foreach ($memberships as $m) {
            $v = $m['visibility'];
            if ($v === 'public' || $v === 'unlisted') return true;
        }
        // Every owning album is restricted (private or password). Admin
        // always wins; otherwise check whether the caller already passed
        // the password gate for any of these albums (cookie signed by
        // public_album.php on successful unlock).
        if ((new AuthService())->isAdmin()) return true;

        $secret = (string)\LitePic\Core\Config::get('ADMIN_SESSION_SECRET', '');
        if ($secret === '') return false; // no signing secret → no cookie can be valid

        foreach ($memberships as $m) {
            if ($m['visibility'] !== 'password') continue;
            $cookieKey = $m['slug'] !== null ? $m['slug'] : (string)$m['id'];
            $cookieName = 'lp_album_' . $cookieKey;
            $raw = (string)($_COOKIE[$cookieName] ?? '');
            if ($raw === '') continue;
            [$expBlob, $sig] = array_pad(explode('.', $raw, 2), 2, '');
            if ($sig === '' || !ctype_digit($expBlob)) continue;
            $expected = hash_hmac('sha256', $cookieKey . ':' . $expBlob, $secret);
            if (hash_equals($expected, $sig) && (int)$expBlob > time()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter out browser cache revalidation pings — we only count a hit
     * when the response will actually be a fresh 200 with the bytes.
     */
    private static function isCountableRequest(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') return false;
        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) return false;
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH'])) return false;
        if (!empty($_SERVER['HTTP_RANGE'])) return false;
        return true;
    }
}

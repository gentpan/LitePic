<?php
declare(strict_types=1);

namespace LitePic\Service\Hotlink;

/**
 * Referer-based hotlink protection. When enabled, image requests are
 * routed through `/i/<identifier>` (image.php → ImageServeService),
 * which calls `isRequestAllowed()` to enforce the referer allowlist
 * before streaming the file.
 *
 * The allowlist is the union of:
 *   - The host parsed out of SITE_URL
 *   - The current request's HTTP_HOST (so the bound domain works
 *     even if SITE_URL hasn't been updated yet)
 *   - HOTLINK_ALLOWED_DOMAINS from .env (comma-separated)
 */
final class HotlinkProtection
{
    public function isEnabled(): bool
    {
        return defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED;
    }

    public function isRequestAllowed(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return defined('HOTLINK_ALLOW_EMPTY_REFERER') && HOTLINK_ALLOW_EMPTY_REFERER;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        if (!is_string($refererHost) || $refererHost === '') {
            return false;
        }

        foreach ($this->allowedHosts() as $allowed) {
            if (self::hostMatches($refererHost, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];
        $siteHost = parse_url((string)(defined('SITE_URL') ? SITE_URL : ''), PHP_URL_HOST);
        if (is_string($siteHost) && $siteHost !== '') {
            $hosts[] = $siteHost;
        }
        $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($requestHost !== '') {
            $hosts[] = $requestHost;
        }
        if (defined('HOTLINK_ALLOWED_DOMAINS') && is_array(HOTLINK_ALLOWED_DOMAINS)) {
            $hosts = array_merge($hosts, HOTLINK_ALLOWED_DOMAINS);
        }

        $normalized = [];
        foreach ($hosts as $host) {
            $h = self::normalizeHost((string)$host);
            if ($h !== '') {
                $normalized[$h] = true;
            }
        }
        return array_keys($normalized);
    }

    /**
     * Strip user-info, brackets (IPv6), port, and trailing dots so two
     * cosmetically different host strings compare equal.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') return '';
        if (str_contains($host, '@')) {
            $host = substr($host, strrpos($host, '@') + 1);
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 1, $end - 1);
        }
        $colon = strpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }
        return trim($host, '.');
    }

    /**
     * Returns true if `$refererHost` is the same as `$allowedHost` or a
     * subdomain of it. `*.example.com` is treated as `example.com`.
     */
    public static function hostMatches(string $refererHost, string $allowedHost): bool
    {
        $referer = self::normalizeHost($refererHost);
        $allowed = self::normalizeHost($allowedHost);
        if ($referer === '' || $allowed === '') return false;
        if (str_starts_with($allowed, '*.')) {
            $allowed = substr($allowed, 2);
        }
        return $referer === $allowed || str_ends_with($referer, '.' . $allowed);
    }

    /**
     * Strip a domain string down to a bare hostname: drop scheme, port,
     * leading wildcard, and trailing dots. Returns '' if the result
     * isn't a valid host fragment.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') return '';
        $host = parse_url(str_contains($domain, '://') ? $domain : ('https://' . $domain), PHP_URL_HOST);
        if (is_string($host) && $host !== '') $domain = $host;
        if (str_starts_with($domain, '*.')) $domain = substr($domain, 2);
        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $domain = trim($domain, '.');
        return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : '';
    }

    /**
     * Take a comma-separated allowlist (from the settings form) and
     * return the deduplicated host list, with the current SITE_URL host
     * and HTTP_HOST always included.
     *
     * @return array<int,string>
     */
    public static function domainsFromInput(string $domains): array
    {
        $items = [];
        $siteHost = parse_url(defined('SITE_URL') ? (string)SITE_URL : '', PHP_URL_HOST);
        if (is_string($siteHost)) $items[] = $siteHost;
        $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($requestHost !== '') $items[] = $requestHost;
        foreach (explode(',', $domains) as $domain) $items[] = $domain;

        $normalized = [];
        foreach ($items as $item) {
            $d = self::normalizeDomain((string)$item);
            if ($d !== '') $normalized[$d] = true;
        }
        return array_keys($normalized);
    }

    /**
     * Render the Apache mod_rewrite block that enforces the allowlist.
     * The block is wrapped in BEGIN/END markers so it can be replaced
     * cleanly without touching the rest of the .htaccess.
     */
    public static function apacheRulesBlock(string $domains, bool $allowEmptyReferer): string
    {
        $allowed = self::domainsFromInput($domains);
        if ($allowed === []) $allowed = ['localhost'];
        $pattern = implode('|', array_map(static fn (string $d) => preg_quote($d, '/'), $allowed));

        $lines = [
            '# BEGIN LitePic Hotlink Protection',
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
        ];
        if ($allowEmptyReferer) {
            $lines[] = '    RewriteCond %{HTTP_REFERER} !^$';
        }
        $lines[] = '    RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?(' . $pattern . ')(:[0-9]+)?(/|$) [NC]';
        $lines[] = '    RewriteRule ^uploads/.*\.(jpg|jpeg|png|gif|webp|avif|heic|heif|svg|ico|bmp|tiff|tif)$ - [F,L]';
        $lines[] = '</IfModule>';
        $lines[] = '# END LitePic Hotlink Protection';
        return implode(PHP_EOL, $lines);
    }

    /**
     * Idempotently write (or remove) the rules block in `$htaccessPath`.
     * If `$enabled=false` and the file doesn't exist, no-op.
     */
    public static function writeApacheRules(string $htaccessPath, bool $enabled, string $domains, bool $allowEmptyReferer): bool
    {
        if (!$enabled && !is_file($htaccessPath)) return true;
        $content = is_file($htaccessPath) ? file_get_contents($htaccessPath) : '';
        if (!is_string($content)) return false;

        $pattern = '/\R?# BEGIN LitePic Hotlink Protection\R.*?# END LitePic Hotlink Protection\R?/s';
        $content = preg_replace($pattern, PHP_EOL, $content) ?? $content;
        $content = rtrim($content) . PHP_EOL;

        if ($enabled) {
            $block = self::apacheRulesBlock($domains, $allowEmptyReferer);
            $anchor = '# 真实文件/目录直接访问';
            if (str_contains($content, $anchor)) {
                $content = str_replace($anchor, $block . PHP_EOL . PHP_EOL . $anchor, $content);
            } else {
                $content .= PHP_EOL . $block . PHP_EOL;
            }
        }
        return file_put_contents($htaccessPath, $content, LOCK_EX) !== false;
    }

    public static function apacheRulesEnabled(string $htaccessPath): bool
    {
        if (!is_file($htaccessPath)) return false;
        $content = file_get_contents($htaccessPath);
        return is_string($content) && str_contains($content, '# BEGIN LitePic Hotlink Protection');
    }
}

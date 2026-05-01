<?php
declare(strict_types=1);

namespace LitePic\Service\Hotlink;

/**
 * Referer-based hotlink protection. When enabled, image requests are
 * routed through `/i/<identifier>` (image.php) which calls
 * `serveProtected()` to enforce the referer allowlist before streaming
 * the file.
 */
final class HotlinkProtection
{
    public function isEnabled(): bool
    {
        return defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED;
    }

    public function isRequestAllowed(): bool
    {
        return is_hotlink_request_allowed();
    }

    public function allowedHosts(): array
    {
        return hotlink_allowed_hosts();
    }

    public function serveProtected(string $identifier): void
    {
        serve_protected_image($identifier);
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Repository\DavLockRepository;

/**
 * Write locks — the half of DAV class 2 that decides whether a request may
 * proceed, plus the XML for `lockdiscovery` and `supportedlock`.
 *
 * Why locking is here at all: macOS Finder checks the `DAV` response header on
 * mount and silently mounts read-only unless class 2 is advertised, and Windows
 * Explorer will not save a file in place without taking a lock first. So this
 * isn't an optional refinement — without it the mount looks broken in exactly
 * the two clients most people will try.
 *
 * Only exclusive write locks are granted. Shared locks are requested by almost
 * nothing, and a shared-lock request is answered with an exclusive one rather
 * than a failure, which every client in practice accepts.
 *
 * Ownership is proven by presenting the lock token in an `If` header. That is
 * weaker than tying a lock to an authenticated principal, but it is what RFC
 * 4918 specifies and what clients implement; the mount already requires a valid
 * password, so the token check is about preventing concurrent-edit corruption,
 * not about authorisation.
 */
final class DavLockManager
{
    private DavLockRepository $locks;

    public function __construct(?DavLockRepository $locks = null)
    {
        $this->locks = $locks ?? new DavLockRepository();
    }

    /**
     * Expire stale locks. Called once at the top of every WebDAV request, which
     * is what makes lock timeouts real without depending on cron.
     */
    public function purgeExpired(): void
    {
        $this->locks->purgeExpired();
    }

    /**
     * The lock that blocks writing to $path, or null when the write may proceed.
     *
     * A lock the client has presented a token for is not a blocker; that's the
     * whole point of holding one.
     *
     * @param string[] $submittedTokens
     * @return array<string,mixed>|null
     */
    public function blockingLock(string $path, array $submittedTokens): ?array
    {
        $submitted = array_fill_keys($submittedTokens, true);
        foreach ($this->locks->findGoverning($path) as $lock) {
            if (!isset($submitted[$lock['token']])) {
                return $lock;
            }
        }
        return null;
    }

    /**
     * As {@see self::blockingLock()} but for a whole subtree, which DELETE and
     * MOVE on a collection need: RFC 4918 §9.6.1 forbids removing a collection
     * while any descendant is locked by someone else.
     *
     * @param string[] $submittedTokens
     * @return array<string,mixed>|null
     */
    public function blockingLockInSubtree(string $path, array $submittedTokens): ?array
    {
        $submitted = array_fill_keys($submittedTokens, true);
        foreach ($this->locks->findInSubtree($path) as $lock) {
            if (!isset($submitted[$lock['token']])) {
                return $lock;
            }
        }
        return $this->blockingLock($path, $submittedTokens);
    }

    /**
     * Grant a new exclusive write lock.
     *
     * @return array{token:string,timeout:int,depth:int,owner:string}
     */
    public function create(string $path, int $depth, string $owner, int $timeout): array
    {
        // urn:uuid form, which RFC 4918 §6.5 recommends over the older
        // opaquelocktoken scheme, and which clients treat as opaque anyway.
        $token = 'urn:uuid:' . self::uuid4();
        $normalisedDepth = $depth === -1 ? -1 : 0;

        $this->locks->create($token, $path, $normalisedDepth, 'exclusive', 'write', $owner, $timeout);

        return ['token' => $token, 'timeout' => $timeout, 'depth' => $normalisedDepth, 'owner' => $owner];
    }

    /**
     * Extend an existing lock. Returns the refreshed lock, or null when the
     * token is unknown or already expired (→ 412).
     *
     * @return array<string,mixed>|null
     */
    public function refresh(string $token, int $timeout): ?array
    {
        $lock = $this->locks->findByToken($token);
        if ($lock === null) {
            return null;
        }
        $this->locks->refresh($token, $timeout);
        return $this->locks->findByToken($token);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $token): ?array
    {
        return $this->locks->findByToken($token);
    }

    public function release(string $token): void
    {
        $this->locks->delete($token);
    }

    public function releaseSubtree(string $path): void
    {
        $this->locks->deleteSubtree($path);
    }

    public function relocate(string $from, string $to): void
    {
        $this->locks->moveSubtree($from, $to);
    }

    /**
     * The first live lock on $path itself, used to fill `lockdiscovery`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function governing(string $path): array
    {
        return $this->locks->findGoverning($path);
    }

    // ---- XML fragments -------------------------------------------------------

    /**
     * `<D:lockdiscovery>` inner XML for a path — empty when nothing holds it.
     */
    public function lockDiscoveryXml(string $path): string
    {
        $xml = '';
        foreach ($this->governing($path) as $lock) {
            $xml .= self::activeLockXml($lock);
        }
        return $xml;
    }

    /**
     * `<D:activelock>` for one lock. The token goes in `<D:locktoken><D:href>`,
     * which is where every client looks for it after a LOCK.
     *
     * @param array<string,mixed> $lock
     */
    public static function activeLockXml(array $lock): string
    {
        $remaining = max(1, (int)$lock['expires_at'] - time());
        $depth = (int)$lock['depth'] === -1 ? 'infinity' : '0';
        $owner = (string)$lock['owner'];

        $xml = '<D:activelock>'
            . '<D:locktype><D:write/></D:locktype>'
            . '<D:lockscope><D:' . ((string)$lock['scope'] === 'shared' ? 'shared' : 'exclusive') . '/></D:lockscope>'
            . '<D:depth>' . $depth . '</D:depth>';

        if ($owner !== '') {
            $xml .= '<D:owner>' . DavResponse::escape($owner) . '</D:owner>';
        }

        return $xml
            . '<D:timeout>Second-' . $remaining . '</D:timeout>'
            . '<D:locktoken><D:href>' . DavResponse::escape((string)$lock['token']) . '</D:href></D:locktoken>'
            . '<D:lockroot><D:href>' . DavResponse::escape(DavPath::href((string)$lock['path'], false)) . '</D:href></D:lockroot>'
            . '</D:activelock>';
    }

    /**
     * `<D:supportedlock>` inner XML. Advertising the shared entry as well is
     * harmless and keeps clients from ruling out the mount before trying.
     */
    public static function supportedLockXml(): string
    {
        return '<D:lockentry><D:lockscope><D:exclusive/></D:lockscope><D:locktype><D:write/></D:locktype></D:lockentry>'
            . '<D:lockentry><D:lockscope><D:shared/></D:lockscope><D:locktype><D:write/></D:locktype></D:lockentry>';
    }

    /**
     * The full `207` body a successful LOCK returns, which carries the granted
     * lock's `lockdiscovery` as a bare prop document rather than a multistatus.
     *
     * @param array<string,mixed> $lock
     */
    public static function lockResponseXml(array $lock): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:prop xmlns:D="DAV:"><D:lockdiscovery>'
            . self::activeLockXml($lock)
            . '</D:lockdiscovery></D:prop>';
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

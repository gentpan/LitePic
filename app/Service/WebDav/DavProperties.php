<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Repository\ImageRepository;

/**
 * Computes the WebDAV properties of a {@see DavNode}.
 *
 * Only live properties are supported — there is no dead-property store. That is
 * a deliberate limit: dead properties would mean an unbounded key/value table
 * written by clients on every file operation, to hold data (Finder colour
 * labels, Windows attribute bits) that has no meaning inside an image host.
 * PROPPATCH therefore reports success for the Win32 timestamp properties, which
 * Windows Explorer requires, and 403 for anything else — see {@see DavServer}.
 *
 * The Microsoft `Win32*` properties are answered because Windows Explorer reads
 * them during a copy and treats their absence as a reason to distrust the share.
 * `quota-*` is answered because Finder shows a mounted volume's capacity and
 * displays "zero bytes available" — which blocks writes in the UI — when the
 * properties are missing.
 */
final class DavProperties
{
    /** Properties returned for `allprop`. */
    private const ALLPROP = [
        '{DAV:}resourcetype',
        '{DAV:}displayname',
        '{DAV:}getcontentlength',
        '{DAV:}getcontenttype',
        '{DAV:}getlastmodified',
        '{DAV:}creationdate',
        '{DAV:}getetag',
        '{DAV:}supportedlock',
        '{DAV:}lockdiscovery',
    ];

    /** Additional names reported by `propname`, which lists without values. */
    private const PROPNAME_EXTRA = [
        '{DAV:}quota-available-bytes',
        '{DAV:}quota-used-bytes',
        '{urn:schemas-microsoft-com:}Win32CreationTime',
        '{urn:schemas-microsoft-com:}Win32LastModifiedTime',
        '{urn:schemas-microsoft-com:}Win32FileAttributes',
        '{http://apache.org/dav/props/}executable',
    ];

    private DavLockManager $locks;
    private ImageRepository $images;

    /** Lazily computed; the SUM over images is not worth repeating per node. */
    private ?int $usedBytes = null;

    public function __construct(?DavLockManager $locks = null, ?ImageRepository $images = null)
    {
        $this->locks = $locks ?? new DavLockManager();
        $this->images = $images ?? new ImageRepository();
    }

    /**
     * One `<D:response>` entry for a node.
     *
     * @param string[] $requested Canonical names; ignored unless $mode is `prop`.
     * @return array{href:string,found:array<string,string>,notFound:string[]}
     */
    public function describe(DavNode $node, string $mode, array $requested): array
    {
        if ($mode === DavXml::PROPFIND_PROPNAME) {
            $names = array_merge(self::ALLPROP, self::PROPNAME_EXTRA);
            $found = [];
            foreach ($names as $name) {
                $found[$name] = '';
            }
            return ['href' => $node->href(), 'found' => $found, 'notFound' => []];
        }

        $names = $mode === DavXml::PROPFIND_PROP ? $requested : self::ALLPROP;

        $found = [];
        $notFound = [];
        foreach ($names as $name) {
            $value = $this->value($node, $name);
            if ($value === null) {
                $notFound[] = $name;
                continue;
            }
            $found[$name] = $value;
        }

        return ['href' => $node->href(), 'found' => $found, 'notFound' => $notFound];
    }

    /**
     * Inner XML for one property, or null when the node does not have it.
     *
     * Returning null (→ reported under `404` in the propstat) rather than an
     * empty value matters: a client that sees `<getcontentlength/>` with no text
     * on a collection may render it as a zero-byte file.
     */
    private function value(DavNode $node, string $canonical): ?string
    {
        switch ($canonical) {
            case '{DAV:}resourcetype':
                return $node->isCollection ? '<D:collection/>' : '';

            case '{DAV:}displayname':
                // The root has no name of its own; "LitePic" is friendlier in a
                // client's sidebar than an empty string.
                return DavResponse::escape($node->name !== '' ? $node->name : 'LitePic');

            case '{DAV:}getcontentlength':
                return $node->isCollection ? null : (string)$node->size;

            case '{DAV:}getcontenttype':
                if ($node->isCollection) {
                    // httpd/unix-directory is what clients expect here; some
                    // older ones use it instead of resourcetype to spot folders.
                    return 'httpd/unix-directory';
                }
                return DavResponse::escape($node->contentType !== '' ? $node->contentType : 'application/octet-stream');

            case '{DAV:}getlastmodified':
                return DavResponse::httpDate($node->modifiedAt);

            case '{DAV:}creationdate':
                return DavResponse::isoDate($node->createdAt);

            case '{DAV:}getetag':
                return $node->isCollection ? null : DavResponse::escape($node->etag());

            case '{DAV:}supportedlock':
                return DavLockManager::supportedLockXml();

            case '{DAV:}lockdiscovery':
                // Empty element is correct and required when nothing is locked;
                // omitting the property makes Finder retry the LOCK in a loop.
                return $this->locks->lockDiscoveryXml($node->path) ?: ' ';

            case '{DAV:}quota-available-bytes':
                return $node->isCollection ? (string)$this->availableBytes() : null;

            case '{DAV:}quota-used-bytes':
                return $node->isCollection ? (string)$this->totalUsedBytes() : null;

            case '{urn:schemas-microsoft-com:}Win32CreationTime':
                return DavResponse::httpDate($node->createdAt);

            case '{urn:schemas-microsoft-com:}Win32LastModifiedTime':
                return DavResponse::httpDate($node->modifiedAt);

            case '{urn:schemas-microsoft-com:}Win32FileAttributes':
                // Hex bitmask: 0x10 FILE_ATTRIBUTE_DIRECTORY, 0x20 ARCHIVE, and
                // 0x01 READONLY for the date view, which Explorer then greys out
                // instead of letting the user attempt a doomed write.
                $attributes = $node->isCollection ? 0x10 : 0x20;
                if (!$node->writable) {
                    $attributes |= 0x01;
                }
                return sprintf('%08x', $attributes);

            case '{http://apache.org/dav/props/}executable':
                return $node->isCollection ? null : 'F';

            default:
                return null;
        }
    }

    /**
     * Free space on the storage volume. Falls back to a nominal 8GB when the
     * filesystem can't be queried (`open_basedir`, or a container where
     * `disk_free_space` is disabled): reporting 0 would make Finder refuse to
     * copy anything at all.
     */
    private function availableBytes(): int
    {
        $root = defined('UPLOAD_PATH_LOCAL') ? (string)UPLOAD_PATH_LOCAL : APP_ROOT;
        $free = @disk_free_space($root);
        if ($free === false || $free <= 0) {
            return 8 * 1024 * 1024 * 1024;
        }
        return (int)$free;
    }

    private function totalUsedBytes(): int
    {
        if ($this->usedBytes === null) {
            try {
                $this->usedBytes = $this->images->totalSize();
            } catch (\Throwable $e) {
                $this->usedBytes = 0;
            }
        }
        return $this->usedBytes;
    }
}

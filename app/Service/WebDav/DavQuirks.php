<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * Client-specific behaviour that the protocol doesn't cover.
 *
 * Mounting a WebDAV share in Finder or Windows Explorer means the OS starts
 * writing its own bookkeeping files into it: `.DS_Store` for folder view state,
 * `._name` AppleDouble sidecars for resource forks, `desktop.ini`, thumbnail
 * caches, and editor lock files. None of them are images, and LitePic has
 * nowhere sensible to put them — accepting them into the library would mean a
 * gallery full of 4KB binary junk with broken thumbnails.
 *
 * Rejecting them with 403 is worse than it sounds: Finder surfaces the failure
 * as "the operation can't be completed" on the *user's* copy, even though the
 * user's actual file transferred fine. So these names are absorbed instead —
 * writes report success, reads report 404, and nothing is stored. From the OS's
 * point of view its scratch file was written and then vanished, which it handles
 * without complaint.
 */
final class DavQuirks
{
    /**
     * Exact names, matched case-insensitively.
     */
    private const JUNK_NAMES = [
        '.ds_store',
        '.localized',
        'desktop.ini',
        'thumbs.db',
        'ehthumbs.db',
        '.apdisk',
        '.volumeicon.icns',
        'folder.jpg~',
    ];

    /**
     * Name prefixes. `._` is AppleDouble; `.~lock.` is LibreOffice; `~$` is Office.
     */
    private const JUNK_PREFIXES = [
        '._',
        '.~lock.',
        '~$',
        '.fseventsd',
        '.spotlight-',
        '.trashes',
        '.temporaryitems',
        '.documentrevisions',
    ];

    /**
     * Suffixes for editor scratch files.
     */
    private const JUNK_SUFFIXES = [
        '.crdownload',
        '.part',
        '.partial',
        '.tmp',
        '.swp',
    ];

    /**
     * True when a name is OS scratch rather than user content.
     */
    public static function isJunkName(string $name): bool
    {
        $lower = strtolower($name);

        if (in_array($lower, self::JUNK_NAMES, true)) {
            return true;
        }
        foreach (self::JUNK_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        foreach (self::JUNK_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the last segment of a path is a junk name.
     */
    public static function isJunkPath(string $path): bool
    {
        $name = DavPath::basename($path);
        return $name !== '' && self::isJunkName($name);
    }

    /**
     * A file name with no image extension at all — worth knowing before paying
     * for a reconciliation pass on a lookup miss, since clients probe for a lot
     * of names that were never going to exist.
     */
    public static function looksLikeImageName(string $name): bool
    {
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }
        // Superset of upload types + derivatives (heic/tiff/…) that may already
        // live in the library even when new uploads of that format are disabled.
        static $known = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico',
            'bmp', 'tif', 'tiff', 'heic', 'heif',
        ];
        return in_array($extension, $known, true);
    }
}

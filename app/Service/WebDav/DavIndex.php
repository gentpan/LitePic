<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use LitePic\Repository\DavEntryRepository;

/**
 * Keeps `dav_entries` in step with what the albums actually contain.
 *
 * The alternative — writing mapping rows from the upload service, the album
 * service and the delete path — would put WebDAV bookkeeping into four places
 * that have no other reason to know it exists, and any missed call site would
 * show up as a file that is visible in the gallery but invisible over WebDAV
 * (or worse, the reverse). Reconciling instead means the mapping can only ever
 * be stale, never wrong, and one query fixes it.
 *
 * Reconciliation runs when a collection is listed, and on a path-lookup miss so
 * that a client which GETs a file it has never listed still finds it.
 *
 * Deleting an image cascades its mapping rows away via the foreign key, so the
 * "stale" queries here only have to handle album membership changes.
 */
final class DavIndex
{
    /** A 0-byte placeholder this old is an abandoned probe, not a live write. */
    private const PLACEHOLDER_TTL = 3600;

    private DavEntryRepository $entries;

    public function __construct(?DavEntryRepository $entries = null)
    {
        $this->entries = $entries ?? new DavEntryRepository();
    }

    /**
     * Bring one collection up to date. `$albumId === 0` is the unfiled view.
     *
     * `$limit` bounds how many new mappings are created in one pass, matching
     * the listing cap: there is no point materialising 100k names for a listing
     * that will be truncated anyway, and an unbounded pass would turn the first
     * PROPFIND on a large library into a timeout.
     */
    public function reconcile(int $albumId, int $limit): void
    {
        if ($albumId === 0) {
            // Unfiled is a hard-link style view, not "images with no album".
            // A COPY into an album must leave the original name here; only a
            // MOVE, DELETE, or the image itself vanishing (FK cascade) removes
            // an unfiled mapping. There is therefore no stale sweep for album_id
            // 0 — missingInUnfiled still backfills images the web UI uploaded
            // that have never been filed into any album.
            $candidates = $this->entries->missingInUnfiled($limit);
        } else {
            $this->entries->deleteMany($this->entries->staleInAlbum($albumId));
            $candidates = $this->entries->missingInAlbum($albumId, $limit);
        }

        if ($candidates === []) {
            return;
        }

        $taken = $this->entries->takenNames($albumId);
        foreach ($candidates as $candidate) {
            $name = DavName::forImage($candidate['filename'], $candidate['original_name'], $taken);
            $id = $this->entries->insert($albumId, $name, $candidate['filename']);
            if ($id === null) {
                // Lost a race with a parallel request that claimed the same
                // name. It now exists either way, which is all we needed.
                continue;
            }
            $taken[$name] = true;
        }
    }

    /**
     * Drop placeholders whose follow-up PUT never arrived. Called once per
     * WebDAV request, alongside lock expiry, so an interrupted Finder copy
     * cannot leave a name permanently squatted.
     */
    public function purgeStalePlaceholders(): void
    {
        $this->entries->deleteMany($this->entries->stalePlaceholderIds(time() - self::PLACEHOLDER_TTL));
    }
}

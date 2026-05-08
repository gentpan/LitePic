<?php
declare(strict_types=1);

namespace LitePic\Service\Album;

use LitePic\Repository\AlbumImageRepository;
use LitePic\Repository\AlbumRepository;

/**
 * Business logic on top of {@see AlbumRepository}: slug normalisation,
 * visibility validation, password hashing, embed-token issuance, and
 * keeping the denormalised `image_count` honest.
 *
 * Controllers should ALWAYS go through this service — never write to the
 * repositories directly. That way the rules below stay enforced (e.g. you
 * can't accidentally save a slug like "../etc/passwd" or skip the bcrypt
 * cost when storing a password).
 */
final class AlbumService
{
    public const VISIBILITIES = ['public', 'unlisted', 'password', 'private'];

    /**
     * Reserved slugs we must never accept — they conflict with framework
     * routes (`/api`, `/embed`, `/a/...` reused, etc.) or look weird in URLs.
     */
    private const RESERVED_SLUGS = [
        'api', 'app', 'assets', 'static', 'data', 'logs', 'i', 'a',
        'albums', 'embed', 'admin', 'settings', 'gallery', 'upload',
        'stats', 'login', 'logout', 'auth', 'new', 'edit', 'delete',
        'create', 'update', 'public', 'private',
    ];

    private AlbumRepository $albums;
    private AlbumImageRepository $albumImages;

    public function __construct(
        ?AlbumRepository $albums = null,
        ?AlbumImageRepository $albumImages = null
    ) {
        $this->albums = $albums ?? new AlbumRepository();
        $this->albumImages = $albumImages ?? new AlbumImageRepository();
    }

    // ==================== creation / update ====================

    /**
     * Create a new album. Returns the new id, or one of the string error
     * codes below.
     *
     *   - 'slug_invalid'      slug fails {@see normalizeSlug}
     *   - 'slug_reserved'     slug is in RESERVED_SLUGS
     *   - 'slug_taken'        another album already owns this slug
     *   - 'name_required'     name is empty
     *   - 'visibility_invalid'  visibility not in VISIBILITIES
     *   - 'password_required'   visibility=password but no password supplied
     *   - 'db_error'          INSERT failed for unknown reasons
     *
     * @param array{
     *   name:string, slug?:string, description?:string, visibility?:string,
     *   password?:?string, cover_filename?:?string
     * } $input
     * @return int|string  album id on success, error code otherwise
     */
    public function create(array $input): int|string
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') return 'name_required';

        // Slug is now optional. Blank → null → public URL becomes /a/<id>.
        // Provided → normalised, validated, and uniqueness-checked.
        $slug = trim((string)($input['slug'] ?? ''));
        if ($slug !== '') {
            $slug = self::normalizeSlug($slug);
            if ($slug === '') return 'slug_invalid';
            if (in_array($slug, self::RESERVED_SLUGS, true)) return 'slug_reserved';
            if ($this->albums->findBySlug($slug) !== null) return 'slug_taken';
        } else {
            $slug = null;
        }

        $visibility = (string)($input['visibility'] ?? 'public');
        if (!in_array($visibility, self::VISIBILITIES, true)) return 'visibility_invalid';

        $passwordHash = null;
        if ($visibility === 'password') {
            $password = (string)($input['password'] ?? '');
            if ($password === '') return 'password_required';
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        $cover = (string)($input['cover_filename'] ?? '');
        $cover = $cover !== '' ? $cover : null;

        $id = $this->albums->create([
            'slug'           => $slug,
            'name'           => $name,
            'description'    => trim((string)($input['description'] ?? '')),
            'cover_filename' => $cover,
            'visibility'     => $visibility,
            'password_hash'  => $passwordHash,
            'embed_token'    => self::generateEmbedToken(),
        ]);
        return $id ?? 'db_error';
    }

    /**
     * Partial update. Same error-code contract as create() plus 'not_found'.
     *
     * Pass `password = null` to wipe an existing password (and switch
     * visibility off `password`). Pass `password = '<new>'` to set/change.
     * Omit the key entirely to leave the password untouched.
     *
     * @param array{
     *   name?:string, slug?:string, description?:string, visibility?:string,
     *   password?:?string, cover_filename?:?string
     * } $input
     * @return true|string
     */
    public function update(int $id, array $input): true|string
    {
        $existing = $this->albums->find($id);
        if ($existing === null) return 'not_found';

        $patch = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') return 'name_required';
            $patch['name'] = $name;
        }

        if (array_key_exists('slug', $input)) {
            // Empty string means "clear the slug" — fall back to /a/<id> URL.
            $rawSlug = trim((string)$input['slug']);
            if ($rawSlug === '') {
                $patch['slug'] = null;
            } else {
                $slug = self::normalizeSlug($rawSlug);
                if ($slug === '') return 'slug_invalid';
                if (in_array($slug, self::RESERVED_SLUGS, true)) return 'slug_reserved';
                if ($slug !== ($existing['slug'] ?? null)) {
                    $clash = $this->albums->findBySlug($slug);
                    if ($clash !== null && (int)$clash['id'] !== $id) return 'slug_taken';
                }
                $patch['slug'] = $slug;
            }
        }

        if (array_key_exists('description', $input)) {
            $patch['description'] = trim((string)$input['description']);
        }

        if (array_key_exists('cover_filename', $input)) {
            $cover = (string)$input['cover_filename'];
            $patch['cover_filename'] = $cover !== '' ? $cover : null;
        }

        $newVisibility = $existing['visibility'];
        if (array_key_exists('visibility', $input)) {
            $vis = (string)$input['visibility'];
            if (!in_array($vis, self::VISIBILITIES, true)) return 'visibility_invalid';
            $patch['visibility'] = $vis;
            $newVisibility = $vis;
        }

        if (array_key_exists('password', $input)) {
            $password = $input['password'];
            if ($password === null || $password === '') {
                // Wipe — user wants no password.
                $patch['password_hash'] = null;
            } else {
                $patch['password_hash'] = password_hash((string)$password, PASSWORD_BCRYPT);
            }
        }

        // Coherence: if final visibility is 'password' there must be a hash.
        if ($newVisibility === 'password') {
            $finalHash = array_key_exists('password_hash', $patch)
                ? $patch['password_hash']
                : $existing['password_hash'];
            if ($finalHash === null || $finalHash === '') return 'password_required';
        }

        if ($patch === []) return true;
        return $this->albums->update($id, $patch) ? true : 'db_error';
    }

    public function delete(int $id): bool
    {
        return $this->albums->delete($id);
    }

    // ==================== image membership ====================

    /**
     * @param array<int,string> $filenames
     * @return int  number actually inserted (skipped duplicates not counted)
     */
    public function addImages(int $albumId, array $filenames): int
    {
        if ($this->albums->find($albumId) === null) return 0;
        $count = $this->albumImages->addMany($albumId, $filenames);
        if ($count > 0) {
            $this->albums->refreshImageCount($albumId);
        }
        return $count;
    }

    public function removeImage(int $albumId, string $filename): bool
    {
        $removed = $this->albumImages->remove($albumId, $filename);
        if ($removed) {
            $this->albums->refreshImageCount($albumId);
        }
        return $removed;
    }

    /**
     * @param array<int,string> $filenames
     * @return int  number actually removed
     */
    public function removeImages(int $albumId, array $filenames): int
    {
        $count = $this->albumImages->removeMany($albumId, $filenames);
        if ($count > 0) {
            $this->albums->refreshImageCount($albumId);
        }
        return $count;
    }

    /**
     * @param array<int,string> $orderedFilenames  full or partial order; missing
     *                                             entries keep their old positions
     */
    public function reorder(int $albumId, array $orderedFilenames): void
    {
        $this->albumImages->reorder($albumId, $orderedFilenames);
    }

    // ==================== password / visibility helpers ====================

    public function verifyPassword(array $album, string $password): bool
    {
        $hash = $album['password_hash'] ?? '';
        return is_string($hash) && $hash !== ''
            && password_verify($password, $hash);
    }

    /**
     * Whether an album is publicly browsable (with or without a gate).
     * Used by routing to decide between 200 / password-prompt / 404.
     */
    public static function isPublicVisibility(string $visibility): bool
    {
        return in_array($visibility, ['public', 'unlisted', 'password'], true);
    }

    public static function isEmbeddable(string $visibility): bool
    {
        return in_array($visibility, ['public', 'unlisted'], true);
    }

    /**
     * The public addressable key for an album — what goes after `/a/` and what
     * the admin API endpoints accept.
     *
     *   - slug set    → return the slug
     *   - slug null   → return the numeric id as a string
     *
     * Single source of truth: every URL builder (admin list, edit page,
     * embed iframe, share link) should call this so we don't have ten places
     * each independently doing `slug ?: id`.
     *
     * @param array{id:int,slug:?string} $album
     */
    public static function urlKey(array $album): string
    {
        $slug = $album['slug'] ?? null;
        if (is_string($slug) && $slug !== '') return $slug;
        return (string)(int)$album['id'];
    }

    // ==================== slug helpers ====================

    /**
     * Lowercase, replace runs of non a-z 0-9 with `-`, trim leading/trailing
     * dashes, cap at 50 chars. Returns '' if the result is empty or starts
     * with a digit (slugs must start with a letter for nicer URLs).
     */
    public static function normalizeSlug(string $raw): string
    {
        $s = strtolower(trim($raw));
        if ($s === '') return '';

        // Best-effort transliteration — works on most installs (intl optional).
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') return '';
        if (strlen($s) > 50) $s = substr($s, 0, 50);
        // First char must be a letter (CSS / URL aesthetics)
        if (!preg_match('/^[a-z]/', $s)) return '';
        return $s;
    }

    /**
     * Auto-derive a slug from name, falling back to "album-<n>" when
     * normalisation can't produce anything valid (e.g. all-Chinese name
     * with no iconv install).
     */
    public function slugFromName(string $name): string
    {
        $base = self::normalizeSlug($name);
        if ($base === '') $base = 'album';

        $slug = $base;
        $i = 2;
        while ($this->albums->findBySlug($slug) !== null) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 999) {
                // Bail to a random suffix rather than loop forever
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }
        return $slug;
    }

    private static function generateEmbedToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Repository\ImageRepository;
use LitePic\Service\Storage\RemoteStorage;

/**
 * Deleting an image, everywhere it exists.
 *
 * One image on disk can have up to six associated objects: the original, its
 * `.webp` and `.avif` derivatives, and a thumbnail for each of those. Plus a
 * database row, plus copies in remote object storage. Getting all of them is
 * fiddly enough that having the sequence written out twice — once for the
 * gallery's `action.php`, once for WebDAV DELETE — would guarantee the two drift
 * apart and one of them starts leaving orphans behind.
 *
 * Remote objects are not deleted immediately. {@see RemoteStorage} enqueues them
 * with a delay (24h by default) so an accidental delete can still be recovered
 * from the bucket, and so a CDN that has the object cached doesn't start serving
 * 404s to pages that are still referencing it.
 *
 * Ordering is deliberate: remote enqueue first (it only writes a queue row and
 * cannot fail destructively), then local files, then the database row last. If
 * anything throws midway, the row still points at whatever survived, and the
 * orphan cleaner can finish the job — the reverse order would leave files with
 * no row, which nothing would ever look at again.
 */
final class ImageDeleter
{
    private ImageRepository $images;
    private ThumbnailService $thumbnails;
    private RemoteStorage $remote;

    public function __construct(
        ?ImageRepository $images = null,
        ?ThumbnailService $thumbnails = null,
        ?RemoteStorage $remote = null
    ) {
        $this->images = $images ?? new ImageRepository();
        $this->thumbnails = $thumbnails ?? new ThumbnailService();
        $this->remote = $remote ?? new RemoteStorage();
    }

    /**
     * True when $identifier has an extension LitePic recognises as an image.
     * Guards against a caller handing over an arbitrary path.
     */
    public static function isDeletableType(string $identifier): bool
    {
        $ext = strtolower((string)pathinfo($identifier, PATHINFO_EXTENSION));
        $allowed = defined('ALLOWED_TYPES') && is_array(ALLOWED_TYPES) ? ALLOWED_TYPES : [];
        return $ext !== '' && in_array($ext, $allowed, true);
    }

    /**
     * Remove an image and everything derived from it.
     *
     * @return array{ok:bool,message:string}
     */
    public function delete(string $identifier): array
    {
        if (!self::isDeletableType($identifier)) {
            return ['ok' => false, 'message' => '只能删除允许的图片类型'];
        }

        $path = PathService::resolveFilePath($identifier);
        $derivatives = self::derivativePaths($path);

        $this->remote->deleteFileAndThumbnail($identifier);
        foreach ($derivatives as $derivative) {
            if (file_exists($derivative)) {
                $this->remote->deleteFileAndThumbnail(self::identifierFor($derivative));
            }
        }

        // Thumbnail cleanup runs before the original unlink so a failure to
        // remove the original still doesn't leave a stale thumbnail behind.
        $this->thumbnails->delete($identifier);

        if (file_exists($path) && !@unlink($path)) {
            return ['ok' => false, 'message' => '删除失败'];
        }

        foreach ($derivatives as $derivative) {
            if (file_exists($derivative)) {
                @unlink($derivative);
                $this->thumbnails->delete(self::identifierFor($derivative));
            }
        }

        $this->images->delete($identifier);

        return [
            'ok' => true,
            'message' => $this->remote->credentialsValid()
                ? '删除成功，远程对象将在 24 小时后删除'
                : '删除成功',
        ];
    }

    /**
     * Absolute paths of the `.webp` / `.avif` versions the conversion pipeline
     * may have written next to a raster original.
     *
     * @return string[]
     */
    private static function derivativePaths(string $path): array
    {
        $paths = [];
        foreach (['webp', 'avif'] as $format) {
            $candidate = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.' . $format, $path);
            if (is_string($candidate) && $candidate !== $path) {
                $paths[] = $candidate;
            }
        }
        return $paths;
    }

    private static function identifierFor(string $absolutePath): string
    {
        return (string)(PathService::identifierFromPath($absolutePath) ?? basename($absolutePath));
    }
}

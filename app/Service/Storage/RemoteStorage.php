<?php
declare(strict_types=1);

namespace LitePic\Service\Storage;

/**
 * S3-compatible (AWS S3 / Cloudflare R2 / etc.) storage layer.
 *
 * Wraps the procedural `remote_storage_*` family in functions.php with
 * a class-shaped API. The implementation lives in the legacy file
 * because it's ~700 lines (SigV4 signing, list/put/delete, sync,
 * restore) — moving the body without breaking it warrants its own pass.
 */
final class RemoteStorage
{
    public function isEnabled(): bool { return remote_storage_enabled(); }
    public function isConfigValid(): bool { return remote_storage_config_valid(); }
    public function credentialsValid(): bool { return remote_storage_credentials_valid(); }
    public function usage(): string { return remote_storage_usage(); }
    public function mode(): string { return remote_storage_mode(); }

    public function publicUrlForIdentifier(string $identifier): ?string
    {
        return remote_storage_public_url_for_identifier($identifier);
    }

    public function publicUrlForLocalPath(string $localPath): ?string
    {
        return remote_storage_public_url_for_local_path($localPath);
    }

    public function syncFileAndThumbnail(string $filename): array
    {
        return remote_storage_sync_file_and_thumbnail($filename);
    }

    public function deleteFileAndThumbnail(string $filename): void
    {
        remote_storage_delete_file_and_thumbnail($filename);
    }

    public function uploadLocalFile(string $localPath): array
    {
        return remote_storage_upload_local_file($localPath);
    }

    public function deleteObject(string $objectKey): bool
    {
        return remote_storage_delete_object($objectKey);
    }

    public function listObjects(string $prefix = '', string $continuationToken = ''): array
    {
        return remote_storage_list_objects($prefix, $continuationToken);
    }

    public function deleteAllObjects(): array
    {
        return remote_storage_delete_all_objects();
    }

    public function testConnection(): array
    {
        return remote_storage_test_connection();
    }

    public function syncAllLocalImages(): array
    {
        return remote_storage_sync_all_local_images();
    }

    public function restoreAllToLocal(): array
    {
        return remote_storage_restore_all_to_local();
    }

    public function objectKeyForFilename(string $filename): ?string
    {
        return remote_storage_object_key_for_filename($filename);
    }

    public function objectKeyForThumbnail(string $filename): ?string
    {
        return remote_storage_object_key_for_thumbnail($filename);
    }
}

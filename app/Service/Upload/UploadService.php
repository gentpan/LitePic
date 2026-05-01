<?php
declare(strict_types=1);

namespace LitePic\Service\Upload;

/**
 * Receives uploaded files (`$_FILES`), validates them, writes to disk,
 * and returns the per-file result array used by the upload UI / API.
 */
final class UploadService
{
    /**
     * @param array<mixed> $files  Raw $_FILES entry, or pre-normalised array.
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $files): array
    {
        return handle_uploaded_files($files);
    }

    public function validateMime(string $tmpPath, string $ext): bool
    {
        return validate_upload_mime($tmpPath, $ext);
    }

    public function maxBytes(): int
    {
        return get_effective_upload_max_bytes();
    }

    public function uploadErrorMessage(int $errorCode, string $filename = ''): string
    {
        return get_upload_error_message($errorCode, $filename);
    }

    /**
     * Rearrange the awkward $_FILES layout into one record per file.
     */
    public static function normaliseFilesArray(array $files): array
    {
        return normalize_uploaded_files($files);
    }
}

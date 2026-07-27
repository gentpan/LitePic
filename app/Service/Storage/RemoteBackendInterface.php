<?php
declare(strict_types=1);

namespace LitePic\Service\Storage;

/**
 * Shared contract for remote backends (S3/R2 and external WebDAV).
 * Call sites go through {@see Remotes::active()}.
 */
interface RemoteBackendInterface
{
    public function isEnabled(): bool;

    public function isConfigValid(): bool;

    public function usage(): string;

    public function publicDeliveryEnabled(): bool;

    public function publicUrlForIdentifier(string $identifier): ?string;

    public function publicUrlForLocalPath(string $localPath): ?string;

    /**
     * @return array<string, mixed>
     */
    public function syncFileAndThumbnail(string $filename): array;

    public function deleteFileAndThumbnail(string $filename): void;

    /**
     * @return array{ok:bool,status:int,error:?string,object_key:string}
     */
    public function uploadLocalFileAs(string $localPath, string $objectKey): array;

    /**
     * @return array{success:bool,message:string}
     */
    public function testConnection(): array;

    /**
     * @return array<string, mixed>
     */
    public function syncAllLocalImages(): array;

    /**
     * @return array<string, mixed>
     */
    public function restoreAllToLocal(): array;

    /**
     * @return array<string, mixed>
     */
    public function deleteAllObjects(): array;
}

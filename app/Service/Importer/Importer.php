<?php
declare(strict_types=1);

namespace LitePic\Service\Importer;

/**
 * Bulk import / scan-and-process directories of legacy uploads.
 *
 * Wraps the procedural `scan_and_import_uploads` and `import_task_*`
 * functions. The processing pipeline (compress / convert / watermark)
 * still lives in functions.php; this class is the public OO surface.
 */
final class Importer
{
    public function scanAndImport(array $options = []): array
    {
        return scan_and_import_uploads($options);
    }

    public function processQueue(int $limit = 8): array
    {
        return import_task_process_queue($limit);
    }

    public function queueStatus(): array
    {
        return import_task_queue_status();
    }

    public function enqueue(string $filename, array $options): bool
    {
        return import_task_enqueue($filename, $options);
    }
}

<?php
declare(strict_types=1);

namespace LitePic\Service\Importer;

use FilesystemIterator;
use LitePic\Repository\ImageRepository;
use LitePic\Repository\ImportQueueRepository;
use LitePic\Service\Image\CompressionService;
use LitePic\Service\Image\ConversionService;
use LitePic\Service\Image\ImageFormat;
use LitePic\Service\Image\PathService;
use LitePic\Service\Image\ThumbnailService;
use LitePic\Service\Image\WatermarkService;
use LitePic\Service\Storage\RemoteStorage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Bulk import / scan-and-process pipeline. Two paths:
 *
 *   1. `scanAndImport()` — walks one or more source directories, copies
 *      anything not already in the library into uploads/, and queues a
 *      processing task per file.
 *   2. `processQueue()` — drains the import_queue table, running each
 *      task's compress/convert/watermark/thumbnail/sync steps via the
 *      service classes that actually do the work.
 *
 * The dedup index is built from sha1 hashes pulled from disk (cheap
 * once the repo's hash column is populated, falls back to hashing
 * on-demand for older rows).
 */
final class Importer
{
    private ImportQueueRepository $queue;
    private ImageRepository $images;

    public function __construct(?ImportQueueRepository $queue = null, ?ImageRepository $images = null)
    {
        $this->queue = $queue ?? new ImportQueueRepository();
        $this->images = $images ?? new ImageRepository();
    }

    /**
     * @return array<string, mixed>
     */
    public function scanAndImport(array $options = []): array
    {
        $createThumb = !array_key_exists('create_thumbnail', $options) || (bool)$options['create_thumbnail'];
        $autoWebp = !empty($options['auto_webp']);
        $autoAvif = !empty($options['auto_avif']);
        $autoCompress = !empty($options['auto_compress']);
        $queueProcessing = !array_key_exists('queue_processing', $options) || (bool)$options['queue_processing'];

        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $legacyDir = $appRoot . DIRECTORY_SEPARATOR . 'upload';
        $currentRoot = rtrim((string)UPLOAD_PATH_LOCAL, DIRECTORY_SEPARATOR);

        $report = [
            'scanned' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0,
            'thumb_created' => 0, 'compressed' => 0, 'webp_created' => 0, 'avif_created' => 0,
            'watermark_applied' => 0, 'skip_compress' => 0, 'skip_webp' => 0,
            'skip_avif' => 0, 'skip_watermark' => 0, 'tasks_queued' => 0, 'errors' => [],
        ];

        $sources = $this->resolveSources((string)($options['source_path'] ?? ''), $report['errors']);
        if ($sources === []) {
            $report['errors'][] = '未找到可扫描目录（upload / uploads）';
            return $report;
        }

        $existingHashes = $this->buildHashIndex();
        $currentRootNormalized = str_replace('\\', '/', $currentRoot) . '/';
        $legacyRootNormalized = str_replace('\\', '/', $legacyDir) . '/';

        foreach ($sources as $sourceDir) {
            foreach (self::collectImagesIn($sourceDir) as $sourcePath) {
                $report['scanned']++;
                $normalized = str_replace('\\', '/', $sourcePath);
                $isInCurrent = str_starts_with($normalized, $currentRootNormalized);
                $isInLegacy = str_starts_with($normalized, $legacyRootNormalized);

                $relativeIdentifier = self::relativeIdentifier($sourcePath, $sourceDir);
                if ($relativeIdentifier === '') {
                    $report['failed']++;
                    $report['errors'][] = '路径解析失败: ' . $sourcePath;
                    continue;
                }
                if (preg_match('/\.thumb\.[a-z0-9]+$/i', basename($normalized))) continue;

                $ext = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
                $allowed = defined('ALLOWED_TYPES') ? ALLOWED_TYPES : [];
                if (!in_array($ext, $allowed, true)) continue;

                $hash = @sha1_file($sourcePath);
                if (is_string($hash) && $hash !== '' && isset($existingHashes[$hash])) {
                    $report['duplicates']++;
                    continue;
                }

                $finalFilename = $this->placeFile($sourcePath, $relativeIdentifier, $isInCurrent, $isInLegacy, $report);
                if ($finalFilename === '') continue;

                if ($queueProcessing) {
                    $taskOptions = [
                        'create_thumbnail' => $createThumb,
                        'auto_compress' => $autoCompress,
                        'auto_webp' => $autoWebp,
                        'auto_avif' => $autoAvif,
                        'watermark' => defined('WATERMARK_ENABLED') && WATERMARK_ENABLED,
                        'remote_sync' => (new RemoteStorage())->isEnabled() && (new RemoteStorage())->isConfigValid(),
                    ];
                    if (ImportQueueRepository::hasWork($taskOptions)) {
                        if ($this->queue->enqueue($finalFilename, $taskOptions)) {
                            $report['tasks_queued']++;
                        } else {
                            $report['failed']++;
                            $report['errors'][] = '任务入队失败: ' . $finalFilename;
                        }
                    }
                }

                $finalHash = @sha1_file(PathService::resolveFilePath($finalFilename));
                if (is_string($finalHash) && $finalHash !== '') {
                    $existingHashes[$finalHash] = $finalFilename;
                } elseif (is_string($hash) && $hash !== '') {
                    $existingHashes[$hash] = $finalFilename;
                }
                $report['imported']++;
            }
        }
        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function processQueue(int $limit = 8): array
    {
        $result = [
            'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pending' => 0,
            'thumb_created' => 0, 'compressed' => 0, 'webp_created' => 0, 'avif_created' => 0,
            'watermark_applied' => 0, 'skip_compress' => 0, 'skip_webp' => 0,
            'skip_avif' => 0, 'skip_watermark' => 0, 'errors' => [],
        ];

        foreach ($this->queue->nextBatch($limit) as $row) {
            $result['processed']++;
            $task = ['id' => $row['id'], 'filename' => $row['filename'], 'attempts' => $row['attempts']]
                  + $row['options'];
            $report = $this->processTask($task);

            foreach (['thumb_created', 'compressed', 'webp_created', 'avif_created', 'watermark_applied',
                      'skip_compress', 'skip_webp', 'skip_avif', 'skip_watermark'] as $key) {
                $result[$key] += (int)($report[$key] ?? 0);
            }

            if (!empty($report['success'])) {
                $this->queue->markDone($row['id']);
                $result['succeeded']++;
                continue;
            }
            $errors = is_array($report['errors'] ?? null) ? $report['errors'] : ['任务处理失败'];
            $result['failed']++;
            $result['errors'] = array_merge($result['errors'], $errors);
            $this->queue->markFailure($row['id'], (string)($errors[0] ?? '任务处理失败'));
        }
        $result['pending'] = $this->queue->pendingCount();
        return $result;
    }

    /**
     * @return array{pending:int,failed:int}
     */
    public function queueStatus(): array
    {
        return ['pending' => $this->queue->pendingCount(), 'failed' => $this->queue->failedCount()];
    }

    public function enqueue(string $filename, array $options): bool
    {
        $filename = PathService::normalizeIdentifier($filename);
        if ($filename === '') return false;
        if (!ImportQueueRepository::hasWork($options)) return false;
        return $this->queue->enqueue($filename, $options);
    }

    /**
     * Process one task: compress -> convert (webp OR avif) -> watermark
     * -> thumbnail -> remote sync, in that order. Format conversion is
     * mutually exclusive (webp wins if both flags somehow on).
     *
     * @return array<string, mixed>
     */
    public function processTask(array $task): array
    {
        $filename = PathService::normalizeIdentifier((string)($task['filename'] ?? ''));
        $result = [
            'success' => false, 'filename' => $filename, 'final_filename' => $filename,
            'thumb_created' => 0, 'compressed' => 0, 'webp_created' => 0, 'avif_created' => 0,
            'watermark_applied' => 0, 'skip_compress' => 0, 'skip_webp' => 0,
            'skip_avif' => 0, 'skip_watermark' => 0, 'errors' => [],
        ];
        if ($filename === '') {
            $result['errors'][] = '任务缺少图片路径';
            return $result;
        }
        $path = PathService::resolveFilePath($filename);
        if (!is_file($path)) {
            $result['errors'][] = '图片不存在: ' . $filename;
            return $result;
        }

        $finalFilename = $filename;
        $ext = strtolower((string)pathinfo($finalFilename, PATHINFO_EXTENSION));

        if (!empty($task['auto_compress'])) {
            if (ImageFormat::canCompress($ext)) {
                $compress = (new CompressionService())->compress(PathService::resolveFilePath($finalFilename), 85);
                $result[!empty($compress['success']) ? 'compressed' : 'skip_compress']++;
            } else {
                $result['skip_compress']++;
            }
        }

        if (!empty($task['auto_webp'])) {
            $finalFilename = self::tryConvert($finalFilename, 'webp', $result);
            $ext = strtolower((string)pathinfo($finalFilename, PATHINFO_EXTENSION));
        } elseif (!empty($task['auto_avif'])) {
            $finalFilename = self::tryConvert($finalFilename, 'avif', $result);
            $ext = strtolower((string)pathinfo($finalFilename, PATHINFO_EXTENSION));
        }

        if (!empty($task['watermark'])) {
            $w = (new WatermarkService())->apply($finalFilename);
            if (!empty($w['applied'])) $result['watermark_applied']++;
            elseif (!empty($w['enabled'])) $result['skip_watermark']++;
        }

        if (!empty($task['create_thumbnail']) && ThumbnailService::canGenerate($finalFilename)
            && (new ThumbnailService())->create($finalFilename, true)) {
            $result['thumb_created']++;
        }

        if (!empty($task['remote_sync'])) {
            $remote = new RemoteStorage();
            if ($remote->isEnabled() && $remote->isConfigValid()) {
                $remote->syncFileAndThumbnail($finalFilename);
            }
        }

        $result['success'] = true;
        $result['final_filename'] = $finalFilename;
        return $result;
    }

    /**
     * Recursively list image files under $dir (skipping .thumbs/).
     *
     * @return array<int, string>
     */
    public static function collectImagesIn(string $dir): array
    {
        if (!is_dir($dir)) return [];
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return [];
        }
        $allowed = defined('ALLOWED_TYPES') ? ALLOWED_TYPES : [];
        $images = [];
        foreach ($iter as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) continue;
            $path = (string)$item->getPathname();
            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, '/.thumbs/')) continue;
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            $images[] = $path;
        }
        return $images;
    }

    public static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
    }

    /**
     * @param array<int,string> $errors  Out-parameter for collected errors
     * @return array<int, string>
     */
    public function resolveSources(string $sourceInput, array &$errors = []): array
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $sourceInput = trim($sourceInput);
        $usingDefaults = $sourceInput === '';
        $rawItems = $usingDefaults ? ['upload', 'uploads'] : (preg_split('/[\r\n,]+/', $sourceInput) ?: []);

        $sources = [];
        foreach ($rawItems as $rawItem) {
            $item = trim((string)$rawItem);
            $item = trim($item, " \t\n\r\0\x0B\"'");
            if ($item === '') continue;

            $candidate = self::isAbsolutePath($item)
                ? $item
                : $appRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item);
            $real = realpath($candidate);
            if ($real === false || !is_dir($real)) {
                if (!$usingDefaults) $errors[] = '目录不存在: ' . $item;
                continue;
            }
            if (!is_readable($real)) {
                if (!$usingDefaults) $errors[] = '目录不可读: ' . $item;
                continue;
            }
            $sources[rtrim(str_replace('\\', '/', $real), '/')] = $real;
        }
        return array_values($sources);
    }

    public static function relativeIdentifier(string $sourcePath, string $sourceRoot): string
    {
        $normalizedPath = str_replace('\\', '/', $sourcePath);
        $normalizedRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/') . '/';
        $relative = str_starts_with($normalizedPath, $normalizedRoot)
            ? substr($normalizedPath, strlen($normalizedRoot))
            : basename($sourcePath);
        return PathService::normalizeIdentifier($relative);
    }

    /**
     * If $relativeIdentifier already exists in uploads/, append `-1`,
     * `-2`, ... until we find a free slot. Returns '' if 1000 attempts fail.
     */
    public static function uniqueTargetIdentifier(string $relativeIdentifier): string
    {
        $relative = PathService::normalizeIdentifier($relativeIdentifier);
        if ($relative === '') return '';
        $targetPath = UPLOAD_PATH_LOCAL . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!file_exists($targetPath)) return $relative;

        $dir = (string)pathinfo($relative, PATHINFO_DIRNAME);
        $name = (string)pathinfo($relative, PATHINFO_FILENAME);
        $ext = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
        $prefix = $dir !== '.' && $dir !== '' ? $dir . '/' : '';
        for ($i = 1; $i <= 999; $i++) {
            $candidate = $prefix . $name . '-' . $i . ($ext !== '' ? '.' . $ext : '');
            if (!file_exists(UPLOAD_PATH_LOCAL . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Build a hash → filename index of everything currently in the
     * images table. Used to skip duplicates during import.
     *
     * @return array<string, string>
     */
    public function buildHashIndex(): array
    {
        $index = [];
        foreach ($this->images->listIdentifiers() as $filename) {
            $path = PathService::resolveFilePath((string)$filename);
            if (!is_file($path) || !is_readable($path)) continue;
            $hash = @sha1_file($path);
            if (!is_string($hash) || $hash === '') continue;
            $index[$hash] = (string)$filename;
        }
        return $index;
    }

    /**
     * Either record the existing in-uploads file's mapping, or copy a
     * fresh file in. Returns the chosen identifier ('' on failure).
     */
    private function placeFile(
        string $sourcePath,
        string $relativeIdentifier,
        bool $isInCurrent,
        bool $isInLegacy,
        array &$report
    ): string {
        if ($isInCurrent && !$isInLegacy) {
            $identifier = PathService::identifierFromPath($sourcePath);
            if ($identifier === null) {
                $report['failed']++;
                $report['errors'][] = '路径解析失败: ' . $sourcePath;
                return '';
            }
            (new \LitePic\Repository\ImageRepository())->recordOriginalName($identifier, basename($sourcePath));
            return $identifier;
        }

        $targetIdentifier = self::uniqueTargetIdentifier($relativeIdentifier);
        if ($targetIdentifier === '') {
            $report['failed']++;
            $report['errors'][] = '目标路径生成失败: ' . $relativeIdentifier;
            return '';
        }
        $targetPath = UPLOAD_PATH_LOCAL . str_replace('/', DIRECTORY_SEPARATOR, $targetIdentifier);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            $report['failed']++;
            $report['errors'][] = '创建目录失败: ' . $targetDir;
            return '';
        }
        if (!@copy($sourcePath, $targetPath)) {
            $report['failed']++;
            $report['errors'][] = '复制失败: ' . $sourcePath;
            return '';
        }
        $mtime = @filemtime($sourcePath);
        if ($mtime !== false) @touch($targetPath, (int)$mtime);

        $finalFilename = PathService::identifierFromPath($targetPath) ?? $targetIdentifier;
        (new \LitePic\Repository\ImageRepository())->recordOriginalName($finalFilename, $relativeIdentifier);
        return $finalFilename;
    }

    /**
     * Try converting `$filename` to the target format. On success and
     * !KEEP_ORIGINAL_AFTER_PROCESS, removes the source. Returns the new
     * filename (or the original if conversion was skipped/failed).
     */
    private static function tryConvert(string $filename, string $targetExt, array &$result): string
    {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $convertible = $targetExt === 'webp'
            ? ImageFormat::canConvertWebp($ext)
            : ImageFormat::canConvertAvif($ext);
        $skipKey = 'skip_' . $targetExt;

        if (!$convertible) {
            $result[$skipKey]++;
            return $filename;
        }
        $service = new ConversionService();
        $originPath = PathService::resolveFilePath($filename);
        $ok = $targetExt === 'webp' ? $service->toWebp($originPath) : $service->toAvif($originPath);
        if (!$ok) {
            $result[$skipKey]++;
            return $filename;
        }
        $variantPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.' . $targetExt, $originPath);
        if (!is_string($variantPath) || !is_file($variantPath)) {
            $result[$skipKey]++;
            return $filename;
        }
        $variantFilename = PathService::identifierFromPath($variantPath) ?? basename($variantPath);
        if ($variantFilename !== $filename) {
            $keep = defined('KEEP_ORIGINAL_AFTER_PROCESS') && KEEP_ORIGINAL_AFTER_PROCESS;
            if (!$keep) {
                @unlink($originPath);
                (new ThumbnailService())->delete($filename);
            }
        }
        $result[$targetExt . '_created']++;
        return $variantFilename;
    }
}

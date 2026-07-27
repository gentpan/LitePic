<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Core\Logger;
use LitePic\Repository\ImageRepository;
use LitePic\Repository\ImportQueueRepository;
use LitePic\Service\Storage\Remotes;
use Throwable;

/**
 * Async image-processing worker.
 *
 * Drains `import_queue` rows and runs the heavy steps (thumbnail,
 * compression, format conversion, watermark, S3 sync) for each one,
 * out-of-band from the upload request.
 *
 * Two entry points:
 *   • drain(maxItems, maxSeconds) — used by detached upload requests
 *     (after fastcgi_finish_request) and by the optional cron worker.
 *     Bounded by both task count and wall time so a chatty queue can't
 *     hang the request forever.
 *   • drainOne()                  — process exactly one task, used in
 *     tests and by the future "process now" UI button.
 *
 * Concurrency: each task is claimed via a single atomic UPDATE
 * (status pending → processing, tagged with this worker's id) inside
 * {@see \LitePic\Repository\ImportQueueRepository::nextBatch()} — two
 * parallel drains (in-request shutdown + cron) can't both pull the same
 * row. Stale claims (worker died mid-task) get reset back to pending at
 * the top of every drain cycle via reclaimStale().
 */
final class ImageProcessor
{
    private ImportQueueRepository $queue;
    private ImageRepository $images;

    public function __construct(?ImportQueueRepository $queue = null, ?ImageRepository $images = null)
    {
        $this->queue = $queue ?? new ImportQueueRepository();
        $this->images = $images ?? new ImageRepository();
    }

    /**
     * Drain pending tasks until time / count budget is hit. Returns
     * a summary so the caller (request-detached or cron) can log it.
     *
     * @return array{processed:int, failed:int, skipped:int, elapsed_ms:int}
     */
    public function drain(int $maxItems = 20, int $maxSeconds = 25): array
    {
        $start = microtime(true);
        $processed = 0;
        $failed = 0;
        $skipped = 0;

        // 先把上一轮 worker 死亡留下的孤儿行还回 pending — 卡 >10 分钟
        // 仍在 processing 的几乎一定是 worker 进程已经死了。错的话最多
        // 也就是慢任务被重做一次,代价可控。
        try {
            $reclaimed = $this->queue->reclaimStale(600);
            if ($reclaimed > 0) {
                Logger::info('ImageProcessor reclaimed stale rows', ['count' => $reclaimed]);
            }
        } catch (Throwable $_) {
            // Best-effort — a failure here just means we miss a reclaim
            // cycle. Next drain tries again.
        }

        while ($processed + $failed + $skipped < $maxItems) {
            if ((microtime(true) - $start) >= $maxSeconds) break;

            $batch = $this->queue->nextBatch(1);
            if (empty($batch)) break;
            $task = $batch[0];

            try {
                $didWork = $this->processTask($task);
                $this->queue->markDone($task['id']);
                if ($didWork) $processed++;
                else $skipped++;
            } catch (Throwable $e) {
                Logger::error('ImageProcessor task failed', [
                    'task_id'  => $task['id'],
                    'filename' => $task['filename'],
                    'error'    => $e->getMessage(),
                ]);
                $this->queue->markFailure($task['id'], $e->getMessage());
                $failed++;
            }
        }

        return [
            'processed'  => $processed,
            'failed'     => $failed,
            'skipped'    => $skipped,
            'elapsed_ms' => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * Pull and process exactly one task (or none, if queue empty).
     * Returns the task that was processed, or null if queue empty.
     */
    public function drainOne(): ?array
    {
        $batch = $this->queue->nextBatch(1);
        if (empty($batch)) return null;
        $task = $batch[0];
        try {
            $this->processTask($task);
            $this->queue->markDone($task['id']);
        } catch (Throwable $e) {
            $this->queue->markFailure($task['id'], $e->getMessage());
        }
        return $task;
    }

    /**
     * Run all post-processing steps for one queue task.
     * Same logic as the old synchronous block in UploadService::handleSingle:
     *   thumbnail → compress → convert → settle (move/delete variants)
     *   → final thumbnail → watermark → remote sync.
     *
     * @return bool true if any actual work happened (not just options.* all false)
     */
    private function processTask(array $task): bool
    {
        $filename = (string)$task['filename'];
        $opts = $task['options'];

        if (!is_file(PathService::resolveFilePath($filename))) {
            // Source file gone (deleted between enqueue and process) —
            // markDone via caller; nothing to do.
            return false;
        }

        // Safety net: upload path already scans, but imports / retries may not.
        try {
            (new ExifService($this->images))->scanAndStore($filename);
        } catch (Throwable $e) {
            Logger::warning('EXIF scan failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        $thumbnails = new ThumbnailService();
        if (!empty($opts['create_thumbnail'])) {
            $thumbnails->create($filename);
        }

        $processing = ['auto_compress' => [], 'auto_convert' => [], 'auto_webp' => [], 'auto_avif' => []];
        if (!empty($opts['auto_compress']) || !empty($opts['auto_convert']) || !empty($opts['auto_webp']) || !empty($opts['auto_avif'])) {
            $target = ImageFormat::normalizeTarget((string)($opts['auto_convert_target'] ?? \LitePic\Core\Config::liveString('CONVERT_PREFERRED_FORMAT', 'webp'))) ?: 'webp';
            $convertEnabled = !empty($opts['auto_convert']) || !empty($opts['auto_webp']) || !empty($opts['auto_avif']);
            $compressEnabled = !empty($opts['auto_compress']);
            $processing = (new ConversionService())->runUploadPostProcess($filename, $target, $convertEnabled, $compressEnabled);
        }

        // Decide which file is "final" (avif > webp > original) and
        // optionally delete the predecessor (when KEEP_ORIGINAL_AFTER_PROCESS=false).
        [$finalFilename] = self::settleVariants($filename, $processing, $thumbnails);

        if (!empty($opts['watermark'])) {
            $watermark = (new WatermarkService())->apply($finalFilename);
            if (!empty($watermark['applied'])) {
                $this->images->setFlags($finalFilename, ['watermarked' => true]);
            } elseif (!empty($watermark['enabled'])) {
                Logger::warning('Watermark skipped', [
                    'filename' => $finalFilename,
                    'reason' => $watermark['skip_reason'] ?? 'unknown',
                ]);
            }
        }

        // Always (re)create thumbnail for the final variant so the gallery
        // shows the right preview after webp/avif conversion.
        if (ThumbnailService::canGenerate($finalFilename)) {
            $thumbnails->create($finalFilename);
        }

        if (!empty($opts['remote_sync'])) {
            Remotes::active()->syncFileAndThumbnail($finalFilename);
        }

        return true;
    }

    /**
     * Settlement logic: AVIF wins over WebP wins over the original.
     * Mirrors the legacy `UploadService::settleVariants` so behaviour
     * stays identical post-refactor — just runs from the queue worker
     * instead of inline in the upload request.
     *
     * @return array{0:string,1:string,2:bool} [finalFilename, finalUrl, originalDeleted]
     */
    public static function settleVariants(string $identifier, array $processing, ThumbnailService $thumbnails): array
    {
        $finalFilename = $identifier;
        $finalUrl = ImageUrl::forIdentifier($identifier);
        $originalDeleted = false;
        $keepOriginal = \LitePic\Core\Config::liveBool('KEEP_ORIGINAL_AFTER_PROCESS');
        $albumImages = new \LitePic\Repository\AlbumImageRepository();
        $images = new ImageRepository();
        $exif = new ExifService($images);

        $replace = static function (string $old, string $neu) use ($keepOriginal, $thumbnails, $albumImages, $images, $exif, &$originalDeleted): string {
            if ($neu === '' || $neu === $old) {
                return $old;
            }
            $neuPath = PathService::resolveFilePath($neu);
            if (!file_exists($neuPath)) {
                return $old;
            }
            $oldPath = PathService::resolveFilePath($old);
            if (file_exists($oldPath) && basename($oldPath) !== PathService::displayName($neu)) {
                $exif->copyMeta($old, $neu);
                $albumImages->repointFilename($old, $neu);
                if (!$keepOriginal) {
                    @unlink($oldPath);
                    $thumbnails->delete($old);
                    $images->delete($old);
                    $originalDeleted = true;
                }
            }
            return $neu;
        };

        if (!empty($processing['auto_webp']['created']) && !empty($processing['auto_webp']['filename'])) {
            $webpFilename = PathService::normalizeIdentifier((string)$processing['auto_webp']['filename']);
            $finalFilename = $replace($finalFilename, $webpFilename);
            $finalUrl = ImageUrl::forIdentifier($finalFilename);
        }

        if (!empty($processing['auto_avif']['created']) && !empty($processing['auto_avif']['filename'])) {
            $avifFilename = PathService::normalizeIdentifier((string)$processing['auto_avif']['filename']);
            $finalFilename = $replace($finalFilename, $avifFilename);
            $finalUrl = ImageUrl::forIdentifier($finalFilename);
        }

        if (!empty($processing['auto_convert']['created']) && !empty($processing['auto_convert']['filename'])) {
            $convertedFilename = PathService::normalizeIdentifier((string)$processing['auto_convert']['filename']);
            if ($convertedFilename !== $finalFilename) {
                $finalFilename = $replace($finalFilename, $convertedFilename);
                $finalUrl = ImageUrl::forIdentifier($finalFilename);
            }
        }

        return [$finalFilename, $finalUrl, $originalDeleted];
    }
}

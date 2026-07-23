<?php
declare(strict_types=1);

namespace LitePic\Service\Upload;

use LitePic\Repository\ImageRepository;
use LitePic\Service\Hotlink\HotlinkProtection;
use LitePic\Service\Image\ConversionService;
use LitePic\Service\Image\ImageUrl;
use LitePic\Service\Image\PathService;
use LitePic\Service\Image\ThumbnailService;
use LitePic\Service\Image\WatermarkService;
use LitePic\Service\Storage\RemoteStorage;

/**
 * Receives uploaded files (`$_FILES` rows), validates them, writes them
 * to disk, records metadata, enqueues post-processing work (thumbnail +
 * compress + convert + watermark + remote sync), and returns the result
 * array the upload UI / API renders. Upload success means the original
 * file is safely stored; optimization work happens after that.
 *
 * MIME validation reads the real file type using `finfo` (or
 * `mime_content_type` / `getimagesize` fallbacks). If a safe raster image
 * has the wrong suffix (for example JPEG bytes named `.png`), it is saved
 * with the extension that matches the detected MIME. SVG is still
 * special-cased and scanned for `<script>` / event handlers /
 * foreignObject before accepting.
 */
final class UploadService
{
    /** @var array<string,string[]> */
    private const ALLOWED_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        'image/heic-sequence' => ['heic'],
        'image/heif-sequence' => ['heif'],
        'image/x-icon' => ['ico'],
        'image/vnd.microsoft.icon' => ['ico'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tiff', 'tif'],
    ];

    /**
     * @param array<mixed> $files Either a $_FILES['field'] entry or a
     *                            list of pre-normalised file records.
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $files): array
    {
        if (isset($files['name'])) {
            $files = self::normaliseFilesArray($files);
        }
        $results = [];
        foreach ($files as $file) {
            $results[] = $this->handleSingle($file);
        }
        return $results;
    }

    public function maxBytes(): int
    {
        $phpLimit = self::phpUploadLimitBytes();
        $configured = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 0;
        return $phpLimit <= 0 ? $configured : min($configured, $phpLimit);
    }

    /**
     * Server-side ingest path: takes a file that's already on local disk
     * (NOT a PHP-managed $_FILES tmp upload) and runs it through the same
     * validation + dedupe + storage + queue pipeline as the browser flow.
     *
     * Use cases:
     *   - Telegram webhook hands us a file it just downloaded from
     *     api.telegram.org.
     *   - Future: programmatic CLI/cron import where you have a path.
     *
     * Difference vs {@see self::handleSingle()}: we use `rename()` instead
     * of `move_uploaded_file()` (which only works on tmp uploads), and we
     * accept the source's original filename via parameter rather than from
     * `$_FILES`.
     *
     * Path safety:
     *   - Symlinks are always rejected (no follow).
     *   - `$sourcePath`'s realpath must live inside one of `$allowedPrefixes`.
     *   - When `$allowedPrefixes` is empty, we default to `sys_get_temp_dir()`.
     *     This keeps every safe in-tree caller (Telegram webhook) working
     *     unchanged while making any future "import any file by path"
     *     caller fail safely until it explicitly broadens the allowlist.
     *
     * Returns the same shape as `handleSingle()`. On `status === 'success'`
     * the file at `$sourcePath` has been moved into LitePic's storage tree;
     * on any other status the source is left untouched (caller cleans up).
     *
     * @param string[] $allowedPrefixes Caller-supplied allowlist of absolute
     *                                  directory prefixes that $sourcePath
     *                                  is permitted to live under.
     * @return array<string,mixed>
     */
    public function storeFromPath(string $sourcePath, string $originalName, array $allowedPrefixes = []): array
    {
        // Reject symlinks before anything else — is_file follows symlinks,
        // so a `ln -s /etc/hosts.png /tmp/litepic_tg_xxx` would otherwise
        // be ingested as a perfectly legitimate "I own this file" path.
        if (is_link($sourcePath)) {
            return ['status' => 'error', 'message' => "源路径是符号链接,拒绝以避免任意文件读取"];
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return ['status' => 'error', 'message' => "源文件不存在或不可读：{$sourcePath}"];
        }
        // Realpath strips any `..` / symlink chasing and gives us the
        // canonical absolute path. Compare against the allowlist with
        // a trailing-separator-safe prefix check.
        $realSource = realpath($sourcePath);
        if ($realSource === false) {
            return ['status' => 'error', 'message' => "源路径无法 resolve 到真实文件"];
        }
        if ($allowedPrefixes === []) {
            $tmp = realpath(sys_get_temp_dir());
            $allowedPrefixes = $tmp !== false ? [$tmp] : [];
        }
        if (!self::pathInsideAny($realSource, $allowedPrefixes)) {
            return ['status' => 'error', 'message' => "源路径不在允许的目录列表内"];
        }
        // Continue with the (canonical) path so downstream rename/copy
        // doesn't get tricked by a path that resolves differently later.
        $sourcePath = $realSource;

        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExts = defined('ALLOWED_UPLOAD_TYPES') ? ALLOWED_UPLOAD_TYPES : [];
        $uploadType = $this->resolveUploadType($sourcePath, $ext, $allowedExts);
        if ($uploadType === null) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 内容与实际类型不符、格式未启用或包含危险内容"];
        }
        $ext = (string)$uploadType['ext'];
        $detectedMime = (string)$uploadType['mime'];

        $size = (int)@filesize($sourcePath);
        $maxBytes = $this->maxBytes();
        if ($size > $maxBytes) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 超过大小限制（当前上限 " . self::fmt($maxBytes) . '）'];
        }

        // Content-hash dedupe — identical to browser path. If we've seen
        // this exact bytes-on-disk before, hand back the existing image
        // identifier (so callers can still reply with a public URL).
        $hash = @sha1_file($sourcePath);
        $hash = is_string($hash) ? $hash : '';
        $imageRepo = new ImageRepository();
        if ($hash !== '') {
            $duplicate = $imageRepo->findByHashWithBackfill($hash);
            if ($duplicate !== null) {
                $existing = (string)($duplicate['filename'] ?? '');
                @unlink($sourcePath); // dedupe wins → drop the source copy
                return [
                    'status' => 'duplicate',
                    'message' => '图片已存在，已跳过',
                    'duplicate' => true,
                    'filename' => $existing,
                    'original_name' => (string)($duplicate['original_name'] ?? $existing),
                    'url' => ImageUrl::forIdentifier($existing),
                    'thumbnail_url' => ImageUrl::forIdentifier($existing),
                    'hash' => $hash,
                ];
            }
        }

        $filename = PathService::generateFilename($ext);
        $storagePath = PathService::todaysStoragePath();
        $target = $storagePath . $filename;

        if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 存储目录创建失败"];
        }
        if (!is_writable($storagePath)) {
            return [
                'status' => 'error',
                'message' => "存储目录不可写（{$storagePath}）。请把 uploads/data/logs 属主改为运行 PHP 的用户（FrankenPHP 多为 frankenphp，PHP-FPM 多为 www-data）",
            ];
        }
        // rename() works for both same-FS moves and (on most platforms)
        // cross-FS — it's the right primitive for "I already own this file".
        if (!@rename($sourcePath, $target)) {
            // Fallback: copy + unlink, in case rename trips up on a tmpfs
            // cross-device edge case (PHP-FPM tmp dir vs storage volume).
            if (!@copy($sourcePath, $target)) {
                $hint = is_writable($storagePath) ? '' : '（目录不可写，请检查 uploads 属主/权限）';
                return ['status' => 'error', 'message' => "文件 {$originalName} 保存失败{$hint}"];
            }
            @unlink($sourcePath);
        }

        $identifier = PathService::identifierFromPath($target) ?? $filename;
        $imageRepo->recordOriginalName($identifier, $originalName);
        $imageMeta = [
            'hash' => $hash !== '' ? $hash : null,
            'mime' => $detectedMime !== '' ? $detectedMime : self::detectMime($target),
            'size' => is_file($target) ? (int)@filesize($target) : $size,
            'ext' => $ext,
        ];
        $dimensions = @getimagesize($target);
        if (is_array($dimensions)) {
            $imageMeta['width'] = (int)($dimensions[0] ?? 0);
            $imageMeta['height'] = (int)($dimensions[1] ?? 0);
        }
        $imageRepo->update($identifier, $imageMeta);

        // 上传路径只保存原图并入队。缩略图 / 压缩 / 转换 / 水印 /
        // 远程同步统一交给 ImageProcessor 队列，避免大批量上传时每个
        // 请求都在响应前解码图片。
        $thumbnailReady = false;
        $queueOptions = [
            'create_thumbnail'    => ThumbnailService::canGenerate($identifier),
            'auto_compress'       => defined('AUTO_COMPRESS_ON_UPLOAD') && AUTO_COMPRESS_ON_UPLOAD,
            'auto_convert'        => defined('AUTO_CONVERT_ON_UPLOAD') && AUTO_CONVERT_ON_UPLOAD,
            'auto_convert_target' => defined('CONVERT_PREFERRED_FORMAT') ? CONVERT_PREFERRED_FORMAT : 'webp',
            'auto_webp'           => defined('AUTO_CONVERT_WEBP_ON_UPLOAD') && AUTO_CONVERT_WEBP_ON_UPLOAD,
            'auto_avif'           => defined('AUTO_CONVERT_AVIF_ON_UPLOAD') && AUTO_CONVERT_AVIF_ON_UPLOAD,
            'watermark'           => defined('WATERMARK_ENABLED') && WATERMARK_ENABLED,
            'remote_sync'         => (new RemoteStorage())->isEnabled(),
        ];
        $queue = new \LitePic\Repository\ImportQueueRepository();
        $hasWork = \LitePic\Repository\ImportQueueRepository::hasWork($queueOptions);
        if ($hasWork) $queue->enqueue($identifier, $queueOptions);

        return [
            'status' => 'success',
            'filename' => $identifier,
            'original_name' => $originalName,
            'url' => ImageUrl::forIdentifier($identifier),
            'thumbnail_url' => ImageUrl::forIdentifier($identifier),
            'has_thumbnail' => false,
            'processing' => [
                'queued' => $hasWork,
                'queue_options' => $queueOptions,
                'final_filename' => $identifier,
            ],
        ];
    }

    public function uploadErrorMessage(int $errorCode, string $filename = ''): string
    {
        $name = $filename !== '' ? "文件 {$filename} " : '文件 ';
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return $name . '超过 PHP 上传限制（upload_max_filesize/post_max_size）。当前约为 ' . self::fmt(self::phpUploadLimitBytes());
            case UPLOAD_ERR_FORM_SIZE:
                return $name . '超过表单限制大小';
            case UPLOAD_ERR_PARTIAL:
                return $name . '上传不完整（网络中断或连接重置）';
            case UPLOAD_ERR_NO_FILE:
                return $name . '未选择上传文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录（upload_tmp_dir）';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写入磁盘失败';
            case UPLOAD_ERR_EXTENSION:
                return '上传被 PHP 扩展拦截';
            default:
                return $name . '上传失败（错误码: ' . $errorCode . '）';
        }
    }

    public function validateMime(string $tmpPath, string $ext): bool
    {
        $allowedExts = defined('ALLOWED_UPLOAD_TYPES') ? ALLOWED_UPLOAD_TYPES : [];
        return $this->resolveUploadType($tmpPath, $ext, $allowedExts) !== null;
    }

    /**
     * Convert php.ini sizes ("2M", "512K", "1G") to a byte count.
     * "0" or unparseable returns 0; -1 returns 0 (treat as unlimited
     * upstream and skip the comparison).
     */
    public static function iniSizeToBytes($value): int
    {
        $raw = trim((string)$value);
        if ($raw === '') return 0;
        $unit = strtolower(substr($raw, -1));
        $num = (float)$raw;
        if ($num <= 0) return 0;
        $multipliers = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4, 'p' => 1024 ** 5];
        if (isset($multipliers[$unit])) {
            $num *= $multipliers[$unit];
        }
        return (int)round($num);
    }

    /**
     * Smaller of upload_max_filesize and post_max_size.
     */
    public static function phpUploadLimitBytes(): int
    {
        $upload = self::iniSizeToBytes(ini_get('upload_max_filesize'));
        $post = self::iniSizeToBytes(ini_get('post_max_size'));
        if ($upload <= 0 && $post <= 0) return 0;
        if ($upload <= 0) return $post;
        if ($post <= 0) return $upload;
        return min($upload, $post);
    }

    /**
     * Re-shape `$_FILES['field']` (column-of-arrays) into a list of
     * one-record-per-file arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function normaliseFilesArray(array $rawFiles): array
    {
        if (!isset($rawFiles['name'])) return [];
        if (!is_array($rawFiles['name'])) return [$rawFiles];
        return self::transposeFilesArray($rawFiles);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function transposeFilesArray(array $files): array
    {
        $records = [];
        $count = count($files['name']);
        $keys = array_keys($files);
        for ($i = 0; $i < $count; $i++) {
            foreach ($keys as $key) {
                $records[$i][$key] = $files[$key][$i];
            }
        }
        return $records;
    }

    /**
     * @return array<string,mixed>
     */
    private function handleSingle(array $file): array
    {
        $originalName = (string)($file['name'] ?? '');
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => $this->uploadErrorMessage($uploadError, $originalName)];
        }

        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '') {
            return ['status' => 'error', 'message' => "文件 {$originalName} 上传源无效"];
        }

        $allowedExts = defined('ALLOWED_UPLOAD_TYPES') ? ALLOWED_UPLOAD_TYPES : [];
        $uploadType = $this->resolveUploadType($tmpName, $ext, $allowedExts);
        if ($uploadType === null) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 内容与实际类型不符、格式未启用或包含危险内容"];
        }
        $ext = (string)$uploadType['ext'];
        $detectedMime = (string)$uploadType['mime'];

        $maxBytes = $this->maxBytes();
        if ((int)($file['size'] ?? 0) > $maxBytes) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 超过大小限制（当前上限 " . self::fmt($maxBytes) . '）'];
        }

        $hash = @sha1_file($tmpName);
        $hash = is_string($hash) ? $hash : '';
        $imageRepo = new ImageRepository();
        if ($hash !== '') {
            $duplicate = $imageRepo->findByHashWithBackfill($hash);
            if ($duplicate !== null) {
                $existing = (string)($duplicate['filename'] ?? '');
                return [
                    'status' => 'duplicate',
                    'message' => '图片已存在，已跳过',
                    'duplicate' => true,
                    'filename' => $existing,
                    'original_name' => (string)($duplicate['original_name'] ?? $existing),
                    'url' => ImageUrl::forIdentifier($existing),
                    'thumbnail_url' => ImageUrl::forIdentifier($existing),
                    'hash' => $hash,
                ];
            }
        }

        $filename = PathService::generateFilename($ext);
        $storagePath = PathService::todaysStoragePath();
        $target = $storagePath . $filename;

        if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 存储目录创建失败"];
        }
        if (!is_writable($storagePath)) {
            return [
                'status' => 'error',
                'message' => "存储目录不可写（{$storagePath}）。请把 uploads/data/logs 属主改为运行 PHP 的用户（FrankenPHP 多为 frankenphp，PHP-FPM 多为 www-data），例如：chown -R frankenphp:frankenphp uploads data logs",
            ];
        }
        if (!move_uploaded_file($tmpName, $target)) {
            $hint = is_writable($storagePath) ? '' : '（目录不可写，请检查 uploads 属主/权限）';
            return ['status' => 'error', 'message' => "文件 {$originalName} 保存失败{$hint}"];
        }

        $identifier = PathService::identifierFromPath($target) ?? $filename;

        // 立刻把元数据写入 images 表（图片本身已经在磁盘上能直接被 /uploads/...
        // 或 /i/<id> 路由 serve），并把"重活"任务（缩略图、压缩、格式转换、
        // 水印、远程同步）入队 import_queue 等异步 worker 处理。
        // 上传请求只做这两件事 + 返回，~100ms 就能完成。
        $imageRepo->recordOriginalName($identifier, $originalName);
        $imageMeta = [
            'hash' => $hash !== '' ? $hash : null,
            'mime' => $detectedMime !== '' ? $detectedMime : self::detectMime($target),
            'size' => is_file($target) ? (int)@filesize($target) : (int)($file['size'] ?? 0),
            'ext' => $ext,
        ];
        $dimensions = @getimagesize($target);
        if (is_array($dimensions)) {
            $imageMeta['width'] = (int)($dimensions[0] ?? 0);
            $imageMeta['height'] = (int)($dimensions[1] ?? 0);
        }
        $imageRepo->update($identifier, $imageMeta);

        // 根据全局设置决定要做哪些重活(沿用之前同步流程的开关含义)。
        // 缩略图也走同一个队列，上传响应只代表原图已保存。
        $thumbnailReady = false;
        $queueOptions = [
            'create_thumbnail' => ThumbnailService::canGenerate($identifier),
            'auto_compress'    => defined('AUTO_COMPRESS_ON_UPLOAD') && AUTO_COMPRESS_ON_UPLOAD,
            'auto_convert'     => defined('AUTO_CONVERT_ON_UPLOAD') && AUTO_CONVERT_ON_UPLOAD,
            'auto_convert_target' => defined('CONVERT_PREFERRED_FORMAT') ? CONVERT_PREFERRED_FORMAT : 'webp',
            'auto_webp'        => defined('AUTO_CONVERT_WEBP_ON_UPLOAD') && AUTO_CONVERT_WEBP_ON_UPLOAD,
            'auto_avif'        => defined('AUTO_CONVERT_AVIF_ON_UPLOAD') && AUTO_CONVERT_AVIF_ON_UPLOAD,
            'watermark'        => defined('WATERMARK_ENABLED') && WATERMARK_ENABLED,
            'remote_sync'      => (new RemoteStorage())->isEnabled(),
        ];

        $queue = new \LitePic\Repository\ImportQueueRepository();
        $hasWork = \LitePic\Repository\ImportQueueRepository::hasWork($queueOptions);
        if ($hasWork) {
            $queue->enqueue($identifier, $queueOptions);
        }

        return [
            'status' => 'success',
            'filename' => $identifier,
            'original_name' => $originalName,
            'url' => ImageUrl::forIdentifier($identifier),
            'thumbnail_url' => ImageUrl::forIdentifier($identifier),
            'has_thumbnail' => false,
            'processing' => [
                'queued'           => $hasWork,
                'queue_options'    => $queueOptions,
                'final_filename'   => $identifier,
                'auto_compress'    => [],
                'auto_webp'        => [],
                'auto_avif'        => [],
                'watermark'        => [],
                'remote_storage'   => [],
                'original_deleted' => false,
            ],
        ];
    }

    /**
     * Settlement logic moved to \LitePic\Service\Image\ImageProcessor::settleVariants().
     * Kept here as a back-compat shim in case anything still calls it
     * (no public callers in the current codebase).
     *
     * @return array{0:string,1:string,2:bool} [filename, url, originalDeleted]
     */
    private function settleVariants(string $identifier, array $processing, ThumbnailService $thumbnails): array
    {
        return \LitePic\Service\Image\ImageProcessor::settleVariants($identifier, $processing, $thumbnails);
    }

    /**
     * Resolve the final stored extension from detected MIME.
     *
     * For known raster formats we trust the file content over the original
     * suffix. This keeps uploads safe while fixing common cases where a JPEG
     * or WebP is downloaded with a `.png` filename.
     *
     * @param string[] $allowedExts
     * @return array{ext:string,mime:string,normalized:bool}|null
     */
    private function resolveUploadType(string $tmpPath, string $ext, array $allowedExts): ?array
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) return null;

        $ext = strtolower(ltrim(trim($ext), '.'));
        $allowedExts = array_values(array_unique(array_map(
            static fn($item) => strtolower(ltrim(trim((string)$item), '.')),
            $allowedExts
        )));

        if ($ext === 'svg') {
            if (!in_array('svg', $allowedExts, true)) return null;
            return self::validateSvg($tmpPath)
                ? ['ext' => 'svg', 'mime' => 'image/svg+xml', 'normalized' => false]
                : null;
        }

        $mime = self::detectMime($tmpPath);
        if ($mime === null || $mime === '') return null;
        $mime = strtolower($mime);

        if (isset(self::ALLOWED_MIMES[$mime])) {
            $candidates = self::ALLOWED_MIMES[$mime];
            if (in_array($ext, $candidates, true) && in_array($ext, $allowedExts, true)) {
                return ['ext' => $ext, 'mime' => $mime, 'normalized' => false];
            }

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $allowedExts, true)) {
                    return ['ext' => $candidate, 'mime' => $mime, 'normalized' => $candidate !== $ext];
                }
            }

            return null;
        }

        // 用户自添的扩展名（如 heic / jxl / raw / dng），不在预设 MIME 表里。
        // 仍要确保文件内容是 image/*，并且原扩展名在后台允许列表中。
        if (str_starts_with($mime, 'image/') && $ext !== '' && in_array($ext, $allowedExts, true)) {
            return ['ext' => $ext, 'mime' => $mime, 'normalized' => false];
        }

        return null;
    }

    private static function detectMime(string $tmpPath): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) return null;
            $mime = finfo_file($finfo, $tmpPath);
            return $mime === false ? null : (string)$mime;
        }
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($tmpPath);
            return $mime === false ? null : (string)$mime;
        }
        $info = @getimagesize($tmpPath);
        if ($info === false) return null;
        return (string)($info['mime'] ?? '');
    }

    private static function validateSvg(string $tmpPath): bool
    {
        $content = @file_get_contents($tmpPath);
        if ($content === false) return false;
        // Decode entities first so attackers can't hide tags behind &#x3c;
        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lower = strtolower($decoded);
        $dangerous = [
            '<script', 'javascript:', 'onload=', 'onerror=', 'onmouseover=',
            'onfocus=', 'onbegin=', 'onend=', 'onactivate=', 'onclick=',
            '<foreignobject', 'xlink:href', 'data:image/svg+xml',
        ];
        foreach ($dangerous as $needle) {
            if (str_contains($lower, $needle)) return false;
        }
        return true;
    }

    private static function fmt(int $bytes): string
    {
        return function_exists('format_filesize') ? \LitePic\Core\Format::filesize($bytes) : ($bytes . ' B');
    }

    /**
     * Check whether `$path` is a descendant of any directory in `$prefixes`.
     * Both sides are normalised so trailing separators / different
     * canonical forms don't cause false negatives.
     *
     * @param string[] $prefixes
     */
    private static function pathInsideAny(string $path, array $prefixes): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        foreach ($prefixes as $prefix) {
            $prefix = is_string($prefix) ? rtrim($prefix, DIRECTORY_SEPARATOR) : '';
            if ($prefix === '') continue;
            // Require either an exact match or a path that continues with
            // the directory separator — guards against `/tmp/lite` matching
            // `/tmp/litepic_evil_dir/...` when prefix is `/tmp/lite`.
            if ($path === $prefix) return true;
            if (str_starts_with($path, $prefix . DIRECTORY_SEPARATOR)) return true;
        }
        return false;
    }
}

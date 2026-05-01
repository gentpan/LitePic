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
 * Receives uploaded files (`$_FILES` rows), validates them, writes to
 * disk, runs the post-process pipeline (compress + convert + thumbnail +
 * watermark + remote sync), and returns the result array the upload
 * UI / API renders.
 *
 * MIME validation rejects extension-spoofed uploads using `finfo` (or
 * `mime_content_type` / `getimagesize` fallbacks), and special-cases
 * SVG to scan for `<script>` / event handlers / foreignObject before
 * accepting.
 */
final class UploadService
{
    /** @var array<int,array<string,string[]>> */
    private const ALLOWED_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
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
        if (!is_file($tmpPath) || !is_readable($tmpPath)) return false;

        if ($ext === 'svg') {
            return self::validateSvg($tmpPath);
        }

        $mime = self::detectMime($tmpPath);
        if ($mime === null) return false;

        return isset(self::ALLOWED_MIMES[$mime])
            && in_array($ext, self::ALLOWED_MIMES[$mime], true);
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
        switch ($unit) {
            case 'p': $num *= 1024;
            case 't': $num *= 1024;
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
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
        $allowedExts = defined('ALLOWED_UPLOAD_TYPES') ? ALLOWED_UPLOAD_TYPES : [];
        if (!in_array($ext, $allowedExts, true)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 类型不支持"];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName !== '' && !$this->validateMime($tmpName, $ext)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 内容与实际类型不符或包含危险内容"];
        }

        $maxBytes = $this->maxBytes();
        if ((int)($file['size'] ?? 0) > $maxBytes) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 超过大小限制（当前上限 " . self::fmt($maxBytes) . '）'];
        }
        if ($tmpName === '') {
            return ['status' => 'error', 'message' => "文件 {$originalName} 上传源无效"];
        }

        $filename = PathService::generateFilename($ext);
        $storagePath = PathService::todaysStoragePath();
        $target = $storagePath . $filename;

        if (!is_dir($storagePath) && !mkdir($storagePath, 0755, true) && !is_dir($storagePath)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 存储目录创建失败"];
        }
        if (!move_uploaded_file($tmpName, $target)) {
            return ['status' => 'error', 'message' => "文件 {$originalName} 保存失败"];
        }

        $identifier = PathService::identifierFromPath($target) ?? $filename;

        if (function_exists('save_original_filename')) {
            save_original_filename($identifier, $originalName);
        }
        $thumbnails = new ThumbnailService();
        $thumbnails->create($identifier);

        $conversion = new ConversionService();
        $processing = $conversion->runUploadPostProcess($identifier);

        [$finalFilename, $finalUrl, $originalDeleted] = $this->settleVariants($identifier, $processing, $thumbnails);

        $watermark = (new WatermarkService())->apply($finalFilename);

        $finalThumbnailUrl = $finalUrl;
        if (ThumbnailService::canGenerate($finalFilename) && $thumbnails->create($finalFilename)) {
            $finalThumbnailUrl = ImageUrl::thumbnailUrl($finalFilename);
        }

        $remoteSync = (new RemoteStorage())->syncFileAndThumbnail($finalFilename);

        $processing['original_deleted'] = $originalDeleted;
        $processing['final_filename'] = $finalFilename;
        $processing['remote_storage'] = $remoteSync;
        $processing['watermark'] = $watermark;

        return [
            'status' => 'success',
            'filename' => $finalFilename,
            'original_name' => $originalName,
            'url' => $finalUrl,
            'thumbnail_url' => $finalThumbnailUrl,
            'processing' => $processing,
        ];
    }

    /**
     * After post-process, decide which file becomes the "final" one and
     * whether to drop the previous variant. AVIF wins over WebP wins
     * over the original (when KEEP_ORIGINAL_AFTER_PROCESS is off).
     *
     * @return array{0:string,1:string,2:bool} [filename, url, originalDeleted]
     */
    private function settleVariants(string $identifier, array $processing, ThumbnailService $thumbnails): array
    {
        $finalFilename = $identifier;
        $finalUrl = ImageUrl::forIdentifier($identifier);
        $originalDeleted = false;
        $keepOriginal = defined('KEEP_ORIGINAL_AFTER_PROCESS') && KEEP_ORIGINAL_AFTER_PROCESS;

        if (!empty($processing['auto_webp']['created']) && !empty($processing['auto_webp']['filename'])) {
            $webpFilename = PathService::normalizeIdentifier((string)$processing['auto_webp']['filename']);
            $webpPath = PathService::resolveFilePath($webpFilename);
            if (file_exists($webpPath)) {
                $originPath = PathService::resolveFilePath($identifier);
                if (file_exists($originPath) && basename($originPath) !== PathService::displayName($webpFilename)) {
                    if (!$keepOriginal) {
                        @unlink($originPath);
                        $thumbnails->delete($identifier);
                        (new ImageRepository())->delete($identifier);
                        $originalDeleted = true;
                    }
                }
                $finalFilename = $webpFilename;
                $finalUrl = ImageUrl::forIdentifier($webpFilename);
            }
        }

        if (!empty($processing['auto_avif']['created']) && !empty($processing['auto_avif']['filename'])) {
            $avifFilename = PathService::normalizeIdentifier((string)$processing['auto_avif']['filename']);
            $avifPath = PathService::resolveFilePath($avifFilename);
            if (file_exists($avifPath)) {
                $prevPath = PathService::resolveFilePath($finalFilename);
                if (file_exists($prevPath) && basename($prevPath) !== PathService::displayName($avifFilename)) {
                    if (!$keepOriginal) {
                        @unlink($prevPath);
                        $thumbnails->delete($finalFilename);
                        (new ImageRepository())->delete($finalFilename);
                        $originalDeleted = true;
                    }
                }
                $finalFilename = $avifFilename;
                $finalUrl = ImageUrl::forIdentifier($avifFilename);
            }
        }

        return [$finalFilename, $finalUrl, $originalDeleted];
    }

    private static function detectMime(string $tmpPath): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) return null;
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
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
        return function_exists('format_filesize') ? format_filesize($bytes) : ($bytes . ' B');
    }
}

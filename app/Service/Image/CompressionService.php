<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Repository\CompressionKeyRepository;

/**
 * Lossy compression of JPG/PNG. Three backends, picked by
 * `COMPRESSION_MODE`:
 *
 *   - imagemagick — shells out to `magick`/`convert`; best quality
 *   - tinypng     — TinyPNG API; round-robins through the keys in the
 *                   compression_api_keys table, accounting for usage
 *                   and rate-limit responses
 *   - gd          — pure-PHP fallback; no external dependencies
 *
 * `compress()` is the public dispatcher; the three backends are
 * private statics so they can be unit-tested via the dispatcher
 * without leaking the procedural surface.
 */
final class CompressionService
{
    public function mode(): string
    {
        return ImageFormat::compressionMode();
    }

    /**
     * Run lossy compression in-place. Returns the backend that won.
     *
     * @return array{success:bool, method:?string, mode:string}
     */
    public function compress(string $path, int $quality = 85): array
    {
        $mode = $this->mode();
        $ok = match ($mode) {
            'gd' => self::compressWithGd($path, $quality),
            'tinypng' => (defined('ENABLE_COMPRESSION') && ENABLE_COMPRESSION) && self::compressWithTinyPng($path),
            default => self::compressWithImagick($path, $quality),
        };
        return ['success' => $ok, 'method' => $ok ? $mode : null, 'mode' => $mode];
    }

    /**
     * Auto-compress immediately after a successful upload. Skipped when
     * AUTO_COMPRESS_ON_UPLOAD is off, or when an automatic format
     * conversion (WebP/AVIF) is also enabled — those handle their own
     * compression and we don't want to double-process.
     *
     * @return array<string,mixed>
     */
    public function autoCompressAfterUpload(string $filename): array
    {
        $result = [
            'enabled' => defined('AUTO_COMPRESS_ON_UPLOAD') && AUTO_COMPRESS_ON_UPLOAD,
            'attempted' => false,
            'compressed' => false,
            'method' => null,
            'skip_reason' => null,
            'before_size' => 0,
            'after_size' => 0,
            'saved_bytes' => 0,
            'saved_percent' => 0.0,
            'before_size_text' => '0 B',
            'after_size_text' => '0 B',
            'saved_size_text' => '0 B',
        ];

        if (!$result['enabled']) {
            $result['skip_reason'] = 'disabled';
            return $result;
        }
        if (
            (defined('AUTO_CONVERT_WEBP_ON_UPLOAD') && AUTO_CONVERT_WEBP_ON_UPLOAD) ||
            (defined('AUTO_CONVERT_AVIF_ON_UPLOAD') && AUTO_CONVERT_AVIF_ON_UPLOAD)
        ) {
            $result['skip_reason'] = 'conversion_enabled';
            return $result;
        }

        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!ImageFormat::canCompress($ext)) {
            $result['skip_reason'] = 'unsupported_format';
            return $result;
        }
        $path = PathService::resolveFilePath($filename);
        if (!file_exists($path)) {
            $result['skip_reason'] = 'missing_file';
            return $result;
        }

        $before = filesize($path);
        if ($before === false || $before <= 0) {
            $result['skip_reason'] = 'size_unavailable';
            return $result;
        }
        $result['attempted'] = true;
        $result['before_size'] = $before;
        $result['before_size_text'] = self::fmt($before);

        $compress = $this->compress($path, 85);
        $method = $compress['method'];
        clearstatcache(true, $path);
        $after = filesize($path);
        if ($after === false || $after <= 0) {
            $result['skip_reason'] = 'size_unavailable_after';
            return $result;
        }
        $result['after_size'] = $after;
        $result['after_size_text'] = self::fmt($after);

        if ($method === null) {
            $result['skip_reason'] = 'compress_failed';
            return $result;
        }

        $saved = max(0, $before - $after);
        $result['saved_bytes'] = $saved;
        $result['saved_size_text'] = self::fmt($saved);
        $result['saved_percent'] = $before > 0 ? round(($saved / $before) * 100, 2) : 0;
        $result['method'] = $method;

        if ($saved <= 0) {
            $result['skip_reason'] = 'not_reduced';
            return $result;
        }

        $result['compressed'] = true;
        (new ThumbnailService())->create($filename, true);
        return $result;
    }

    public static function compressWithImagick(string $filepath, int $quality = 85): bool
    {
        try {
            if (!file_exists($filepath) || !is_readable($filepath)) {
                error_log("ImageMagick: file not readable: {$filepath}");
                return false;
            }
            if (!function_exists('exec') && !function_exists('shell_exec')) {
                return false;
            }

            // Probe for `magick` first (modern), fall back to `convert` (legacy).
            $bin = null;
            foreach (['magick -version', 'convert -version', 'where magick', 'where convert'] as $probe) {
                $out = null;
                $rc = null;
                @exec($probe . ' 2>&1', $out, $rc);
                if ($rc === 0 && !empty($out)) {
                    $bin = strpos($probe, 'magick') !== false ? 'magick' : 'convert';
                    break;
                }
            }
            if (!$bin) {
                error_log('ImageMagick: binary not found in PATH');
                return false;
            }

            $ext = strtolower((string)pathinfo($filepath, PATHINFO_EXTENSION));
            $tmp = $filepath . '.imtmp';
            $quality = max(10, min(100, $quality));

            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -interlace Plane -quality {$quality} " . escapeshellarg($tmp);
            } elseif ($ext === 'png') {
                $level = max(0, min(9, (int)round((100 - $quality) / 11)));
                $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -define png:compression-level={$level} -quality {$quality} " . escapeshellarg($tmp);
            } else {
                $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -quality {$quality} " . escapeshellarg($tmp);
            }

            $execOut = [];
            @exec($cmd . ' 2>&1', $execOut, $rc);
            if ($rc !== 0) {
                error_log("ImageMagick command failed ({$cmd}): " . implode("\n", $execOut));
                @unlink($tmp);
                return false;
            }
            if (!file_exists($tmp) || filesize($tmp) === 0) {
                @unlink($tmp);
                return false;
            }

            $orig = (int)filesize($filepath);
            $new = (int)filesize($tmp);
            if ($new > 0 && $new <= $orig) {
                if (!@rename($tmp, $filepath)) {
                    if (@copy($tmp, $filepath)) {
                        @unlink($tmp);
                    } else {
                        @unlink($tmp);
                        return false;
                    }
                }
                clearstatcache(true, $filepath);
                return true;
            }
            @unlink($tmp);
            return false;
        } catch (\Throwable $e) {
            error_log('ImageMagick compression error: ' . $e->getMessage());
            return false;
        }
    }

    public static function compressWithTinyPng(string $filepath): bool
    {
        try {
            if (!file_exists($filepath) || !is_readable($filepath)) {
                throw new \RuntimeException('文件不可读');
            }
            $ext = strtolower((string)pathinfo($filepath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                return false;
            }
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('服务器未启用 cURL 扩展');
            }

            $repo = new CompressionKeyRepository();
            $apis = $repo->active();
            if ($apis === [] && defined('TINIFY_API_KEYS') && is_array(TINIFY_API_KEYS)) {
                foreach (TINIFY_API_KEYS as $legacyKey) {
                    if (is_string($legacyKey) && $legacyKey !== '') {
                        $apis[] = ['id' => null, 'name' => 'legacy', 'api_key' => $legacyKey, 'used_count' => 0];
                    }
                }
            }
            if ($apis === []) {
                throw new \RuntimeException('未配置可用的 TinyPNG API Key');
            }

            // Round-robin: prefer the least-used key first.
            usort($apis, static fn (array $a, array $b) => (int)($a['used_count'] ?? 0) <=> (int)($b['used_count'] ?? 0));

            foreach ($apis as $api) {
                $key = (string)($api['api_key'] ?? '');
                $apiId = isset($api['id']) ? (string)$api['id'] : null;
                if ($key === '') continue;

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'https://api.tinify.com/shrink',
                    CURLOPT_USERPWD => 'api:' . $key,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => file_get_contents($filepath),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'LitePic/3.0',
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                $response = curl_exec($ch);
                if ($response === false) {
                    $err = curl_error($ch);
                    if (PHP_VERSION_ID < 80500) curl_close($ch);
                    if ($apiId !== null) $repo->recordUsage($apiId, false, 0, 'cURL: ' . $err);
                    continue;
                }
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $body = substr((string)$response, $headerSize);
                if (PHP_VERSION_ID < 80500) curl_close($ch);

                if ($status === 429) {
                    if ($apiId !== null) $repo->recordUsage($apiId, false, 429, 'rate_limited');
                    continue;
                }
                if ($status >= 400) {
                    if ($apiId !== null) $repo->recordUsage($apiId, false, $status, 'http_error');
                    continue;
                }

                $data = json_decode($body, true);
                $downloadUrl = $data['output']['url'] ?? null;
                if (!$downloadUrl) {
                    if ($apiId !== null) $repo->recordUsage($apiId, false, $status, 'missing_output_url');
                    continue;
                }

                $dl = curl_init($downloadUrl);
                curl_setopt($dl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($dl, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($dl, CURLOPT_TIMEOUT, 20);
                $compressed = curl_exec($dl);
                if (PHP_VERSION_ID < 80500) curl_close($dl);
                if ($compressed === false) {
                    if ($apiId !== null) $repo->recordUsage($apiId, false, $status, 'download_failed');
                    continue;
                }

                if (file_put_contents($filepath, $compressed) !== false) {
                    if ($apiId !== null) $repo->recordUsage($apiId, true, $status, null);
                    return true;
                }
                if ($apiId !== null) $repo->recordUsage($apiId, false, $status, 'write_failed');
            }
            throw new \RuntimeException('所有 API key 均尝试失败或超时');
        } catch (\Throwable $e) {
            error_log('TinyPNG compression failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function compressWithGd(string $filepath, int $quality = 85): bool
    {
        if (!extension_loaded('gd')) return false;
        if (!file_exists($filepath) || !is_readable($filepath)) return false;

        $ext = strtolower((string)pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) return false;

        $original = filesize($filepath);
        if ($original === false || $original <= 0) return false;

        $quality = max(10, min(100, $quality));
        $tmp = $filepath . '.gdtmp';
        @unlink($tmp);

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            if (!function_exists('imagecreatefromjpeg')) return false;
            $img = @imagecreatefromjpeg($filepath);
            if ($img === false) return false;
            $ok = @imagejpeg($img, $tmp, $quality);
        } else {
            if (!function_exists('imagecreatefrompng')) return false;
            $img = @imagecreatefrompng($filepath);
            if ($img === false) return false;
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $level = max(0, min(9, (int)round((100 - $quality) / 11)));
            $ok = @imagepng($img, $tmp, $level);
        }
        if ($ok !== true) {
            @unlink($tmp);
            return false;
        }
        if (!file_exists($tmp)) return false;
        clearstatcache(true, $tmp);

        $newSize = filesize($tmp);
        if ($newSize === false || $newSize <= 0 || $newSize > $original) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $filepath)) {
            if (@copy($tmp, $filepath)) {
                @unlink($tmp);
            } else {
                @unlink($tmp);
                return false;
            }
        }
        clearstatcache(true, $filepath);
        return true;
    }

    private static function fmt(int $bytes): string
    {
        return function_exists('format_filesize') ? format_filesize($bytes) : ($bytes . ' B');
    }
}

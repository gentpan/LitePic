<?php
declare(strict_types=1);

namespace LitePic\Service\Storage;

use LitePic\Core\Database;
use LitePic\Repository\RemoteDeleteQueueRepository;
use LitePic\Service\Image\ImageUrl;
use LitePic\Service\Image\PathService;
use LitePic\Service\Image\ThumbnailService;
use SimpleXMLElement;

/**
 * S3-compatible (AWS S3 / Cloudflare R2 / etc.) storage layer.
 *
 * Handles SigV4 signing for object operations (PUT/DELETE/GET) and
 * bucket operations (ListObjectsV2). Coordinates with the
 * RemoteDeleteQueueRepository so deletions can be deferred (default
 * 24h delay; configurable via REMOTE_STORAGE_DELETE_DELAY_SECONDS) —
 * the delete queue is also drained opportunistically on every sync /
 * delete call so a healthy upload pipeline keeps the queue trimmed
 * without a separate cron.
 */
final class RemoteStorage
{
    private const SERVICE = 's3';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function isEnabled(): bool { return $this->credentialsValid(); }
    public function isConfigValid(): bool { return $this->credentialsValid(); }

    public function credentialsValid(): bool
    {
        return defined('S3_BUCKET') && S3_BUCKET !== ''
            && defined('S3_KEY') && S3_KEY !== ''
            && defined('S3_SECRET') && S3_SECRET !== ''
            && defined('S3_ENDPOINT') && S3_ENDPOINT !== '';
    }

    public function usage(): string
    {
        $usage = defined('REMOTE_STORAGE_USAGE') ? strtolower((string)REMOTE_STORAGE_USAGE) : 'backup';
        return in_array($usage, ['backup', 'storage'], true) ? $usage : 'backup';
    }

    public function mode(): string
    {
        return $this->credentialsValid() ? 'sync' : 'off';
    }

    /**
     * Public CDN delivery is enabled only when the bucket is meant
     * to serve traffic ('storage' mode) AND a base URL is configured.
     */
    public function publicDeliveryEnabled(): bool
    {
        return $this->usage() === 'storage'
            && $this->credentialsValid()
            && defined('S3_PUBLIC_BASE_URL')
            && trim((string)S3_PUBLIC_BASE_URL) !== '';
    }

    public function publicUrlForObjectKey(string $objectKey): ?string
    {
        $objectKey = trim($objectKey, '/');
        if (!$this->publicDeliveryEnabled() || $objectKey === '') {
            return null;
        }
        $base = rtrim((string)S3_PUBLIC_BASE_URL, '/');
        if ($base === '') return null;
        return $base . '/' . self::encodeKey($objectKey);
    }

    public function publicUrlForIdentifier(string $identifier): ?string
    {
        $identifier = PathService::normalizeIdentifier($identifier);
        if ($identifier === '') return null;
        return $this->publicUrlForObjectKey(self::prefix() . $identifier);
    }

    public function publicUrlForLocalPath(string $localPath): ?string
    {
        $key = self::objectKeyFromLocalPath($localPath);
        if ($key === null) return null;
        return $this->publicUrlForObjectKey($key);
    }

    public function objectKeyForFilename(string $filename): ?string
    {
        return self::objectKeyFromLocalPath(PathService::resolveFilePath($filename));
    }

    public function objectKeyForThumbnail(string $filename): ?string
    {
        return self::objectKeyFromLocalPath(ImageUrl::thumbnailPath($filename));
    }

    /**
     * Upload local file → object_key derived from its path under uploads/.
     *
     * @return array{success:bool,status:int,error:?string,object_key:?string}
     */
    public function uploadLocalFile(string $localPath): array
    {
        if (!file_exists($localPath)) {
            return ['success' => false, 'error' => '本地文件不存在', 'status' => 0, 'object_key' => null];
        }
        $objectKey = self::objectKeyFromLocalPath($localPath);
        if ($objectKey === null) {
            return ['success' => false, 'error' => '对象路径解析失败', 'status' => 0, 'object_key' => null];
        }
        $data = file_get_contents($localPath);
        if ($data === false) {
            return ['success' => false, 'error' => '读取本地文件失败', 'status' => 0, 'object_key' => $objectKey];
        }
        $mime = self::guessContentType($localPath);
        $res = $this->objectRequest('PUT', $objectKey, $data, $mime);
        return [
            'success' => (bool)($res['success'] ?? false),
            'status' => (int)($res['status'] ?? 0),
            'error' => $res['error'] ?? null,
            'object_key' => $objectKey,
        ];
    }

    public function deleteObject(string $objectKey): bool
    {
        $res = $this->objectRequest('DELETE', $objectKey, '');
        return (bool)($res['success'] ?? false);
    }

    /**
     * Sync the original + thumbnail to remote. Drains the deferred
     * delete queue first so it doesn't grow unbounded.
     *
     * @return array<string, mixed>
     */
    public function syncFileAndThumbnail(string $filename): array
    {
        $this->processDeleteQueue();

        $result = [
            'enabled' => $this->isEnabled(),
            'mode' => $this->mode(),
            'usage' => $this->usage(),
            'configured' => $this->credentialsValid(),
            'public_delivery' => $this->publicDeliveryEnabled(),
            'uploaded' => [],
            'errors' => [],
        ];
        if (!$this->isEnabled()) return $result;
        if (!$this->credentialsValid()) {
            $result['errors'][] = '远程存储配置不完整';
            return $result;
        }

        $mainPath = PathService::resolveFilePath($filename);
        if (file_exists($mainPath)) {
            $main = $this->uploadLocalFile($mainPath);
            if (!empty($main['success'])) {
                $result['uploaded'][] = (string)($main['object_key'] ?? '');
            } else {
                $result['errors'][] = '主图上传失败: ' . (string)($main['error'] ?? 'unknown');
            }
        } else {
            $result['errors'][] = '主图不存在';
        }

        $thumbPath = ImageUrl::thumbnailPath($filename);
        if (file_exists($thumbPath)) {
            $thumb = $this->uploadLocalFile($thumbPath);
            if (!empty($thumb['success'])) {
                $result['uploaded'][] = (string)($thumb['object_key'] ?? '');
            } else {
                $result['errors'][] = '缩略图上传失败: ' . (string)($thumb['error'] ?? 'unknown');
            }
        }
        return $result;
    }

    /**
     * Queue the file + thumbnail for deferred deletion. Default delay
     * comes from REMOTE_STORAGE_DELETE_DELAY_SECONDS (24h). Also
     * opportunistically drains the queue.
     */
    public function deleteFileAndThumbnail(string $filename): void
    {
        $this->processDeleteQueue();

        $keys = [];
        $fileKey = $this->objectKeyForFilename($filename);
        if (is_string($fileKey) && $fileKey !== '') {
            $keys[] = $fileKey;
        }
        $thumbKey = $this->objectKeyForThumbnail($filename);
        if (is_string($thumbKey) && $thumbKey !== '') {
            $keys[] = $thumbKey;
        }
        $delay = defined('REMOTE_STORAGE_DELETE_DELAY_SECONDS') ? (int)REMOTE_STORAGE_DELETE_DELAY_SECONDS : 86400;
        $repo = new RemoteDeleteQueueRepository();
        foreach (array_unique($keys) as $key) {
            $repo->enqueue($key, $delay);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function listObjects(string $prefix = '', string $continuationToken = ''): array
    {
        $query = ['list-type' => '2', 'max-keys' => '1000'];
        if ($prefix !== '') $query['prefix'] = $prefix;
        if ($continuationToken !== '') $query['continuation-token'] = $continuationToken;

        $res = $this->bucketRequest('GET', $query, '');
        if (empty($res['success'])) {
            return [
                'success' => false,
                'error' => (string)($res['error'] ?? '列举远程对象失败'),
                'objects' => [], 'is_truncated' => false, 'next_token' => '',
            ];
        }
        $body = (string)($res['body'] ?? '');
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [
                'success' => false,
                'error' => '解析远程对象列表失败',
                'objects' => [], 'is_truncated' => false, 'next_token' => '',
            ];
        }

        $objects = [];
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $item) {
                $key = (string)($item->Key ?? '');
                if ($key !== '') $objects[] = $key;
            }
        }
        return [
            'success' => true,
            'error' => null,
            'objects' => $objects,
            'is_truncated' => ((string)($xml->IsTruncated ?? 'false')) === 'true',
            'next_token' => (string)($xml->NextContinuationToken ?? ''),
        ];
    }

    /**
     * Wipe everything under the configured prefix (or the whole bucket
     * if no prefix). Paginates with a 500-loop safety cap.
     */
    public function deleteAllObjects(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => '远程存储配置不完整', 'deleted' => 0, 'failed' => 0];
        }

        $prefix = self::prefix();
        $deleted = $failed = $loops = 0;
        $token = '';
        $maxLoops = 500;

        do {
            if (++$loops > $maxLoops) {
                return ['success' => false, 'message' => '删除中止：分页次数过多，请稍后重试', 'deleted' => $deleted, 'failed' => $failed];
            }
            $list = $this->listObjects($prefix, $token);
            if (empty($list['success'])) {
                return ['success' => false, 'message' => '列举对象失败：' . (string)($list['error'] ?? 'unknown'), 'deleted' => $deleted, 'failed' => $failed];
            }
            $objects = is_array($list['objects'] ?? null) ? $list['objects'] : [];
            foreach ($objects as $key) {
                if (!is_string($key) || $key === '') continue;
                if ($this->deleteObject($key)) $deleted++;
                else $failed++;
            }
            $token = (string)($list['next_token'] ?? '');
            $hasMore = !empty($list['is_truncated']) && $token !== '';
        } while ($hasMore);

        $scope = $prefix !== '' ? ('前缀 ' . $prefix) : '整个 Bucket';
        $msg = sprintf('远程清理完成（%s）：成功 %d，失败 %d', $scope, $deleted, $failed);
        if ($failed === 0) {
            // Bucket is empty now — nothing left for the deferred delete queue to do.
            Database::connection()->exec('DELETE FROM remote_delete_queue');
        }
        return ['success' => $failed === 0, 'message' => $msg, 'deleted' => $deleted, 'failed' => $failed];
    }

    public function testConnection(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => 'S3/R2 配置不完整'];
        }
        $probeKey = self::prefix() . '.healthcheck/litepic-' . gmdate('YmdHis') . '.txt';
        $put = $this->objectRequest('PUT', $probeKey, 'litepic-health-check', 'text/plain');
        if (empty($put['success'])) {
            return ['success' => false, 'message' => '连接失败（上传测试失败）: ' . (string)($put['error'] ?? 'unknown')];
        }
        if (!$this->deleteObject($probeKey)) {
            return ['success' => false, 'message' => '连接成功但清理测试文件失败，请检查删除权限'];
        }
        $queue = $this->processDeleteQueue();
        $suffix = ((int)($queue['deleted'] ?? 0) > 0)
            ? sprintf('；已处理到期远程删除 %d 个', (int)$queue['deleted'])
            : '';
        return ['success' => true, 'message' => '连接测试成功' . $suffix];
    }

    /**
     * Push every locally-known image (and its thumbnail) up to remote.
     */
    public function syncAllLocalImages(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => '远程存储配置不完整', 'total' => 0, 'synced' => 0, 'failed' => 0];
        }
        $images = function_exists('get_uploaded_images') ? get_uploaded_images() : [];
        $total = count($images);
        $synced = $failed = 0;
        $errors = [];

        foreach ($images as $filename) {
            $res = $this->syncFileAndThumbnail((string)$filename);
            if (!empty($res['errors']) && is_array($res['errors'])) {
                $failed++;
                $errors[] = (string)$filename . ': ' . implode(' | ', array_slice($res['errors'], 0, 2));
            } else {
                $synced++;
            }
        }

        $message = sprintf('远程同步完成：总计 %d，成功 %d，失败 %d', $total, $synced, $failed);
        if (!empty($errors)) {
            $message .= '；示例错误：' . implode(' ; ', array_slice($errors, 0, 3));
        }
        return [
            'success' => $failed === 0,
            'message' => $message,
            'total' => $total, 'synced' => $synced, 'failed' => $failed, 'errors' => $errors,
        ];
    }

    /**
     * Pull everything under the prefix back down to local uploads/.
     * Auto-thumbnails restored originals; gives them an identity
     * filename map so the gallery picks them up.
     */
    public function restoreAllToLocal(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => '远程存储配置不完整', 'total' => 0, 'restored' => 0, 'failed' => 0];
        }

        $prefix = self::prefix();
        $token = '';
        $loops = $total = $restored = $failed = 0;
        $maxLoops = 500;
        $errors = [];

        do {
            if (++$loops > $maxLoops) {
                return ['success' => false, 'message' => '恢复中止：分页次数过多，请稍后重试', 'total' => $total, 'restored' => $restored, 'failed' => $failed, 'errors' => $errors];
            }
            $list = $this->listObjects($prefix, $token);
            if (empty($list['success'])) {
                return ['success' => false, 'message' => '列举远程对象失败：' . (string)($list['error'] ?? 'unknown'), 'total' => $total, 'restored' => $restored, 'failed' => $failed, 'errors' => $errors];
            }
            $objects = is_array($list['objects'] ?? null) ? $list['objects'] : [];
            foreach ($objects as $objectKey) {
                if (!is_string($objectKey) || $objectKey === '') continue;
                $total++;

                $relative = $objectKey;
                if ($prefix !== '' && str_starts_with($objectKey, $prefix)) {
                    $relative = substr($objectKey, strlen($prefix));
                }
                $relative = ltrim((string)$relative, '/');
                if ($relative === '') continue;

                $targetPath = rtrim((string)UPLOAD_PATH_LOCAL, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    $failed++;
                    $errors[] = '创建目录失败: ' . $targetDir;
                    continue;
                }

                $get = $this->objectRequest('GET', $objectKey, '');
                if (empty($get['success'])) {
                    $failed++;
                    $errors[] = '下载失败: ' . $objectKey . ' (' . (string)($get['error'] ?? 'unknown') . ')';
                    continue;
                }
                $body = (string)($get['body'] ?? '');
                if ($body === '') {
                    $failed++;
                    $errors[] = '下载为空: ' . $objectKey;
                    continue;
                }
                if (file_put_contents($targetPath, $body, LOCK_EX) === false) {
                    $failed++;
                    $errors[] = '写入失败: ' . $targetPath;
                    continue;
                }

                // Top up the original-name map + thumbnail for restored originals.
                $basename = basename($targetPath);
                if (!preg_match('/\.thumb\./i', $basename) && ThumbnailService::canGenerate($basename)) {
                    (new ThumbnailService())->create($basename, true);
                    if (function_exists('get_original_filename') && get_original_filename($basename) === null) {
                        if (function_exists('save_original_filename')) {
                            save_original_filename($basename, $basename);
                        }
                    }
                }
                $restored++;
            }

            $token = (string)($list['next_token'] ?? '');
            $hasMore = !empty($list['is_truncated']) && $token !== '';
        } while ($hasMore);

        $message = sprintf('远程恢复完成：总计 %d，成功 %d，失败 %d', $total, $restored, $failed);
        if (!empty($errors)) {
            $message .= '；示例错误：' . implode(' ; ', array_slice($errors, 0, 3));
        }
        return [
            'success' => $failed === 0,
            'message' => $message,
            'total' => $total, 'restored' => $restored, 'failed' => $failed, 'errors' => $errors,
        ];
    }

    /**
     * Drain due entries from the deferred deletion queue. Called from
     * sync / delete / test paths so a healthy pipeline keeps it
     * trimmed without a separate cron.
     */
    public function processDeleteQueue(int $limit = 25): array
    {
        $repo = new RemoteDeleteQueueRepository();
        $result = ['processed' => 0, 'deleted' => 0, 'failed' => 0, 'pending' => 0];

        $total = $repo->totalCount();
        if ($total === 0) return $result;
        if (!$this->credentialsValid()) {
            $result['pending'] = $total;
            return $result;
        }

        foreach ($repo->dueNow($limit) as $item) {
            $result['processed']++;
            if ($this->deleteObject($item['object_key'])) {
                $repo->delete($item['id']);
                $result['deleted']++;
                continue;
            }
            $attempts = $item['attempts'] + 1;
            $backoff = min(3600 * $attempts, 86400);
            $repo->recordFailure($item['id'], 'delete_failed', time() + $backoff);
            $result['failed']++;
        }
        $result['pending'] = $repo->totalCount();
        return $result;
    }

    public static function prefix(): string
    {
        $prefix = trim(defined('S3_PATH_PREFIX') ? (string)S3_PATH_PREFIX : '', '/');
        return $prefix === '' ? '' : $prefix . '/';
    }

    public static function relativePath(string $localPath): ?string
    {
        $normalized = str_replace('\\', '/', $localPath);
        $base = rtrim(str_replace('\\', '/', (string)UPLOAD_PATH_LOCAL), '/') . '/';
        if (!str_starts_with($normalized, $base)) return null;
        $relative = ltrim(substr($normalized, strlen($base)), '/');
        return $relative === '' ? null : $relative;
    }

    public static function objectKeyFromLocalPath(string $localPath): ?string
    {
        $relative = self::relativePath($localPath);
        return $relative === null ? null : self::prefix() . $relative;
    }

    public static function endpointHost(): string
    {
        $endpoint = trim(defined('S3_ENDPOINT') ? (string)S3_ENDPOINT : '');
        if ($endpoint === '') return '';
        $parts = parse_url($endpoint);
        return (string)($parts['host'] ?? '');
    }

    public static function endpointBase(): string
    {
        return rtrim(trim(defined('S3_ENDPOINT') ? (string)S3_ENDPOINT : ''), '/');
    }

    public static function encodeKey(string $objectKey): string
    {
        $parts = array_map('rawurlencode', explode('/', ltrim($objectKey, '/')));
        return implode('/', $parts);
    }

    public static function guessContentType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') return $mime;
        }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'tif', 'tiff' => 'image/tiff',
            'json' => 'application/json',
            'txt', 'log' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * Object-scoped SigV4 request (PUT / DELETE / GET on a specific key).
     *
     * @return array{success:bool,status:int,error:?string,body?:string}
     */
    public function objectRequest(string $method, string $objectKey, ?string $body = null, string $contentType = 'application/octet-stream'): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'error' => 'cURL 扩展未启用'];
        }
        if (!$this->credentialsValid()) {
            return ['success' => false, 'status' => 0, 'error' => '远程存储配置不完整'];
        }
        $endpoint = self::endpointBase();
        $host = self::endpointHost();
        if ($endpoint === '' || $host === '') {
            return ['success' => false, 'status' => 0, 'error' => 'S3_ENDPOINT 无效'];
        }

        $bucket = trim((string)S3_BUCKET);
        $region = self::region();
        $payload = $body ?? '';
        $payloadHash = hash('sha256', $payload);
        $keyPath = self::encodeKey($objectKey);
        $canonicalUri = '/' . rawurlencode($bucket) . '/' . $keyPath;

        $sig = self::sign($method, $canonicalUri, '', $payloadHash, $host, $region);
        $url = $endpoint . $canonicalUri;

        $headers = [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $sig['amz_date'],
            'Authorization: ' . $sig['authorization'],
        ];
        if (strtoupper($method) === 'PUT') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        return self::curlExec($url, $method, $payload, $headers, /*timeout*/ 30);
    }

    /**
     * Bucket-scoped SigV4 request (ListObjectsV2 etc.). Uses URL-encoded
     * query parameters in canonical form.
     *
     * @param array<string,string> $query
     */
    public function bucketRequest(string $method, array $query = [], string $body = '', string $contentType = 'application/xml'): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'error' => 'cURL 扩展未启用'];
        }
        if (!$this->credentialsValid()) {
            return ['success' => false, 'status' => 0, 'error' => '远程存储配置不完整'];
        }
        $endpoint = self::endpointBase();
        $host = self::endpointHost();
        if ($endpoint === '' || $host === '') {
            return ['success' => false, 'status' => 0, 'error' => 'S3_ENDPOINT 无效'];
        }

        $bucket = trim((string)S3_BUCKET);
        $region = self::region();

        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            $key = rawurlencode((string)$k);
            $pairs[] = ($v === null || $v === '')
                ? $key . '='
                : $key . '=' . rawurlencode((string)$v);
        }
        $canonicalQuery = implode('&', $pairs);
        $canonicalUri = '/' . rawurlencode($bucket);
        $url = $endpoint . $canonicalUri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

        $payloadHash = hash('sha256', $body);
        $sig = self::sign($method, $canonicalUri, $canonicalQuery, $payloadHash, $host, $region);

        $headers = [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $sig['amz_date'],
            'Authorization: ' . $sig['authorization'],
        ];
        if ($body !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        return self::curlExec($url, $method, $body, $headers, /*timeout*/ 60);
    }

    private static function region(): string
    {
        $region = trim(defined('S3_REGION') ? (string)S3_REGION : 'auto');
        return $region === '' ? 'auto' : $region;
    }

    /**
     * SigV4 calculation: returns ['authorization' => ..., 'amz_date' => ...]
     */
    private static function sign(
        string $method,
        string $canonicalUri,
        string $canonicalQuery,
        string $payloadHash,
        string $host,
        string $region
    ): array {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = strtoupper($method) . "\n"
                          . $canonicalUri . "\n"
                          . $canonicalQuery . "\n"
                          . $canonicalHeaders . "\n"
                          . $signedHeaders . "\n"
                          . $payloadHash;

        $credentialScope = "{$dateStamp}/{$region}/" . self::SERVICE . "/aws4_request";
        $stringToSign = self::ALGORITHM . "\n"
                      . $amzDate . "\n"
                      . $credentialScope . "\n"
                      . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . S3_SECRET, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = self::ALGORITHM
            . ' Credential=' . S3_KEY . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return ['authorization' => $authorization, 'amz_date' => $amzDate];
    }

    /**
     * @param array<int,string> $headers
     * @return array{success:bool,status:int,error:?string,body:string}
     */
    private static function curlExec(string $url, string $method, string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== '' && in_array(strtoupper($method), ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80500) curl_close($ch);

        if ($respBody === false) {
            return ['success' => false, 'status' => $status, 'error' => $curlError !== '' ? $curlError : '远程请求失败', 'body' => ''];
        }
        $success = $status >= 200 && $status < 300;
        return [
            'success' => $success,
            'status' => $status,
            'error' => $success ? null : ('HTTP ' . $status),
            'body' => is_string($respBody) ? $respBody : '',
        ];
    }
}

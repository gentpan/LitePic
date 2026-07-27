<?php
declare(strict_types=1);

namespace LitePic\Service\Storage;

use LitePic\Core\Database;
use LitePic\Core\Format;
use LitePic\Repository\RemoteDeleteQueueRepository;
use LitePic\Service\Image\ImageUrl;
use LitePic\Service\Image\PathService;
use LitePic\Service\Image\ThumbnailService;

/**
 * External WebDAV client used as remote backup or primary storage —
 * same roles as {@see RemoteStorage} (S3/R2), opposite of the old
 * LitePic-as-WebDAV-server feature which has been removed.
 *
 * Speaks Basic auth + PUT / GET / DELETE / MKCOL / PROPFIND. Nested
 * object keys trigger parent MKCOL so typical netdisk WebDAV roots
 * accept uploads without a pre-created tree.
 */
final class WebDavRemoteStorage implements RemoteBackendInterface
{
    public function isEnabled(): bool
    {
        return $this->credentialsValid();
    }

    public function isConfigValid(): bool
    {
        return $this->credentialsValid();
    }

    public function credentialsValid(): bool
    {
        return $this->baseUrl() !== ''
            && $this->username() !== ''
            && $this->password() !== '';
    }

    public function usage(): string
    {
        $usage = defined('REMOTE_STORAGE_USAGE') ? strtolower((string)REMOTE_STORAGE_USAGE) : 'backup';
        return in_array($usage, ['backup', 'storage'], true) ? $usage : 'backup';
    }

    public function publicDeliveryEnabled(): bool
    {
        return $this->usage() === 'storage'
            && $this->credentialsValid()
            && $this->publicBaseUrl() !== '';
    }

    public function publicUrlForObjectKey(string $objectKey): ?string
    {
        $objectKey = trim($objectKey, '/');
        if (!$this->publicDeliveryEnabled() || $objectKey === '') {
            return null;
        }
        $base = $this->publicBaseUrl();
        if ($base === '') {
            return null;
        }
        return $base . '/' . RemoteStorage::encodeKey($objectKey);
    }

    public function publicUrlForIdentifier(string $identifier): ?string
    {
        $identifier = PathService::normalizeIdentifier($identifier);
        if ($identifier === '') {
            return null;
        }
        return $this->publicUrlForObjectKey(self::prefix() . $identifier);
    }

    public function publicUrlForLocalPath(string $localPath): ?string
    {
        $key = self::objectKeyFromLocalPath($localPath);
        return $key === null ? null : $this->publicUrlForObjectKey($key);
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
     * @return array<string,string>
     */
    public static function envFromPostedForm(): array
    {
        $usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? (defined('REMOTE_STORAGE_USAGE') ? REMOTE_STORAGE_USAGE : 'backup'))));
        if (!in_array($usage, ['backup', 'storage'], true)) {
            $usage = 'backup';
        }
        $driver = strtolower(trim((string)($_POST['remote_storage_driver'] ?? (defined('REMOTE_STORAGE_DRIVER') ? REMOTE_STORAGE_DRIVER : 's3'))));
        if ($driver !== 'webdav') {
            $driver = 's3';
        }

        $url = trim((string)($_POST['remote_webdav_url'] ?? (defined('REMOTE_WEBDAV_URL') ? REMOTE_WEBDAV_URL : '')));
        $user = trim((string)($_POST['remote_webdav_username'] ?? (defined('REMOTE_WEBDAV_USERNAME') ? REMOTE_WEBDAV_USERNAME : '')));
        $prefix = trim((string)($_POST['remote_webdav_path_prefix'] ?? (defined('REMOTE_WEBDAV_PATH_PREFIX') ? REMOTE_WEBDAV_PATH_PREFIX : 'uploads')), '/');
        $public = trim((string)($_POST['remote_webdav_public_base_url'] ?? (defined('REMOTE_WEBDAV_PUBLIC_BASE_URL') ? REMOTE_WEBDAV_PUBLIC_BASE_URL : '')));

        $passwordPosted = (string)($_POST['remote_webdav_password'] ?? '');
        $password = $passwordPosted !== ''
            ? $passwordPosted
            : (defined('REMOTE_WEBDAV_PASSWORD') ? (string)REMOTE_WEBDAV_PASSWORD : '');

        return [
            'REMOTE_STORAGE_USAGE' => $usage,
            'REMOTE_STORAGE_DRIVER' => $driver,
            'REMOTE_WEBDAV_URL' => Format::envQuote($url),
            'REMOTE_WEBDAV_USERNAME' => Format::envQuote($user),
            'REMOTE_WEBDAV_PASSWORD' => Format::envQuote($password),
            'REMOTE_WEBDAV_PATH_PREFIX' => Format::envQuote($prefix),
            'REMOTE_WEBDAV_PUBLIC_BASE_URL' => Format::envQuote(rtrim($public, '/')),
        ];
    }

    public static function postedFormIsComplete(): bool
    {
        $current = static fn (string $field, string $constName) => trim(
            (string)($_POST[$field] ?? (defined($constName) ? constant($constName) : ''))
        );
        $usage = strtolower($current('remote_storage_usage', 'REMOTE_STORAGE_USAGE'));
        $url = $current('remote_webdav_url', 'REMOTE_WEBDAV_URL');
        $user = $current('remote_webdav_username', 'REMOTE_WEBDAV_USERNAME');
        $passwordPosted = trim((string)($_POST['remote_webdav_password'] ?? ''));
        $passwordExisting = defined('REMOTE_WEBDAV_PASSWORD') ? trim((string)REMOTE_WEBDAV_PASSWORD) : '';
        $password = $passwordPosted !== '' ? $passwordPosted : $passwordExisting;
        if ($url === '' || $user === '' || $password === '') {
            return false;
        }
        return $usage !== 'storage' || $current('remote_webdav_public_base_url', 'REMOTE_WEBDAV_PUBLIC_BASE_URL') !== '';
    }

    /**
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
        $mime = RemoteStorage::guessContentType($localPath);
        $res = $this->putObject($objectKey, $data, $mime);
        return [
            'success' => (bool)($res['success'] ?? false),
            'status' => (int)($res['status'] ?? 0),
            'error' => $res['error'] ?? null,
            'object_key' => $objectKey,
        ];
    }

    public function uploadLocalFileAs(string $localPath, string $objectKey): array
    {
        if (!file_exists($localPath)) {
            return ['ok' => false, 'error' => '本地文件不存在', 'status' => 0, 'object_key' => $objectKey];
        }
        $data = @file_get_contents($localPath);
        if ($data === false) {
            return ['ok' => false, 'error' => '读取本地文件失败', 'status' => 0, 'object_key' => $objectKey];
        }
        $mime = RemoteStorage::guessContentType($localPath);
        $res = $this->putObject($objectKey, $data, $mime);
        return [
            'ok' => (bool)($res['success'] ?? false),
            'status' => (int)($res['status'] ?? 0),
            'error' => $res['error'] ?? null,
            'object_key' => $objectKey,
        ];
    }

    public function deleteObject(string $objectKey): bool
    {
        $res = $this->request('DELETE', $objectKey);
        $status = (int)($res['status'] ?? 0);
        // 404 = already gone — treat as success for queue drain.
        return !empty($res['success']) || $status === 404;
    }

    public function syncFileAndThumbnail(string $filename): array
    {
        $this->processDeleteQueue();

        $result = [
            'enabled' => $this->isEnabled(),
            'mode' => $this->credentialsValid() ? 'sync' : 'off',
            'usage' => $this->usage(),
            'configured' => $this->credentialsValid(),
            'public_delivery' => $this->publicDeliveryEnabled(),
            'driver' => 'webdav',
            'uploaded' => [],
            'errors' => [],
        ];
        if (!$this->isEnabled()) {
            return $result;
        }
        if (!$this->credentialsValid()) {
            $result['errors'][] = 'WebDAV 远程存储配置不完整';
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

    public function testConnection(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => 'WebDAV 配置不完整（需要 URL、用户名、密码）'];
        }
        $probeKey = self::prefix() . '.healthcheck/litepic-' . gmdate('YmdHis') . '.txt';
        $put = $this->putObject($probeKey, 'litepic-health-check', 'text/plain');
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
        return ['success' => true, 'message' => 'WebDAV 连接测试成功' . $suffix];
    }

    public function syncAllLocalImages(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => 'WebDAV 配置不完整', 'total' => 0, 'synced' => 0, 'failed' => 0];
        }
        $images = (new \LitePic\Repository\ImageRepository())->listIdentifiersSafe();
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

        $message = sprintf('WebDAV 同步完成：总计 %d，成功 %d，失败 %d', $total, $synced, $failed);
        if (!empty($errors)) {
            $message .= '；示例错误：' . implode(' ; ', array_slice($errors, 0, 3));
        }
        return [
            'success' => $failed === 0,
            'message' => $message,
            'total' => $total, 'synced' => $synced, 'failed' => $failed, 'errors' => $errors,
        ];
    }

    public function restoreAllToLocal(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => 'WebDAV 配置不完整', 'total' => 0, 'restored' => 0, 'failed' => 0];
        }

        $list = $this->listObjects(self::prefix());
        if (empty($list['success'])) {
            return [
                'success' => false,
                'message' => '列举 WebDAV 对象失败：' . (string)($list['error'] ?? 'unknown'),
                'total' => 0, 'restored' => 0, 'failed' => 0, 'errors' => [],
            ];
        }

        $prefix = self::prefix();
        $total = $restored = $failed = 0;
        $errors = [];

        foreach ($list['objects'] as $objectKey) {
            if (!is_string($objectKey) || $objectKey === '' || str_ends_with($objectKey, '/')) {
                continue;
            }
            $total++;

            $relative = $objectKey;
            if ($prefix !== '' && str_starts_with($objectKey, $prefix)) {
                $relative = substr($objectKey, strlen($prefix));
            }
            $relative = ltrim((string)$relative, '/');
            if ($relative === '') {
                continue;
            }

            $targetPath = rtrim((string)UPLOAD_PATH_LOCAL, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $failed++;
                $errors[] = '创建目录失败: ' . $targetDir;
                continue;
            }

            $get = $this->request('GET', $objectKey);
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

            $basename = basename($targetPath);
            if (!preg_match('/\.thumb\./i', $basename) && ThumbnailService::canGenerate($basename)) {
                (new ThumbnailService())->create($basename, true);
                $imgRepo = new \LitePic\Repository\ImageRepository();
                if ($imgRepo->originalNameFor($basename) === null) {
                    $imgRepo->recordOriginalName($basename, $basename);
                }
            }
            $restored++;
        }

        $message = sprintf('WebDAV 恢复完成：总计 %d，成功 %d，失败 %d', $total, $restored, $failed);
        if (!empty($errors)) {
            $message .= '；示例错误：' . implode(' ; ', array_slice($errors, 0, 3));
        }
        return [
            'success' => $failed === 0,
            'message' => $message,
            'total' => $total, 'restored' => $restored, 'failed' => $failed, 'errors' => $errors,
        ];
    }

    public function deleteAllObjects(): array
    {
        if (!$this->credentialsValid()) {
            return ['success' => false, 'message' => 'WebDAV 配置不完整', 'deleted' => 0, 'failed' => 0];
        }

        $list = $this->listObjects(self::prefix());
        if (empty($list['success'])) {
            return [
                'success' => false,
                'message' => '列举对象失败：' . (string)($list['error'] ?? 'unknown'),
                'deleted' => 0,
                'failed' => 0,
            ];
        }

        // Delete files first, then collections deepest-first.
        $files = [];
        $dirs = [];
        foreach ($list['objects'] as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (str_ends_with($key, '/')) {
                $dirs[] = rtrim($key, '/');
            } else {
                $files[] = $key;
            }
        }
        usort($dirs, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        $deleted = $failed = 0;
        foreach (array_merge($files, $dirs) as $key) {
            if ($this->deleteObject($key)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        $prefix = self::prefix();
        $scope = $prefix !== '' ? ('前缀 ' . rtrim($prefix, '/')) : 'WebDAV 根';
        $msg = sprintf('WebDAV 清理完成（%s）：成功 %d，失败 %d', $scope, $deleted, $failed);
        if ($failed === 0) {
            Database::connection()->exec('DELETE FROM remote_delete_queue');
        }
        return ['success' => $failed === 0, 'message' => $msg, 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * @return array{processed:int,deleted:int,failed:int,pending:int}
     */
    public function processDeleteQueue(int $limit = 25): array
    {
        $repo = new RemoteDeleteQueueRepository();
        $result = ['processed' => 0, 'deleted' => 0, 'failed' => 0, 'pending' => 0];

        $total = $repo->totalCount();
        if ($total === 0) {
            return $result;
        }
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

    /**
     * Recursive PROPFIND under prefix. Returns both files and collection
     * paths (collections end with `/`).
     *
     * @return array{success:bool,error:?string,objects:list<string>}
     */
    public function listObjects(string $prefix = ''): array
    {
        $prefix = trim($prefix, '/');
        $href = $prefix === '' ? '' : $prefix . '/';
        $seen = [];
        $queue = [$href];
        $objects = [];
        $loops = 0;

        while ($queue !== [] && $loops < 2000) {
            $loops++;
            $current = array_shift($queue);
            if (!is_string($current) || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            $prop = $this->propfind($current, 1);
            if (empty($prop['success'])) {
                // Empty / missing collection is fine at the root of a prefix.
                if ($current === $href && ((int)($prop['status'] ?? 0) === 404)) {
                    return ['success' => true, 'error' => null, 'objects' => []];
                }
                return [
                    'success' => false,
                    'error' => (string)($prop['error'] ?? 'PROPFIND 失败'),
                    'objects' => [],
                ];
            }

            foreach ($prop['entries'] as $entry) {
                $rel = (string)($entry['path'] ?? '');
                if ($rel === '' || $rel === $current || $rel === rtrim($current, '/')) {
                    continue;
                }
                if (!empty($entry['collection'])) {
                    $dir = rtrim($rel, '/') . '/';
                    $objects[] = $dir;
                    if (!isset($seen[$dir])) {
                        $queue[] = $dir;
                    }
                } else {
                    $objects[] = $rel;
                }
            }
        }

        return ['success' => true, 'error' => null, 'objects' => array_values(array_unique($objects))];
    }

    public static function prefix(): string
    {
        $prefix = trim(defined('REMOTE_WEBDAV_PATH_PREFIX') ? (string)REMOTE_WEBDAV_PATH_PREFIX : '', '/');
        return $prefix === '' ? '' : $prefix . '/';
    }

    public static function objectKeyFromLocalPath(string $localPath): ?string
    {
        $relative = RemoteStorage::relativePath($localPath);
        return $relative === null ? null : self::prefix() . $relative;
    }

    /**
     * @return array{success:bool,status:int,error:?string,body?:string}
     */
    private function putObject(string $objectKey, string $body, string $contentType): array
    {
        $this->ensureParentCollections($objectKey);
        return $this->request('PUT', $objectKey, $body, [
            'Content-Type: ' . $contentType,
        ]);
    }

    private function ensureParentCollections(string $objectKey): void
    {
        $parts = array_values(array_filter(explode('/', trim($objectKey, '/')), static fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return;
        }
        array_pop($parts);
        $built = '';
        foreach ($parts as $part) {
            $built = $built === '' ? $part : ($built . '/' . $part);
            $this->mkcol($built);
        }
    }

    private function mkcol(string $collectionKey): void
    {
        $res = $this->request('MKCOL', $collectionKey);
        $status = (int)($res['status'] ?? 0);
        // 201 created, 405/409 already exists — all fine for our purpose.
        if (!empty($res['success']) || in_array($status, [405, 409, 301, 302], true)) {
            return;
        }
    }

    /**
     * @return array{success:bool,status:int,error:?string,entries:list<array{path:string,collection:bool}>}
     */
    private function propfind(string $relativePath, int $depth): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
        $res = $this->request('PROPFIND', $relativePath, $body, [
            'Depth: ' . max(0, $depth),
            'Content-Type: application/xml; charset=utf-8',
        ], /*okStatuses*/ [207, 200]);
        if (empty($res['success'])) {
            return [
                'success' => false,
                'status' => (int)($res['status'] ?? 0),
                'error' => $res['error'] ?? 'PROPFIND 失败',
                'entries' => [],
            ];
        }

        $xml = @simplexml_load_string((string)($res['body'] ?? ''));
        if ($xml === false) {
            return [
                'success' => false,
                'status' => (int)($res['status'] ?? 0),
                'error' => '解析 PROPFIND 响应失败',
                'entries' => [],
            ];
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $responses = $xml->xpath('//d:response') ?: [];
        $entries = [];
        foreach ($responses as $response) {
            $hrefNodes = $response->xpath('d:href');
            $href = isset($hrefNodes[0]) ? (string)$hrefNodes[0] : '';
            $path = $this->hrefToObjectKey($href);
            if ($path === null) {
                continue;
            }
            $collectionNodes = $response->xpath('.//d:resourcetype/d:collection');
            $entries[] = [
                'path' => $path,
                'collection' => is_array($collectionNodes) && $collectionNodes !== [],
            ];
        }
        return [
            'success' => true,
            'status' => (int)($res['status'] ?? 0),
            'error' => null,
            'entries' => $entries,
        ];
    }

    private function hrefToObjectKey(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $parts = parse_url($href);
            $href = (string)($parts['path'] ?? '');
        }
        $href = rawurldecode($href);

        $basePath = $this->basePath();
        if ($basePath !== '' && str_starts_with($href, $basePath)) {
            $href = substr($href, strlen($basePath));
        }
        $href = '/' . ltrim($href, '/');
        // Keep trailing slash marker for collections, then trim to relative key.
        $isCollection = str_ends_with($href, '/');
        $key = trim($href, '/');
        if ($key === '') {
            return $isCollection ? '' : null;
        }
        return $isCollection ? ($key . '/') : $key;
    }

    /**
     * @param list<int>|null $okStatuses
     * @param list<string> $extraHeaders
     * @return array{success:bool,status:int,error:?string,body:string}
     */
    private function request(
        string $method,
        string $objectKey,
        string $body = '',
        array $extraHeaders = [],
        ?array $okStatuses = null
    ): array {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'error' => 'cURL 扩展未启用', 'body' => ''];
        }
        if (!$this->credentialsValid()) {
            return ['success' => false, 'status' => 0, 'error' => 'WebDAV 配置不完整', 'body' => ''];
        }

        $url = $this->objectUrl($objectKey);
        if ($url === null) {
            return ['success' => false, 'status' => 0, 'error' => 'WebDAV URL 无效', 'body' => ''];
        }

        $headers = array_merge([
            'Authorization: Basic ' . base64_encode($this->username() . ':' . $this->password()),
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($body !== '' || in_array(strtoupper($method), ['PUT', 'PROPFIND', 'MKCOL'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($respBody === false) {
            return [
                'success' => false,
                'status' => $status,
                'error' => $curlError !== '' ? $curlError : 'WebDAV 请求失败',
                'body' => '',
            ];
        }

        $ok = $okStatuses === null
            ? ($status >= 200 && $status < 300)
            : in_array($status, $okStatuses, true);

        return [
            'success' => $ok,
            'status' => $status,
            'error' => $ok ? null : ('HTTP ' . $status),
            'body' => is_string($respBody) ? $respBody : '',
        ];
    }

    private function objectUrl(string $objectKey): ?string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return null;
        }
        $key = trim(str_replace('\\', '/', $objectKey), '/');
        if ($key === '') {
            return $base . '/';
        }
        return $base . '/' . RemoteStorage::encodeKey($key);
    }

    private function baseUrl(): string
    {
        $url = defined('REMOTE_WEBDAV_URL') ? trim((string)REMOTE_WEBDAV_URL) : '';
        return rtrim($url, '/');
    }

    private function basePath(): string
    {
        $parts = parse_url($this->baseUrl());
        $path = (string)($parts['path'] ?? '');
        return rtrim($path, '/');
    }

    private function username(): string
    {
        return defined('REMOTE_WEBDAV_USERNAME') ? trim((string)REMOTE_WEBDAV_USERNAME) : '';
    }

    private function password(): string
    {
        return defined('REMOTE_WEBDAV_PASSWORD') ? (string)REMOTE_WEBDAV_PASSWORD : '';
    }

    private function publicBaseUrl(): string
    {
        return defined('REMOTE_WEBDAV_PUBLIC_BASE_URL')
            ? rtrim(trim((string)REMOTE_WEBDAV_PUBLIC_BASE_URL), '/')
            : '';
    }
}

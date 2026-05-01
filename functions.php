<?php
declare(strict_types=1);
/**
 * LitePic - 核心函数库
 */

// 在 functions.php 开头添加检查 WebP 支持
if (!function_exists('imagewebp')) {
    error_log('[Theme Notice] Server does not support WebP conversion');
    if (!defined('ENABLE_WEBP')) {
        define('ENABLE_WEBP', false);
    }
} else {
    if (!defined('ENABLE_WEBP')) {
        define('ENABLE_WEBP', true);
    }
}


require_once 'config.php';

/**
 * ENV 持久化辅助：将值编码为 .env 可写格式
 */
function env_quote_for_file(string $value): string {
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
}

/**
 * ENV 持久化辅助：批量写入 .env（覆盖同名键，不影响其他行）
 */
function write_env_kv(array $updates): bool {
    $env_path = __DIR__ . '/.env';
    $lines = [];
    if (is_file($env_path)) {
        $existing = file($env_path, FILE_IGNORE_NEW_LINES);
        if (is_array($existing)) {
            $lines = $existing;
        }
    }

    $remaining = $updates;
    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches)) {
            continue;
        }
        $key = $matches[1];
        if (!array_key_exists($key, $remaining)) {
            continue;
        }
        $lines[$index] = $key . '=' . (string)$remaining[$key];
        unset($remaining[$key]);
    }

    if (!empty($remaining)) {
        if (!empty($lines) && trim((string)end($lines)) !== '') {
            $lines[] = '';
        }
        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . (string)$value;
        }
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    return file_put_contents($env_path, $content, LOCK_EX) !== false;
}

/**
 * 获取上传目录中所有图片文件
 */
function get_uploaded_images() {
    try {
        return (new \LitePic\Repository\ImageRepository())->listIdentifiers('date-desc');
    } catch (\Throwable $e) {
        debug_log('Error getting uploaded images', ['error' => $e->getMessage()], 'error');
        return [];
    }
}

/**
 * 生成文件名
 */
function generate_filename($ext) {
    return \LitePic\Service\Image\PathService::generateFilename((string)$ext);
}

function normalize_image_identifier(string $identifier): string {
    return \LitePic\Service\Image\PathService::normalizeIdentifier($identifier);
}

function get_image_identifier_from_path(string $path): ?string {
    return \LitePic\Service\Image\PathService::identifierFromPath($path);
}

function get_image_display_name(string $identifier): string {
    return \LitePic\Service\Image\PathService::displayName($identifier);
}

function encode_image_identifier_for_url(string $identifier): string {
    return \LitePic\Service\Image\PathService::encodeForUrl($identifier);
}

function get_img_url($filename) {
    return \LitePic\Service\Image\ImageUrl::forIdentifier((string)$filename);
}

function get_thumbnail_filename(string $filename): string {
    return \LitePic\Service\Image\ImageUrl::thumbnailFilename($filename);
}

function get_thumbnail_path(string $filename): string {
    return \LitePic\Service\Image\ImageUrl::thumbnailPath($filename);
}

function get_thumbnail_url(string $filename): string {
    return \LitePic\Service\Image\ImageUrl::thumbnailUrl($filename);
}

function remote_storage_credentials_valid(): bool {
    return S3_BUCKET !== ''
        && S3_KEY !== ''
        && S3_SECRET !== ''
        && S3_ENDPOINT !== '';
}

function remote_storage_usage(): string {
    $usage = defined('REMOTE_STORAGE_USAGE') ? strtolower((string)REMOTE_STORAGE_USAGE) : 'backup';
    return in_array($usage, ['backup', 'storage'], true) ? $usage : 'backup';
}

function remote_storage_mode(): string {
    return remote_storage_credentials_valid() ? 'sync' : 'off';
}

function remote_storage_enabled(): bool {
    return remote_storage_credentials_valid();
}

function remote_storage_config_valid(): bool {
    return remote_storage_credentials_valid();
}

function remote_storage_public_delivery_enabled(): bool {
    return remote_storage_usage() === 'storage'
        && remote_storage_credentials_valid()
        && defined('S3_PUBLIC_BASE_URL')
        && trim((string)S3_PUBLIC_BASE_URL) !== '';
}

function remote_storage_public_url_for_object_key(string $object_key): ?string {
    $object_key = trim($object_key, '/');
    if (!remote_storage_public_delivery_enabled() || $object_key === '') {
        return null;
    }

    $base = rtrim((string)S3_PUBLIC_BASE_URL, '/');
    if ($base === '') {
        return null;
    }

    return $base . '/' . remote_storage_encoded_key($object_key);
}

function remote_storage_public_url_for_identifier(string $identifier): ?string {
    $identifier = normalize_image_identifier($identifier);
    if ($identifier === '') {
        return null;
    }

    return remote_storage_public_url_for_object_key(remote_storage_prefix() . $identifier);
}

function remote_storage_public_url_for_local_path(string $local_path): ?string {
    $object_key = remote_storage_object_key_from_local_path($local_path);
    if ($object_key === null) {
        return null;
    }

    return remote_storage_public_url_for_object_key($object_key);
}

function remote_storage_queue_delete_object(string $object_key, ?int $delay_seconds = null): void {
    $delay_seconds = $delay_seconds ?? (defined('REMOTE_STORAGE_DELETE_DELAY_SECONDS') ? (int)REMOTE_STORAGE_DELETE_DELAY_SECONDS : 86400);
    (new \LitePic\Repository\RemoteDeleteQueueRepository())->enqueue($object_key, $delay_seconds);
}

function remote_storage_process_delete_queue(int $limit = 25): array {
    $repo = new \LitePic\Repository\RemoteDeleteQueueRepository();
    $result = ['processed' => 0, 'deleted' => 0, 'failed' => 0, 'pending' => 0];

    $total = $repo->totalCount();
    if ($total === 0) {
        return $result;
    }
    if (!remote_storage_credentials_valid()) {
        $result['pending'] = $total;
        return $result;
    }

    foreach ($repo->dueNow($limit) as $item) {
        $result['processed']++;
        if (remote_storage_delete_object($item['object_key'])) {
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

function remote_storage_prefix(): string {
    $prefix = trim((string)S3_PATH_PREFIX, '/');
    return $prefix === '' ? '' : $prefix . '/';
}

function remote_storage_relative_path(string $local_path): ?string {
    $normalized = str_replace('\\', '/', $local_path);
    $base = rtrim(str_replace('\\', '/', UPLOAD_PATH_LOCAL), '/') . '/';
    if (!str_starts_with($normalized, $base)) {
        return null;
    }
    $relative = ltrim(substr($normalized, strlen($base)), '/');
    return $relative === '' ? null : $relative;
}

function remote_storage_object_key_from_local_path(string $local_path): ?string {
    $relative = remote_storage_relative_path($local_path);
    if ($relative === null) {
        return null;
    }
    return remote_storage_prefix() . $relative;
}

function remote_storage_object_key_for_filename(string $filename): ?string {
    return remote_storage_object_key_from_local_path(get_file_path($filename));
}

function remote_storage_object_key_for_thumbnail(string $filename): ?string {
    return remote_storage_object_key_from_local_path(get_thumbnail_path($filename));
}

function remote_storage_guess_content_type(string $path): string {
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
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

function remote_storage_endpoint_host(): string {
    $endpoint = trim((string)S3_ENDPOINT);
    if ($endpoint === '') {
        return '';
    }
    $parts = parse_url($endpoint);
    return (string)($parts['host'] ?? '');
}

function remote_storage_endpoint_base(): string {
    $endpoint = rtrim(trim((string)S3_ENDPOINT), '/');
    return $endpoint;
}

function remote_storage_encoded_key(string $object_key): string {
    $parts = array_map('rawurlencode', explode('/', ltrim($object_key, '/')));
    return implode('/', $parts);
}

function remote_storage_request(string $method, string $object_key, ?string $body = null, string $content_type = 'application/octet-stream'): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'status' => 0, 'error' => 'cURL 扩展未启用'];
    }
    if (!remote_storage_config_valid()) {
        return ['success' => false, 'status' => 0, 'error' => '远程存储配置不完整'];
    }

    $endpoint = remote_storage_endpoint_base();
    $host = remote_storage_endpoint_host();
    if ($endpoint === '' || $host === '') {
        return ['success' => false, 'status' => 0, 'error' => 'S3_ENDPOINT 无效'];
    }

    $bucket = trim((string)S3_BUCKET);
    $region = trim((string)S3_REGION);
    if ($region === '') {
        $region = 'auto';
    }

    $amz_date = gmdate('Ymd\THis\Z');
    $date_stamp = gmdate('Ymd');
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';

    $key_path = remote_storage_encoded_key($object_key);
    $canonical_uri = '/' . rawurlencode($bucket) . '/' . $key_path;
    $url = $endpoint . $canonical_uri;

    $payload = $body ?? '';
    $payload_hash = hash('sha256', $payload);
    $canonical_headers = "host:{$host}\n" . "x-amz-content-sha256:{$payload_hash}\n" . "x-amz-date:{$amz_date}\n";
    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
    $canonical_request = strtoupper($method) . "\n" .
        $canonical_uri . "\n\n" .
        $canonical_headers . "\n" .
        $signed_headers . "\n" .
        $payload_hash;
    $credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = $algorithm . "\n" .
        $amz_date . "\n" .
        $credential_scope . "\n" .
        hash('sha256', $canonical_request);

    $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . S3_SECRET, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $authorization = $algorithm .
        ' Credential=' . S3_KEY . '/' . $credential_scope .
        ', SignedHeaders=' . $signed_headers .
        ', Signature=' . $signature;

    $headers = [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payload_hash,
        'x-amz-date: ' . $amz_date,
        'Authorization: ' . $authorization,
    ];

    if (strtoupper($method) === 'PUT') {
        $headers[] = 'Content-Type: ' . $content_type;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (strtoupper($method) === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $resp_body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    if ($resp_body === false) {
        return ['success' => false, 'status' => $status, 'error' => $curl_error !== '' ? $curl_error : '远程请求失败'];
    }

    $success = $status >= 200 && $status < 300;
    return [
        'success' => $success,
        'status' => $status,
        'error' => $success ? null : ('HTTP ' . $status),
        'body' => is_string($resp_body) ? $resp_body : '',
    ];
}

function remote_storage_upload_local_file(string $local_path): array {
    if (!file_exists($local_path)) {
        return ['success' => false, 'error' => '本地文件不存在'];
    }
    $object_key = remote_storage_object_key_from_local_path($local_path);
    if ($object_key === null) {
        return ['success' => false, 'error' => '对象路径解析失败'];
    }
    $data = file_get_contents($local_path);
    if ($data === false) {
        return ['success' => false, 'error' => '读取本地文件失败'];
    }
    $mime = remote_storage_guess_content_type($local_path);
    $res = remote_storage_request('PUT', $object_key, $data, $mime);
    return [
        'success' => (bool)($res['success'] ?? false),
        'status' => (int)($res['status'] ?? 0),
        'error' => $res['error'] ?? null,
        'object_key' => $object_key,
    ];
}

function remote_storage_delete_object(string $object_key): bool {
    $res = remote_storage_request('DELETE', $object_key, '');
    return (bool)($res['success'] ?? false);
}

function remote_storage_sync_file_and_thumbnail(string $filename): array {
    remote_storage_process_delete_queue();

    $result = [
        'enabled' => remote_storage_enabled(),
        'mode' => remote_storage_mode(),
        'usage' => remote_storage_usage(),
        'configured' => remote_storage_config_valid(),
        'public_delivery' => remote_storage_public_delivery_enabled(),
        'uploaded' => [],
        'errors' => [],
    ];

    if (!remote_storage_enabled()) {
        return $result;
    }
    if (!remote_storage_config_valid()) {
        $result['errors'][] = '远程存储配置不完整';
        return $result;
    }

    $main_path = get_file_path($filename);
    if (file_exists($main_path)) {
        $main_upload = remote_storage_upload_local_file($main_path);
        if (!empty($main_upload['success'])) {
            $result['uploaded'][] = $main_upload['object_key'] ?? '';
        } else {
            $result['errors'][] = '主图上传失败: ' . (string)($main_upload['error'] ?? 'unknown');
        }
    } else {
        $result['errors'][] = '主图不存在';
    }

    $thumb_path = get_thumbnail_path($filename);
    if (file_exists($thumb_path)) {
        $thumb_upload = remote_storage_upload_local_file($thumb_path);
        if (!empty($thumb_upload['success'])) {
            $result['uploaded'][] = $thumb_upload['object_key'] ?? '';
        } else {
            $result['errors'][] = '缩略图上传失败: ' . (string)($thumb_upload['error'] ?? 'unknown');
        }
    }

    return $result;
}

function remote_storage_delete_file_and_thumbnail(string $filename): void {
    remote_storage_process_delete_queue();

    $keys = [];
    $file_key = remote_storage_object_key_for_filename($filename);
    if (is_string($file_key) && $file_key !== '') {
        $keys[] = $file_key;
    }
    $thumb_key = remote_storage_object_key_for_thumbnail($filename);
    if (is_string($thumb_key) && $thumb_key !== '') {
        $keys[] = $thumb_key;
    }
    $keys = array_values(array_unique($keys));
    foreach ($keys as $key) {
        remote_storage_queue_delete_object($key);
    }
}

function remote_storage_bucket_request(string $method, array $query = [], string $body = '', string $content_type = 'application/xml'): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'status' => 0, 'error' => 'cURL 扩展未启用'];
    }
    if (!remote_storage_credentials_valid()) {
        return ['success' => false, 'status' => 0, 'error' => '远程存储配置不完整'];
    }

    $endpoint = remote_storage_endpoint_base();
    $host = remote_storage_endpoint_host();
    if ($endpoint === '' || $host === '') {
        return ['success' => false, 'status' => 0, 'error' => 'S3_ENDPOINT 无效'];
    }

    $bucket = trim((string)S3_BUCKET);
    $region = trim((string)S3_REGION);
    if ($region === '') {
        $region = 'auto';
    }

    ksort($query);
    $query_pairs = [];
    foreach ($query as $k => $v) {
        $key = rawurlencode((string)$k);
        if ($v === null || $v === '') {
            $query_pairs[] = $key . '=';
        } else {
            $query_pairs[] = $key . '=' . rawurlencode((string)$v);
        }
    }
    $canonical_query = implode('&', $query_pairs);
    $canonical_uri = '/' . rawurlencode($bucket);
    $url = $endpoint . $canonical_uri . ($canonical_query !== '' ? '?' . $canonical_query : '');

    $amz_date = gmdate('Ymd\THis\Z');
    $date_stamp = gmdate('Ymd');
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $payload_hash = hash('sha256', $body);
    $canonical_headers = "host:{$host}\n" . "x-amz-content-sha256:{$payload_hash}\n" . "x-amz-date:{$amz_date}\n";
    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
    $canonical_request = strtoupper($method) . "\n" .
        $canonical_uri . "\n" .
        $canonical_query . "\n" .
        $canonical_headers . "\n" .
        $signed_headers . "\n" .
        $payload_hash;
    $credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = $algorithm . "\n" .
        $amz_date . "\n" .
        $credential_scope . "\n" .
        hash('sha256', $canonical_request);

    $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . S3_SECRET, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $authorization = $algorithm .
        ' Credential=' . S3_KEY . '/' . $credential_scope .
        ', SignedHeaders=' . $signed_headers .
        ', Signature=' . $signature;

    $headers = [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payload_hash,
        'x-amz-date: ' . $amz_date,
        'Authorization: ' . $authorization,
    ];
    if ($body !== '') {
        $headers[] = 'Content-Type: ' . $content_type;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp_body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    if ($resp_body === false) {
        return ['success' => false, 'status' => $status, 'error' => $curl_error !== '' ? $curl_error : '远程请求失败'];
    }
    $success = $status >= 200 && $status < 300;
    return [
        'success' => $success,
        'status' => $status,
        'error' => $success ? null : ('HTTP ' . $status),
        'body' => is_string($resp_body) ? $resp_body : '',
    ];
}

function remote_storage_list_objects(string $prefix = '', string $continuation_token = ''): array {
    $query = [
        'list-type' => '2',
        'max-keys' => '1000',
    ];
    if ($prefix !== '') {
        $query['prefix'] = $prefix;
    }
    if ($continuation_token !== '') {
        $query['continuation-token'] = $continuation_token;
    }

    $res = remote_storage_bucket_request('GET', $query, '');
    if (empty($res['success'])) {
        return [
            'success' => false,
            'error' => (string)($res['error'] ?? '列举远程对象失败'),
            'objects' => [],
            'is_truncated' => false,
            'next_token' => '',
        ];
    }

    $body = (string)($res['body'] ?? '');
    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        return [
            'success' => false,
            'error' => '解析远程对象列表失败',
            'objects' => [],
            'is_truncated' => false,
            'next_token' => '',
        ];
    }

    $objects = [];
    if (isset($xml->Contents)) {
        foreach ($xml->Contents as $item) {
            $key = (string)($item->Key ?? '');
            if ($key !== '') {
                $objects[] = $key;
            }
        }
    }

    $is_truncated = ((string)($xml->IsTruncated ?? 'false')) === 'true';
    $next_token = (string)($xml->NextContinuationToken ?? '');

    return [
        'success' => true,
        'error' => null,
        'objects' => $objects,
        'is_truncated' => $is_truncated,
        'next_token' => $next_token,
    ];
}

function remote_storage_delete_all_objects(): array {
    if (!remote_storage_credentials_valid()) {
        return ['success' => false, 'message' => '远程存储配置不完整', 'deleted' => 0, 'failed' => 0];
    }

    $prefix = remote_storage_prefix();
    $deleted = 0;
    $failed = 0;
    $token = '';
    $loops = 0;
    $max_loops = 500;

    do {
        $loops++;
        if ($loops > $max_loops) {
            return [
                'success' => false,
                'message' => '删除中止：分页次数过多，请稍后重试',
                'deleted' => $deleted,
                'failed' => $failed,
            ];
        }

        $list = remote_storage_list_objects($prefix, $token);
        if (empty($list['success'])) {
            return [
                'success' => false,
                'message' => '列举对象失败：' . (string)($list['error'] ?? 'unknown'),
                'deleted' => $deleted,
                'failed' => $failed,
            ];
        }

        $objects = is_array($list['objects'] ?? null) ? $list['objects'] : [];
        foreach ($objects as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (remote_storage_delete_object($key)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        $token = (string)($list['next_token'] ?? '');
        $has_more = !empty($list['is_truncated']) && $token !== '';
    } while ($has_more);

    $scope = $prefix !== '' ? ('前缀 ' . $prefix) : '整个 Bucket';
    $msg = sprintf('远程清理完成（%s）：成功 %d，失败 %d', $scope, $deleted, $failed);
    if ($failed === 0) {
        \LitePic\Core\Database::connection()->exec('DELETE FROM remote_delete_queue');
    }
    return [
        'success' => $failed === 0,
        'message' => $msg,
        'deleted' => $deleted,
        'failed' => $failed,
    ];
}

function remote_storage_test_connection(): array {
    if (!remote_storage_config_valid()) {
        return ['success' => false, 'message' => 'S3/R2 配置不完整'];
    }

    $probe_key = remote_storage_prefix() . '.healthcheck/litepic-' . gmdate('YmdHis') . '.txt';
    $probe_body = 'litepic-health-check';
    $put = remote_storage_request('PUT', $probe_key, $probe_body, 'text/plain');
    if (empty($put['success'])) {
        return ['success' => false, 'message' => '连接失败（上传测试失败）: ' . (string)($put['error'] ?? 'unknown')];
    }

    $delete_ok = remote_storage_delete_object($probe_key);
    if (!$delete_ok) {
        return ['success' => false, 'message' => '连接成功但清理测试文件失败，请检查删除权限'];
    }

    $queue = remote_storage_process_delete_queue();
    $suffix = ((int)($queue['deleted'] ?? 0) > 0)
        ? sprintf('；已处理到期远程删除 %d 个', (int)$queue['deleted'])
        : '';
    return ['success' => true, 'message' => '连接测试成功' . $suffix];
}

/**
 * 一键同步：将当前本地图库（原图+缩略图）全量同步到远端
 */
function remote_storage_sync_all_local_images(): array {
    if (!remote_storage_config_valid()) {
        return ['success' => false, 'message' => '远程存储配置不完整', 'total' => 0, 'synced' => 0, 'failed' => 0];
    }

    $images = get_uploaded_images();
    $total = count($images);
    $synced = 0;
    $failed = 0;
    $errors = [];

    foreach ($images as $filename) {
        $res = remote_storage_sync_file_and_thumbnail((string)$filename);
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
        'total' => $total,
        'synced' => $synced,
        'failed' => $failed,
        'errors' => $errors,
    ];
}

/**
 * 一键恢复：从远程对象存储下载前缀下全部对象到本地 uploads
 */
function remote_storage_restore_all_to_local(): array {
    if (!remote_storage_config_valid()) {
        return ['success' => false, 'message' => '远程存储配置不完整', 'total' => 0, 'restored' => 0, 'failed' => 0];
    }

    $prefix = remote_storage_prefix();
    $token = '';
    $loops = 0;
    $max_loops = 500;
    $total = 0;
    $restored = 0;
    $failed = 0;
    $errors = [];

    do {
        $loops++;
        if ($loops > $max_loops) {
            return [
                'success' => false,
                'message' => '恢复中止：分页次数过多，请稍后重试',
                'total' => $total,
                'restored' => $restored,
                'failed' => $failed,
                'errors' => $errors,
            ];
        }

        $list = remote_storage_list_objects($prefix, $token);
        if (empty($list['success'])) {
            return [
                'success' => false,
                'message' => '列举远程对象失败：' . (string)($list['error'] ?? 'unknown'),
                'total' => $total,
                'restored' => $restored,
                'failed' => $failed,
                'errors' => $errors,
            ];
        }

        $objects = is_array($list['objects'] ?? null) ? $list['objects'] : [];
        foreach ($objects as $object_key) {
            if (!is_string($object_key) || $object_key === '') {
                continue;
            }
            $total++;

            $relative = $object_key;
            if ($prefix !== '' && str_starts_with($object_key, $prefix)) {
                $relative = substr($object_key, strlen($prefix));
            }
            $relative = ltrim((string)$relative, '/');
            if ($relative === '') {
                continue;
            }

            $target_path = rtrim(UPLOAD_PATH_LOCAL, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $target_dir = dirname($target_path);
            if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
                $failed++;
                $errors[] = '创建目录失败: ' . $target_dir;
                continue;
            }

            $get = remote_storage_request('GET', $object_key, '');
            if (empty($get['success'])) {
                $failed++;
                $errors[] = '下载失败: ' . $object_key . ' (' . (string)($get['error'] ?? 'unknown') . ')';
                continue;
            }

            $body = (string)($get['body'] ?? '');
            if ($body === '') {
                $failed++;
                $errors[] = '下载为空: ' . $object_key;
                continue;
            }

            if (file_put_contents($target_path, $body, LOCK_EX) === false) {
                $failed++;
                $errors[] = '写入失败: ' . $target_path;
                continue;
            }

            // 对恢复下来的原图补缩略图与文件名映射
            $basename = basename($target_path);
            if (!preg_match('/\.thumb\./i', $basename) && can_generate_thumbnail($basename)) {
                create_thumbnail($basename, true);
                if (get_original_filename($basename) === null) {
                    save_original_filename($basename, $basename);
                }
            }

            $restored++;
        }

        $token = (string)($list['next_token'] ?? '');
        $has_more = !empty($list['is_truncated']) && $token !== '';
    } while ($has_more);

    $message = sprintf('远程恢复完成：总计 %d，成功 %d，失败 %d', $total, $restored, $failed);
    if (!empty($errors)) {
        $message .= '；示例错误：' . implode(' ; ', array_slice($errors, 0, 3));
    }

    return [
        'success' => $failed === 0,
        'message' => $message,
        'total' => $total,
        'restored' => $restored,
        'failed' => $failed,
        'errors' => $errors,
    ];
}

/**
 * 是否支持生成缩略图（仅栅格图）
 */
function can_generate_thumbnail(string $filename): bool {
    return \LitePic\Service\Image\ThumbnailService::canGenerate($filename);
}

function create_thumbnail(string $filename, bool $force = false): bool {
    return (new \LitePic\Service\Image\ThumbnailService())->create($filename, $force);
}

function generate_all_thumbnails(bool $force = true): array {
    return (new \LitePic\Service\Image\ThumbnailService())->generateAll($force);
}

function delete_thumbnail(string $filename): void {
    (new \LitePic\Service\Image\ThumbnailService())->delete($filename);
}

/**
 * 获取存储路径
 */
function get_storage_path() {
    return \LitePic\Service\Image\PathService::todaysStoragePath();
}

function get_storage_path_by_timestamp(int $timestamp): string {
    return \LitePic\Service\Image\PathService::storagePathByTimestamp($timestamp);
}

function collect_importable_images_from_dir(string $dir): array {
    return \LitePic\Service\Importer\Importer::collectImagesIn($dir);
}

function scan_import_is_absolute_path(string $path): bool {
    return \LitePic\Service\Importer\Importer::isAbsolutePath($path);
}

function resolve_scan_import_sources(string $source_input, array &$errors = []): array {
    return (new \LitePic\Service\Importer\Importer())->resolveSources($source_input, $errors);
}

function scan_import_relative_identifier(string $source_path, string $source_root): string {
    return \LitePic\Service\Importer\Importer::relativeIdentifier($source_path, $source_root);
}

function unique_import_target_identifier(string $relative_identifier): string {
    return \LitePic\Service\Importer\Importer::uniqueTargetIdentifier($relative_identifier);
}

function build_uploaded_hash_index(): array {
    return (new \LitePic\Service\Importer\Importer())->buildHashIndex();
}

function import_task_has_work(array $options): bool {
    return \LitePic\Repository\ImportQueueRepository::hasWork($options);
}

function import_task_enqueue(string $filename, array $options): bool {
    return (new \LitePic\Service\Importer\Importer())->enqueue($filename, $options);
}

function import_task_process_image(array $task): array {
    return (new \LitePic\Service\Importer\Importer())->processTask($task);
}

function import_task_process_queue(int $limit = 8): array {
    return (new \LitePic\Service\Importer\Importer())->processQueue($limit);
}

function import_task_queue_status(): array {
    return (new \LitePic\Service\Importer\Importer())->queueStatus();
}

function scan_and_import_uploads(array $options = []): array {
    return (new \LitePic\Service\Importer\Importer())->scanAndImport($options);
}

function get_file_path($filename) {
    return \LitePic\Service\Image\PathService::resolveFilePath((string)$filename);
}

/**
 * 根据真实文件内容推断格式标签
 */
function detect_real_image_format(string $filepath): string {
    return \LitePic\Service\Image\ImageFormat::detectLabel($filepath);
}

/**
 * 读取 SVG 的固定尺寸。优先 width/height，读不到时使用 viewBox。
 *
 * @return array{width:int,height:int}|null
 */
function get_svg_dimensions(string $filepath): ?array {
    return \LitePic\Service\Image\ImageFormat::svgDimensions($filepath);
}

/**
 * 获取图片信息
 */
function get_image_info($filename) {
    try {
        return (new \LitePic\Service\Image\ImageInfo())->get((string)$filename);
    } catch (\Throwable $e) {
        error_log('Error getting image info: ' . $e->getMessage());
        return null;
    }
}

/**
 * 对图库图片标识进行排序
 *
 * @param array<int, string> $images
 * @return array<int, string>
 */
function sort_uploaded_images(array $images, string $sort = 'date-desc'): array {
    if ($images === []) return [];

    // Pull the metadata we need for sorting in one round-trip.
    $repo = new \LitePic\Repository\ImageRepository();
    $byName = [];
    foreach ($images as $name) {
        $byName[(string)$name] = ['size' => 0, 'created_at' => 0];
    }
    foreach ($repo->listAll($sort) as $row) {
        $name = (string)$row['filename'];
        if (isset($byName[$name])) {
            $byName[$name] = ['size' => (int)$row['size'], 'created_at' => (int)$row['created_at']];
        }
    }

    $cmp = match ($sort) {
        'name-asc' => static fn ($a, $b) => strcmp((string)$a, (string)$b),
        'name-desc' => static fn ($a, $b) => strcmp((string)$b, (string)$a),
        'size-asc' => static fn ($a, $b) => $byName[(string)$a]['size'] <=> $byName[(string)$b]['size'],
        'size-desc' => static fn ($a, $b) => $byName[(string)$b]['size'] <=> $byName[(string)$a]['size'],
        'date-asc' => static fn ($a, $b) => $byName[(string)$a]['created_at'] <=> $byName[(string)$b]['created_at'],
        default => static fn ($a, $b) => $byName[(string)$b]['created_at'] <=> $byName[(string)$a]['created_at'],
    };
    usort($images, $cmp);
    return array_values($images);
}

/**
 * 将图片标识构造成 API 响应项
 *
 * @param array<int, string> $images
 * @return array<int, array<string, mixed>>
 */
function build_uploaded_image_api_items(array $images): array {
    $items = [];

    foreach ($images as $filename) {
        $info = get_image_info($filename);
        if ($info === null) {
            continue;
        }

        $items[] = [
            'filename' => (string)$info['filename'],
            'original_name' => (string)($info['original_name'] ?? $info['filename']),
            'url' => (string)$info['url'],
            'thumb_url' => (string)($info['thumb_url'] ?? $info['url']),
            'size' => (int)($info['size'] ?? 0),
            'size_text' => format_filesize((int)($info['size'] ?? 0)),
            'dimensions' => (string)($info['dimensions'] ?? ''),
            'width' => (int)($info['width'] ?? 0),
            'height' => (int)($info['height'] ?? 0),
            'format' => (string)($info['format'] ?? ''),
            'time' => (int)($info['time'] ?? 0),
            'time_text' => date('Y-m-d H:i', (int)($info['time'] ?? time())),
            'request_count' => (int)($info['request_count'] ?? 0),
        ];
    }

    return $items;
}

/**
 * 查询图库图片，支持搜索、排序、分页和全量导出
 *
 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
 */
function query_uploaded_images_for_api(
    int $page = 1,
    int $per_page = 20,
    string $query = '',
    string $sort = 'date-desc',
    bool $all = false
): array {
    $repo = new \LitePic\Repository\ImageRepository();

    if ($all) {
        $rows = $repo->listAll($sort, $query);
        $names = array_map(static fn ($r) => (string)$r['filename'], $rows);
        $total = count($names);
        return [
            'items' => build_uploaded_image_api_items($names),
            'pagination' => [
                'page' => 1,
                'per_page' => $total > 0 ? $total : 0,
                'total' => $total,
                'total_pages' => $total > 0 ? 1 : 0,
            ],
        ];
    }

    $page_data = $repo->paginate($page, $per_page, $sort, $query);
    $names = array_map(static fn ($r) => (string)$r['filename'], $page_data['items']);

    return [
        'items' => build_uploaded_image_api_items($names),
        'pagination' => [
            'page' => $page_data['page'],
            'per_page' => $page_data['per_page'],
            'total' => $page_data['total'],
            'total_pages' => $page_data['total_pages'],
        ],
    ];
}

/**
 * 保存原始文件名映射
 * @param string $system_name 系统生成的文件名
 * @param string $original_name 原始上传的文件名
 */
function save_original_filename($system_name, $original_name) {
    $normalized = normalize_image_identifier((string)$system_name);
    $filename = $normalized !== '' ? $normalized : basename((string)$system_name);
    if ($filename === '') {
        return;
    }

    $repo = new \LitePic\Repository\ImageRepository();
    if ($repo->exists($filename)) {
        $repo->update($filename, ['original_name' => (string)$original_name]);
        return;
    }

    $absolute = get_file_path($filename);
    $size = is_file($absolute) ? (int)@filesize($absolute) : 0;
    $mtime = is_file($absolute) ? (int)@filemtime($absolute) : time();

    $repo->insert([
        'filename' => $filename,
        'original_name' => (string)$original_name,
        'ext' => strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)),
        'size' => $size,
        'created_at' => $mtime,
    ]);
}

/**
 * 获取原始文件名
 * @param string $system_name 系统文件名
 * @return string|null 原始文件名，如果不存在返回 null
 */
function get_original_filename($system_name) {
    $normalized = normalize_image_identifier((string)$system_name);
    $filename = $normalized !== '' ? $normalized : basename((string)$system_name);
    if ($filename === '') {
        return null;
    }
    $row = (new \LitePic\Repository\ImageRepository())->find($filename);
    if ($row === null) return null;
    $original = $row['original_name'] ?? null;
    return ($original === null || $original === '') ? null : (string)$original;
}

/**
 * 调试日志
 */
function debug_log($message, $data = null, $type = 'info') {
    if (!DEBUG) return;
    
    $log_file = LOG_PATH . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    
    $log = sprintf(
        "[%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($type),
        $message
    );
    
    if ($data !== null) {
        $log .= "Data: " . print_r($data, true) . "\n";
    }
    
    error_log($log, 3, $log_file);
}

/**
 * 使用 ImageMagick 命令行对图片进行压缩（优先使用）
 * 在 Unix/Windows 上尝试查找 magick 或 convert 命令，若不可用或执行失败返回 false
 *
 * @param string $filepath
 * @param int $quality JPEG 质量 0-100（默认85）
 * @return bool
 */

function compress_with_imagemagick($filepath, $quality = 85) {
    return \LitePic\Service\Image\CompressionService::compressWithImagick((string)$filepath, (int)$quality);
}

function compress_with_tinypng($filepath) {
    return \LitePic\Service\Image\CompressionService::compressWithTinyPng((string)$filepath);
}

function compress_with_gd(string $filepath, int $quality = 85): bool {
    return \LitePic\Service\Image\CompressionService::compressWithGd($filepath, $quality);
}

function compress_image_by_mode(string $path, int $quality = 85): array {
    return (new \LitePic\Service\Image\CompressionService())->compress($path, $quality);
}

function auto_compress_uploaded_image(string $filename): array {
    return (new \LitePic\Service\Image\CompressionService())->autoCompressAfterUpload($filename);
}

function can_compress_extension(string $ext): bool {
    return \LitePic\Service\Image\ImageFormat::canCompress($ext);
}

function can_convert_webp_extension(string $ext): bool {
    return \LitePic\Service\Image\ImageFormat::canConvertWebp($ext);
}

function can_convert_avif_extension(string $ext): bool {
    return \LitePic\Service\Image\ImageFormat::canConvertAvif($ext);
}

function can_convert_preferred_extension(string $ext): bool {
    return \LitePic\Service\Image\ImageFormat::canConvertPreferred($ext);
}

function get_compression_mode(): string {
    return \LitePic\Service\Image\ImageFormat::compressionMode();
}

function get_compression_api_keys(): array {
    return (new \LitePic\Repository\CompressionKeyRepository())->all();
}

function add_compression_api_key(string $name, string $api_key): bool {
    return (new \LitePic\Repository\CompressionKeyRepository())->create($name, $api_key);
}

function set_compression_api_enabled(string $id, bool $enabled): bool {
    return (new \LitePic\Repository\CompressionKeyRepository())->setEnabled($id, $enabled);
}

function delete_compression_api_key(string $id): bool {
    return (new \LitePic\Repository\CompressionKeyRepository())->delete($id);
}

function get_active_compression_api_keys(): array {
    return (new \LitePic\Repository\CompressionKeyRepository())->active();
}

function record_compression_api_usage(string $id, bool $success, int $status_code = 0, ?string $error = null): void {
    (new \LitePic\Repository\CompressionKeyRepository())->recordUsage($id, $success, $status_code, $error);
}

function auto_convert_uploaded_to_webp(string $filename): array {
    return (new \LitePic\Service\Image\ConversionService())->autoConvertWebpAfterUpload($filename);
}

function auto_convert_uploaded_to_avif(string $filename): array {
    return (new \LitePic\Service\Image\ConversionService())->autoConvertAvifAfterUpload($filename);
}

function run_upload_post_process(string $filename): array {
    return (new \LitePic\Service\Image\ConversionService())->runUploadPostProcess($filename);
}

function save_image_with_type($image, $filepath, $mime) {
    \LitePic\Service\Image\ConversionService::saveImageWithType($image, (string)$filepath, (string)$mime);
}

function convert_to_webp($filepath) {
    return (new \LitePic\Service\Image\ConversionService())->toWebp((string)$filepath);
}

function convert_to_avif($filepath) {
    return (new \LitePic\Service\Image\ConversionService())->toAvif((string)$filepath);
}

function can_allocate_memory_for_image(string $filepath, string $mime): bool {
    return \LitePic\Service\Image\ConversionService::canAllocateMemoryForImage($filepath, $mime);
}

function create_image_resource($filepath, $mime) {
    return \LitePic\Service\Image\ConversionService::createImageResource((string)$filepath, (string)$mime);
}

function can_watermark_extension(string $ext): bool {
    return (new \LitePic\Service\Image\WatermarkService())->canWatermark($ext);
}

function apply_watermark_to_image(string $filename): array {
    return (new \LitePic\Service\Image\WatermarkService())->apply($filename);
}

function normalize_hotlink_host(string $host): string {
    return \LitePic\Service\Hotlink\HotlinkProtection::normalizeHost($host);
}

function hotlink_host_matches(string $referer_host, string $allowed_host): bool {
    return \LitePic\Service\Hotlink\HotlinkProtection::hostMatches($referer_host, $allowed_host);
}

function hotlink_allowed_hosts(): array {
    return (new \LitePic\Service\Hotlink\HotlinkProtection())->allowedHosts();
}

function is_hotlink_request_allowed(): bool {
    return (new \LitePic\Service\Hotlink\HotlinkProtection())->isRequestAllowed();
}

function serve_protected_image(string $identifier): void {
    (new \LitePic\Service\Hotlink\HotlinkProtection())->serveProtected($identifier);
}

function get_access_log_stats(bool $force = false): array {
    return (new \LitePic\Service\Stats\AccessLogStats())->get($force);
}

function get_image_request_count(string $filename, ?array $access_stats = null): int {
    return (new \LitePic\Service\Stats\AccessLogStats())->imageRequestCount($filename, $access_stats);
}

function reArrayFiles($files) {
    return \LitePic\Service\Upload\UploadService::normaliseFilesArray(['name' => $files['name']] + $files);
}

function ini_size_to_bytes($value): int {
    return \LitePic\Service\Upload\UploadService::iniSizeToBytes($value);
}

function get_php_upload_limit_bytes(): int {
    return \LitePic\Service\Upload\UploadService::phpUploadLimitBytes();
}

function get_effective_upload_max_bytes(): int {
    return (new \LitePic\Service\Upload\UploadService())->maxBytes();
}

function get_upload_error_message(int $error_code, string $filename = ''): string {
    return (new \LitePic\Service\Upload\UploadService())->uploadErrorMessage($error_code, $filename);
}

function normalize_uploaded_files(array $raw_files): array {
    return \LitePic\Service\Upload\UploadService::normaliseFilesArray($raw_files);
}

function handle_uploaded_files(array $files): array {
    return (new \LitePic\Service\Upload\UploadService())->handle($files);
}

function validate_upload_mime(string $tmp_name, string $ext): bool {
    return (new \LitePic\Service\Upload\UploadService())->validateMime($tmp_name, $ext);
}

function format_filesize($bytes) {
    $size = (float)$bytes;
    if (!is_finite($size) || $size <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $base = 1024;
    $i = (int)floor(log($size, $base));
    $i = max(0, min($i, count($units) - 1));

    $value = $size / pow($base, $i);
    // 整数不显示小数，有小数保留一位
    $formatted = number_format($value, 1);
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted . ' ' . $units[$i];
}

/**
 * 解析 /etc/os-release，返回 ['id' => 'debian', 'name' => 'Debian',
 * 'version' => '13', 'pretty' => 'Debian 13']。读不到时返回基于 PHP_OS 的兜底。
 *
 * 用 @file_get_contents 而非 is_readable + file_get_contents，避免在
 * open_basedir 受限时（is_readable 不被 @ 抑制）leak Warning。
 */
function get_distro_info(): array {
    return (new \LitePic\Service\Stats\ServerInfo())->distro();
}

function get_server_uptime_seconds(): ?int {
    return (new \LitePic\Service\Stats\ServerInfo())->uptimeSeconds();
}

function detect_web_server_software(?string $software = null): array {
    return (new \LitePic\Service\Stats\ServerInfo())->webServer($software);
}

function get_server_runtime_metrics(): array {
    return (new \LitePic\Service\Stats\ServerInfo())->runtimeMetrics();
}

function get_footer_stats_cached(int $ttl = 45): array {
    return (new \LitePic\Service\Stats\FooterStats())->snapshot($ttl);
}

function get_image_count() {
    return (new \LitePic\Service\Stats\FooterStats())->imageCount();
}

function get_total_size() {
    return (new \LitePic\Service\Stats\FooterStats())->totalSize();
}

/**
 * 验证管理员权限
 */
function is_admin(): bool {
    return (new \LitePic\Service\Auth\AuthService())->isAdmin();
}

function get_request_header(string $name): ?string {
    return \LitePic\Service\Auth\AuthService::requestHeader($name);
}

function get_request_api_key(): ?string {
    return \LitePic\Service\Auth\AuthService::requestApiKey();
}

function has_upload_api_access(): bool {
    return (new \LitePic\Service\Auth\AuthService())->hasUploadApiAccess();
}

function is_api_request_authorized(): bool {
    return (new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized();
}

/**
 * 读取管理型 API Token 列表
 */
function get_managed_api_tokens(): array {
    $rows = (new \LitePic\Repository\ApiTokenRepository())->all();
    return array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'token_hash' => '',
            'created_at' => $row['created_at'] > 0 ? date('c', $row['created_at']) : '-',
            'last_used_at' => $row['last_used_at'] !== null ? date('c', $row['last_used_at']) : null,
            'revoked_at' => null,
        ];
    }, $rows);
}

function create_managed_api_token(string $name = 'token'): ?string {
    try {
        return (new \LitePic\Repository\ApiTokenRepository())->create($name);
    } catch (\Throwable $e) {
        error_log('create_managed_api_token failed: ' . $e->getMessage());
        return null;
    }
}

function revoke_managed_api_token(string $token_id): bool {
    return (new \LitePic\Repository\ApiTokenRepository())->revoke($token_id);
}

function verify_managed_api_token(string $plain_token): bool {
    return (new \LitePic\Repository\ApiTokenRepository())->verify($plain_token);
}

/**
 * ============================================
 * 安全加固函数（CSRF、速率限制、MIME校验）
 * ============================================
 */

/**
 * 初始化 Session（用于 CSRF Token 和登录速率限制）
 */
function session_init_safe(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // 如果 headers 已发送，使用输出缓冲避免崩溃（同时记录日志）
    if (headers_sent($file, $line)) {
        error_log("[LitePic] Session start delayed: headers already sent at {$file}:{$line}");
        if (!ob_get_level()) {
            ob_start();
        }
    }
    session_start();
}

/**
 * 获取或生成 CSRF Token
 */
function csrf_token_get(): string {
    session_init_safe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token
 */
function csrf_token_verify(?string $token): bool {
    session_init_safe();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals((string)$_SESSION['csrf_token'], $token);
}

/**
 * 输出 CSRF Token 隐藏字段（用于表单）
 */
function csrf_token_input(): string {
    $token = csrf_token_get();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * 检查登录速率限制
 * 返回 true 表示允许登录，false 表示已超限
 */
function check_login_rate_limit(): bool {
    return (new \LitePic\Repository\LoginAttemptRepository())
        ->isAllowed((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function record_login_failure(): void {
    (new \LitePic\Repository\LoginAttemptRepository())
        ->recordFailure((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}


/**
 * 生产环境安全的错误信息输出
 * 调试模式下返回原始信息，生产环境返回通用提示
 */
function safe_error_message(Throwable $e): string {
    if (DEBUG) {
        return $e->getMessage();
    }
    return '服务器内部错误，请稍后重试';
}

/**
 * 处理错误响应
 */
function error_response(string $message, int $errorCode = 400): void {
    http_response_code($errorCode);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

/**
 * 处理成功响应
 */
function success_response(array $data): void {
    http_response_code(200);
    // 确保所有成功响应都包含 status 字段
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}

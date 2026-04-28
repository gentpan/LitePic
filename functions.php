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
        if (STORAGE_TYPE === 'date') {
            $files = [];
            $years = glob(UPLOAD_PATH_LOCAL . '*', GLOB_ONLYDIR);
            
            foreach ($years as $year) {
                $yearName = basename($year);
                if ($yearName === '.thumbs' || str_starts_with($yearName, '.')) {
                    continue;
                }
                // 仅允许标准 YYYY 目录，避免 upload/backup 等目录被自动识别进图库
                if (!preg_match('/^\d{4}$/', $yearName)) {
                    continue;
                }
                $months = glob($year . '/*', GLOB_ONLYDIR);
                foreach ($months as $month) {
                    $monthName = basename($month);
                    if (str_starts_with($monthName, '.')) {
                        continue;
                    }
                    // 仅允许标准 MM 目录
                    if (!preg_match('/^(0[1-9]|1[0-2])$/', $monthName)) {
                        continue;
                    }
                    $pattern = $month . '/*.{' . implode(',', ALLOWED_TYPES) . '}';
                    $matched = glob($pattern, GLOB_BRACE);
                    if (is_array($matched)) {
                        $files = array_merge($files, $matched);
                    }
                }
            }
        } else {
            $files = glob(UPLOAD_PATH_LOCAL . '*.{' . implode(',', ALLOWED_TYPES) . '}', GLOB_BRACE);
        }
        
        if ($files === false) {
            debug_log("Failed to get files", ['path' => UPLOAD_PATH_LOCAL], 'error');
            return [];
        }
        
        $files = array_values(array_filter($files, static function (string $path): bool {
            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, '/.thumbs/')) {
                return false;
            }
            $base = basename($normalized);
            return !preg_match('/\\.thumb\\.[a-z0-9]+$/i', $base);
        }));

        $images = array_map(static function (string $path): string {
            $relative = get_image_identifier_from_path($path);
            return $relative ?? get_image_display_name($path);
        }, $files);
        $images = array_values(array_unique($images));

        // 若同名 WebP 存在，隐藏对应的原图（jpg/jpeg/png/gif），避免图库重复显示
        $image_set = array_fill_keys($images, true);
        $images = array_values(array_filter($images, static function (string $name) use ($image_set): bool {
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                return true;
            }
            $dir = (string)pathinfo($name, PATHINFO_DIRNAME);
            $stem = (string)pathinfo($name, PATHINFO_FILENAME);
            $webp_name = ($dir !== '.' ? $dir . '/' : '') . $stem . '.webp';
            return !isset($image_set[$webp_name]);
        }));

        rsort($images); // 最新在前
        return $images;
    } catch (Exception $e) {
        debug_log("Error getting uploaded images", ['error' => $e->getMessage()], 'error');
        return [];
    }
}

/**
 * 生成文件名
 */
function generate_filename($ext) {
    $filename = uniqid() . '.' . strtolower($ext);
    while (file_exists(get_file_path($filename))) {
        $filename = uniqid() . '_' . rand(100, 999) . '.' . strtolower($ext);
    }
    return $filename;
}

/**
 * 规范化图库文件标识（日期存储时使用相对路径，如 2026/03/demo.png）
 */
function normalize_image_identifier(string $identifier): string {
    $normalized = trim(str_replace('\\', '/', $identifier));
    $normalized = ltrim($normalized, '/');
    if ($normalized === '') {
        return '';
    }

    $parts = explode('/', $normalized);
    $safe_parts = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            return '';
        }
        $safe_parts[] = $part;
    }

    return implode('/', $safe_parts);
}

/**
 * 根据本地路径生成图库文件标识
 */
function get_image_identifier_from_path(string $path): ?string {
    $normalized = str_replace('\\', '/', $path);
    $base = rtrim(str_replace('\\', '/', UPLOAD_PATH_LOCAL), '/') . '/';
    if (!str_starts_with($normalized, $base)) {
        return null;
    }

    $relative = normalize_image_identifier(substr($normalized, strlen($base)));
    return $relative !== '' ? $relative : null;
}

/**
 * 获取文件显示名
 */
function get_image_display_name(string $identifier): string {
    $normalized = normalize_image_identifier($identifier);
    if ($normalized === '') {
        return basename($identifier);
    }

    return basename($normalized);
}

/**
 * 生成图片访问 URL
 */
function get_img_url($filename) {
    $identifier = normalize_image_identifier((string)$filename);
    if ($identifier !== '') {
        return SITE_URL . UPLOAD_PATH_WEB . $identifier;
    }

    if (STORAGE_TYPE === 'date') {
        $path = get_file_path($filename);
        $relative = get_image_identifier_from_path($path);
        if ($relative !== null) {
            return SITE_URL . UPLOAD_PATH_WEB . $relative;
        }
    }

    return SITE_URL . UPLOAD_PATH_WEB . get_image_display_name((string)$filename);
}

/**
 * 生成缩略图文件名
 */
function get_thumbnail_filename(string $filename): string {
    $name = pathinfo(get_image_display_name($filename), PATHINFO_FILENAME);
    return $name . '.thumb.jpg';
}

/**
 * 获取缩略图本地路径（与原图同年月目录，放在 .thumbs 下）
 */
function get_thumbnail_path(string $filename): string {
    $thumb_filename = get_thumbnail_filename($filename);
    $identifier = normalize_image_identifier($filename);

    if (STORAGE_TYPE === 'date') {
        $relative = $identifier;
        if ($relative === '') {
            $source_path = get_file_path($filename);
            $relative = (string)get_image_identifier_from_path($source_path);
        }
        $parts = explode('/', trim($relative, '/'));

        $year = $parts[0] ?? date('Y');
        $month = $parts[1] ?? date('m');

        return UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $thumb_filename;
    }

    return UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $thumb_filename;
}

/**
 * 获取缩略图 URL
 */
function get_thumbnail_url(string $filename): string {
    $thumb_filename = get_thumbnail_filename($filename);
    $identifier = normalize_image_identifier($filename);

    if (STORAGE_TYPE === 'date') {
        $relative = $identifier;
        if ($relative === '') {
            $source_path = get_file_path($filename);
            $relative = (string)get_image_identifier_from_path($source_path);
        }
        $parts = explode('/', trim($relative, '/'));

        $year = $parts[0] ?? date('Y');
        $month = $parts[1] ?? date('m');

        return SITE_URL . UPLOAD_PATH_WEB . '.thumbs/' . $year . '/' . $month . '/' . $thumb_filename;
    }

    return SITE_URL . UPLOAD_PATH_WEB . '.thumbs/' . $thumb_filename;
}

function remote_storage_mode(): string {
    $mode = strtolower(trim((string)REMOTE_STORAGE_MODE));
    return in_array($mode, ['off', 'sync', 'backup'], true) ? $mode : 'off';
}

function remote_storage_enabled(): bool {
    return remote_storage_mode() !== 'off';
}

function remote_storage_credentials_valid(): bool {
    return S3_BUCKET !== ''
        && S3_KEY !== ''
        && S3_SECRET !== ''
        && S3_ENDPOINT !== '';
}

function remote_storage_config_valid(): bool {
    return remote_storage_enabled() && remote_storage_credentials_valid();
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
    $result = [
        'enabled' => remote_storage_enabled(),
        'mode' => remote_storage_mode(),
        'configured' => remote_storage_config_valid(),
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
    // 删除动作优先保证本地与远端一致：只要远端凭证有效即尝试远端删除
    if (!remote_storage_credentials_valid()) {
        return;
    }
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
        remote_storage_delete_object($key);
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
    return [
        'success' => $failed === 0,
        'message' => $msg,
        'deleted' => $deleted,
        'failed' => $failed,
    ];
}

function remote_storage_test_connection(): array {
    if (!remote_storage_enabled()) {
        return ['success' => false, 'message' => '当前模式为 off，未启用远程存储'];
    }
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

    return ['success' => true, 'message' => '连接测试成功'];
}

/**
 * 一键同步：将当前本地图库（原图+缩略图）全量同步到远端
 */
function remote_storage_sync_all_local_images(): array {
    if (!remote_storage_enabled()) {
        return ['success' => false, 'message' => '远程存储未启用', 'total' => 0, 'synced' => 0, 'failed' => 0];
    }
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
    if (!remote_storage_enabled()) {
        return ['success' => false, 'message' => '远程存储未启用', 'total' => 0, 'restored' => 0, 'failed' => 0];
    }
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
            if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
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
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
}

/**
 * 生成或更新缩略图
 */
function create_thumbnail(string $filename, bool $force = false): bool {
    if (!can_generate_thumbnail($filename)) {
        return false;
    }

    $source_path = get_file_path($filename);
    if (!file_exists($source_path)) {
        return false;
    }

    $thumb_path = get_thumbnail_path($filename);
    if (!$force && file_exists($thumb_path)) {
        return true;
    }

    $thumb_dir = dirname($thumb_path);
    if (!is_dir($thumb_dir) && !mkdir($thumb_dir, 0777, true)) {
        return false;
    }

    $image_info = @getimagesize($source_path);
    if ($image_info === false) {
        return false;
    }

    $source_width = (int)($image_info[0] ?? 0);
    $source_height = (int)($image_info[1] ?? 0);
    if ($source_width <= 0 || $source_height <= 0) {
        return false;
    }

    $scale = min(
        THUMBNAIL_MAX_WIDTH / $source_width,
        THUMBNAIL_MAX_HEIGHT / $source_height,
        1
    );

    $target_width = max(1, (int)floor($source_width * $scale));
    $target_height = max(1, (int)floor($source_height * $scale));

    $source = create_image_resource($source_path, (string)$image_info['mime']);
    if (!$source) {
        return false;
    }

    $thumb = imagecreatetruecolor($target_width, $target_height);
    if (!$thumb) {
        return false;
    }

    // 统一输出 JPG，透明图层用白底
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefilledrectangle($thumb, 0, 0, $target_width, $target_height, $white);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $target_width, $target_height, $source_width, $source_height);

    $saved = imagejpeg($thumb, $thumb_path, THUMBNAIL_QUALITY);

    return $saved;
}

/**
 * 一键生成全部缩略图
 */
function generate_all_thumbnails(bool $force = true): array {
    $images = get_uploaded_images();
    $report = [
        'total' => count($images),
        'created' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    foreach ($images as $filename) {
        $name = (string)$filename;
        if (!can_generate_thumbnail($name)) {
            $report['skipped']++;
            continue;
        }
        $ok = create_thumbnail($name, $force);
        if ($ok) {
            $report['created']++;
        } else {
            $report['failed']++;
        }
    }

    return $report;
}

/**
 * 删除缩略图
 */
function delete_thumbnail(string $filename): void {
    $identifier = normalize_image_identifier($filename);
    $safe_name = $identifier !== '' ? $identifier : basename($filename);
    $thumb_name = get_thumbnail_filename($safe_name);
    $candidates = [
        get_thumbnail_path($safe_name),
        UPLOAD_PATH_LOCAL . '.thumbs' . DIRECTORY_SEPARATOR . $thumb_name,
        UPLOAD_PATH_LOCAL . 'thumbs' . DIRECTORY_SEPARATOR . $thumb_name,
    ];

    $source_path = get_file_path($safe_name);
    $source_dir = dirname($source_path);
    $base_name = pathinfo($safe_name, PATHINFO_FILENAME);
    $ext = strtolower((string)pathinfo($safe_name, PATHINFO_EXTENSION));

    $candidates[] = $source_dir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $thumb_name;
    $candidates[] = $source_dir . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . $thumb_name;
    $candidates[] = $source_dir . DIRECTORY_SEPARATOR . $base_name . '.thumb.jpg';
    if ($ext !== '') {
        $candidates[] = $source_dir . DIRECTORY_SEPARATOR . $base_name . '.thumb.' . $ext;
    }
    foreach (array_unique($candidates) as $thumb_path) {
        if (is_string($thumb_path) && $thumb_path !== '' && file_exists($thumb_path)) {
            @unlink($thumb_path);
        }
    }
}

/**
 * 获取存储路径
 */
function get_storage_path() {
    if (STORAGE_TYPE === 'date') {
        $year = date('Y');
        $month = date('m');
        $path = UPLOAD_PATH_LOCAL . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
        
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        
        return $path;
    }
    return UPLOAD_PATH_LOCAL;
}

/**
 * 根据时间戳获取存储目录
 */
function get_storage_path_by_timestamp(int $timestamp): string {
    if ($timestamp <= 0) {
        return get_storage_path();
    }

    if (STORAGE_TYPE === 'date') {
        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);
        $path = UPLOAD_PATH_LOCAL . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }

    return UPLOAD_PATH_LOCAL;
}

/**
 * 扫描目录下所有可导入图片
 */
function collect_importable_images_from_dir(string $dir): array {
    $images = [];
    if (!is_dir($dir)) {
        return $images;
    }

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
    } catch (Throwable $e) {
        return $images;
    }

    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $path = (string)$item->getPathname();
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '/.thumbs/')) {
            continue;
        }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_TYPES, true)) {
            continue;
        }
        $images[] = $path;
    }

    return $images;
}

/**
 * 通过 hash 建立当前图库索引，避免重复导入
 */
function build_uploaded_hash_index(): array {
    $index = [];
    $images = get_uploaded_images();
    foreach ($images as $filename) {
        $path = get_file_path((string)$filename);
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $hash = @sha1_file($path);
        if (!is_string($hash) || $hash === '') {
            continue;
        }
        $index[$hash] = (string)$filename;
    }
    return $index;
}

/**
 * 扫描并导入旧目录图片到当前图床
 * 说明：
 * - 源目录优先扫描 ./upload（旧目录），可选再扫描 ./uploads 根目录内零散文件
 * - 导入时按文件时间放入 YYYY/MM 目录
 * - 自动生成缩略图与文件名映射
 */
function scan_and_import_uploads(array $options = []): array {
    $create_thumb = !array_key_exists('create_thumbnail', $options) || (bool)$options['create_thumbnail'];
    $auto_webp = !empty($options['auto_webp']);
    $auto_avif = !empty($options['auto_avif']);
    // 转换与压缩互斥，避免流程冲突
    $auto_compress = !empty($options['auto_compress']) && !$auto_webp && !$auto_avif;

    $base_dir = __DIR__;
    $legacy_dir = $base_dir . DIRECTORY_SEPARATOR . 'upload';
    $current_root = rtrim(UPLOAD_PATH_LOCAL, DIRECTORY_SEPARATOR);

    $sources = [];
    if (is_dir($legacy_dir)) {
        $sources[] = $legacy_dir;
    }

    // 若当前 uploads 根目录存在未纳入日期结构的历史文件，也一并补录
    if (is_dir($current_root)) {
        $sources[] = $current_root;
    }

    $report = [
        'scanned' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'failed' => 0,
        'thumb_created' => 0,
        'compressed' => 0,
        'webp_created' => 0,
        'avif_created' => 0,
        'skip_compress' => 0,
        'skip_webp' => 0,
        'skip_avif' => 0,
        'errors' => [],
    ];

    if (empty($sources)) {
        $report['errors'][] = '未找到可扫描目录（upload / uploads）';
        return $report;
    }

    $existing_hashes = build_uploaded_hash_index();
    $current_root_normalized = str_replace('\\', '/', $current_root) . '/';
    $legacy_root_normalized = str_replace('\\', '/', $legacy_dir) . '/';

    foreach ($sources as $source_dir) {
        $files = collect_importable_images_from_dir($source_dir);
        foreach ($files as $source_path) {
            $report['scanned']++;

            $normalized = str_replace('\\', '/', $source_path);
            $is_in_current_uploads = str_starts_with($normalized, $current_root_normalized);
            $is_in_legacy_upload = str_starts_with($normalized, $legacy_root_normalized);

            // 跳过当前系统自动生成缩略图文件
            $base = basename($normalized);
            if (preg_match('/\\.thumb\\.[a-z0-9]+$/i', $base)) {
                continue;
            }

            $ext = strtolower((string)pathinfo($source_path, PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_TYPES, true)) {
                continue;
            }

            $hash = @sha1_file($source_path);
            if (is_string($hash) && $hash !== '' && isset($existing_hashes[$hash])) {
                $report['duplicates']++;
                continue;
            }

            // 如果文件已经在当前 uploads 目录，直接补映射与后处理
            $final_filename = '';
            if ($is_in_current_uploads && !$is_in_legacy_upload) {
                $identifier = get_image_identifier_from_path($source_path);
                if ($identifier === null) {
                    $report['failed']++;
                    $report['errors'][] = '路径解析失败: ' . $source_path;
                    continue;
                }
                save_original_filename($identifier, basename($source_path));
                $final_filename = $identifier;
            } else {
                $mtime = @filemtime($source_path);
                $target_dir = get_storage_path_by_timestamp($mtime !== false ? (int)$mtime : time());
                if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true)) {
                    $report['failed']++;
                    $report['errors'][] = '创建目录失败: ' . $target_dir;
                    continue;
                }

                $new_filename = generate_filename($ext);
                $target_path = $target_dir . $new_filename;

                if (!@copy($source_path, $target_path)) {
                    $report['failed']++;
                    $report['errors'][] = '复制失败: ' . $source_path;
                    continue;
                }

                if ($mtime !== false) {
                    @touch($target_path, (int)$mtime);
                }

                $final_filename = get_image_identifier_from_path($target_path) ?? $new_filename;
                save_original_filename($final_filename, basename($source_path));
            }

            // 导入后可选处理：WebP / AVIF / 压缩 / 缩略图（不支持格式自动跳过）
            if ($auto_webp) {
                if (can_convert_webp_extension($ext)) {
                    $origin_path = get_file_path($final_filename);
                    if (convert_to_webp($origin_path)) {
                        $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $origin_path);
                        if (is_string($webp_path) && is_file($webp_path)) {
                            $webp_filename = get_image_identifier_from_path($webp_path) ?? basename($webp_path);
                            if ($webp_filename !== $final_filename) {
                                if (!KEEP_ORIGINAL_AFTER_PROCESS) {
                                    @unlink($origin_path);
                                    delete_thumbnail($final_filename);
                                }
                                $final_filename = $webp_filename;
                            }
                            $report['webp_created']++;
                        } else {
                            $report['skip_webp']++;
                        }
                    } else {
                        $report['skip_webp']++;
                    }
                } else {
                    $report['skip_webp']++;
                }
            } elseif ($auto_avif) {
                if (can_convert_avif_extension($ext)) {
                    $origin_path = get_file_path($final_filename);
                    if (convert_to_avif($origin_path)) {
                        $avif_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $origin_path);
                        if (is_string($avif_path) && is_file($avif_path)) {
                            $avif_filename = get_image_identifier_from_path($avif_path) ?? basename($avif_path);
                            if ($avif_filename !== $final_filename) {
                                if (!KEEP_ORIGINAL_AFTER_PROCESS) {
                                    @unlink($origin_path);
                                    delete_thumbnail($final_filename);
                                }
                                $final_filename = $avif_filename;
                            }
                            $report['avif_created']++;
                        } else {
                            $report['skip_avif']++;
                        }
                    } else {
                        $report['skip_avif']++;
                    }
                } else {
                    $report['skip_avif']++;
                }
            } elseif ($auto_compress) {
                $final_ext = strtolower((string)pathinfo($final_filename, PATHINFO_EXTENSION));
                if (can_compress_extension($final_ext)) {
                    $final_path = get_file_path($final_filename);
                    $compress_result = compress_image_by_mode($final_path, 85);
                    if (!empty($compress_result['success'])) {
                        $report['compressed']++;
                    } else {
                        $report['skip_compress']++;
                    }
                } else {
                    $report['skip_compress']++;
                }
            }

            if ($create_thumb && can_generate_thumbnail($final_filename) && create_thumbnail($final_filename, true)) {
                $report['thumb_created']++;
            }
            if (remote_storage_enabled() && remote_storage_config_valid()) {
                remote_storage_sync_file_and_thumbnail($final_filename);
            }

            $final_hash = @sha1_file(get_file_path($final_filename));
            if (is_string($final_hash) && $final_hash !== '') {
                $existing_hashes[$final_hash] = $final_filename;
            } elseif (is_string($hash) && $hash !== '') {
                $existing_hashes[$hash] = $final_filename;
            }
            $report['imported']++;
        }
    }

    return $report;
}

/**
 * 获取文件完整路径
 */
function get_file_path($filename) {
    $raw_identifier = (string)$filename;
    $identifier = normalize_image_identifier($raw_identifier);
    if ($identifier !== '') {
        return UPLOAD_PATH_LOCAL . str_replace('/', DIRECTORY_SEPARATOR, $identifier);
    }

    // 兼容旧逻辑：仅传 basename 时在日期目录中查找首个匹配项
    $filename = basename($raw_identifier);
    
    if (STORAGE_TYPE === 'date') {
        // 尝试仅在标准日期目录中查找文件
        $files = glob(UPLOAD_PATH_LOCAL . '[0-9][0-9][0-9][0-9]/[0-1][0-9]/' . $filename);
        if (!empty($files)) {
            $files = array_values(array_filter($files, static function (string $path): bool {
                $normalized = str_replace('\\', '/', $path);
                if (str_contains($normalized, '/.thumbs/')) {
                    return false;
                }
                // 二次校验月份目录合法，避免 00/13 等路径误匹配
                if (!preg_match('#/(\d{4})/(0[1-9]|1[0-2])/#', $normalized)) {
                    return false;
                }
                return true;
            }));
            if (!empty($files)) {
                return $files[0];
            }
        }
    }
    
    // 如果找不到或不是日期存储，返回默认路径
    $default_path = UPLOAD_PATH_LOCAL . $filename;
    
    // 记录调试信息
    if (!file_exists($default_path)) {
        error_log("File not found: {$default_path}");
        debug_log("File lookup failed", [
            'filename' => $filename,
            'storage_type' => STORAGE_TYPE,
            'path' => $default_path
        ], 'warning');
    }
    
    return $default_path;
}

/**
 * 根据真实文件内容推断格式标签
 */
function detect_real_image_format(string $filepath): string {
    if (!is_file($filepath)) {
        return strtoupper((string)pathinfo($filepath, PATHINFO_EXTENSION));
    }

    $mime = '';
    $image_info = @getimagesize($filepath);
    if (is_array($image_info) && isset($image_info['mime'])) {
        $mime = strtolower((string)$image_info['mime']);
    } elseif (function_exists('mime_content_type')) {
        $mime = strtolower((string)@mime_content_type($filepath));
    }

    $map = [
        'image/jpeg' => 'JPG',
        'image/jpg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/avif' => 'AVIF',
        'image/gif' => 'GIF',
        'image/svg+xml' => 'SVG',
        'image/x-icon' => 'ICO',
        'image/vnd.microsoft.icon' => 'ICO',
        'image/bmp' => 'BMP',
        'image/tiff' => 'TIFF',
    ];

    if ($mime !== '' && isset($map[$mime])) {
        return $map[$mime];
    }

    $ext = strtoupper((string)pathinfo($filepath, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'FILE';
}

/**
 * 获取图片信息
 */
function get_image_info($filename) {
    try {
        $identifier = normalize_image_identifier((string)$filename);
        if ($identifier === '') {
            $identifier = get_image_display_name((string)$filename);
        }
        $filepath = get_file_path($filename);
        
        if (!file_exists($filepath)) {
            debug_log("File not found", [
                'filename' => $filename,
                'filepath' => $filepath
            ], 'warning');
            return null;
        }

        // 获取基本信息
        $filesize = filesize($filepath);
        $upload_time = filemtime($filepath);
        $dimensions = @getimagesize($filepath);
        $format = detect_real_image_format($filepath);
        
        // 获取原始文件名
        $original_name = get_original_filename($identifier);
        if (!$original_name) {
            $original_name = get_image_display_name($identifier); // 如果没有映射就使用当前文件名
        }
        
        $thumb_url = get_img_url($identifier);
        if (can_generate_thumbnail($identifier)) {
            if (create_thumbnail((string)$identifier)) {
                $thumb_url = get_thumbnail_url((string)$identifier);
            }
        }

        return [
            'filename' => $identifier,
            'original_name' => $original_name, // 确保这个字段存在
            'size' => $filesize,
            'filesize' => format_filesize($filesize),
            'width' => $dimensions[0] ?? 0,
            'height' => $dimensions[1] ?? 0,
            'dimensions' => ($dimensions[0] ?? 0) . 'x' . ($dimensions[1] ?? 0),
            'format' => $format,
            'time' => $upload_time,
            'url' => get_img_url($identifier),
            'thumb_url' => $thumb_url
        ];
    } catch (Exception $e) {
        error_log("Error getting image info: " . $e->getMessage());
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
    switch ($sort) {
        case 'name-asc':
            usort($images, static fn (string $a, string $b): int => strcmp($a, $b));
            break;
        case 'name-desc':
            usort($images, static fn (string $a, string $b): int => strcmp($b, $a));
            break;
        case 'size-asc':
            usort($images, static function (string $a, string $b): int {
                $sa = (int)@filesize(get_file_path($a));
                $sb = (int)@filesize(get_file_path($b));
                return $sa <=> $sb;
            });
            break;
        case 'size-desc':
            usort($images, static function (string $a, string $b): int {
                $sa = (int)@filesize(get_file_path($a));
                $sb = (int)@filesize(get_file_path($b));
                return $sb <=> $sa;
            });
            break;
        case 'date-asc':
            usort($images, static function (string $a, string $b): int {
                $ta = (int)@filemtime(get_file_path($a));
                $tb = (int)@filemtime(get_file_path($b));
                return $ta <=> $tb;
            });
            break;
        case 'date-desc':
        default:
            usort($images, static function (string $a, string $b): int {
                $ta = (int)@filemtime(get_file_path($a));
                $tb = (int)@filemtime(get_file_path($b));
                return $tb <=> $ta;
            });
            break;
    }

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
    $images = get_uploaded_images();
    $query = trim($query);

    if ($query !== '') {
        $images = array_values(array_filter($images, static function (string $filename) use ($query): bool {
            return mb_stripos($filename, $query) !== false;
        }));
    }

    $images = sort_uploaded_images($images, $sort);

    $total = count($images);
    if ($all) {
        return [
            'items' => build_uploaded_image_api_items($images),
            'pagination' => [
                'page' => 1,
                'per_page' => $total > 0 ? $total : 0,
                'total' => $total,
                'total_pages' => $total > 0 ? 1 : 0,
            ],
        ];
    }

    $per_page = max(1, min(500, $per_page));
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    $offset = ($page - 1) * $per_page;
    $paged = array_slice($images, $offset, $per_page);

    return [
        'items' => build_uploaded_image_api_items($paged),
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
        ],
    ];
}

/**
 * 保存原始文件名映射
 * @param string $system_name 系统生成的文件名
 * @param string $original_name 原始上传的文件名
 */
function save_original_filename($system_name, $original_name) {
    $map_file = UPLOAD_PATH_LOCAL . 'filename_map.json';
    $map = [];
    $normalized_system_name = normalize_image_identifier((string)$system_name);
    $system_name = $normalized_system_name !== '' ? $normalized_system_name : basename((string)$system_name);
    
    if (file_exists($map_file)) {
        $map = json_decode(file_get_contents($map_file), true) ?? [];
    }
    
    $map[$system_name] = $original_name;
    file_put_contents($map_file, json_encode($map, JSON_PRETTY_PRINT));
}

/**
 * 获取原始文件名
 * @param string $system_name 系统文件名
 * @return string|null 原始文件名，如果不存在返回 null
 */
function get_original_filename($system_name) {
    $map_file = UPLOAD_PATH_LOCAL . 'filename_map.json';
    $normalized_system_name = normalize_image_identifier((string)$system_name);
    $system_name = $normalized_system_name !== '' ? $normalized_system_name : basename((string)$system_name);
    if (!file_exists($map_file)) {
        return null;
    }
    
    $map = json_decode(file_get_contents($map_file), true) ?? [];
    return $map[$system_name] ?? null;
}

/**
 * 调试日志
 */
function debug_log($message, $data = null, $type = 'info') {
    if (!DEBUG) return;
    
    $log_file = LOG_PATH . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
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
    try {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            error_log("ImageMagick: file not readable: {$filepath}");
            return false;
        }

        if (!function_exists('exec') && !function_exists('shell_exec')) {
            error_log("ImageMagick: exec/shell_exec not available");
            return false;
        }

        // 尝试查找可用二进制：优先 magick，其次 convert
        $bin = null;
        // 支持 Windows 和 *nix 的查找方法
        $probeCmds = ['magick -version', 'convert -version', 'where magick', 'where convert'];
        foreach ($probeCmds as $pcmd) {
            $out = null;
            $rc = null;
            @exec($pcmd . ' 2>&1', $out, $rc);
            if ($rc === 0 && !empty($out)) {
                // 从命令字符串判断使用 magick 还是 convert
                $bin = strpos($pcmd, 'magick') !== false ? 'magick' : 'convert';
                break;
            }
        }

        if (!$bin) {
            error_log("ImageMagick: binary not found in PATH");
            return false;
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $tmp = $filepath . '.imtmp';
        $quality = max(10, min(100, (int)$quality));

        // 构建针对不同类型的命令
        if (in_array($ext, ['jpg','jpeg'])) {
            $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -interlace Plane -quality {$quality} " . escapeshellarg($tmp);
        } elseif ($ext === 'png') {
            // 针对 png 使用 compression-level 定义（0-9），根据质量做简单映射
            $png_level = max(0, min(9, (int)round((100 - $quality) / 11)));
            $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -define png:compression-level={$png_level} -quality {$quality} " . escapeshellarg($tmp);
        } else {
            // 其它格式尝试通用命令
            $cmd = "{$bin} " . escapeshellarg($filepath) . " -strip -quality {$quality} " . escapeshellarg($tmp);
        }

        @exec($cmd . ' 2>&1', $execOut, $execRc);
        if ($execRc !== 0) {
            error_log("ImageMagick command failed ({$cmd}): " . implode("\n", $execOut));
            @unlink($tmp);
            return false;
        }

        if (!file_exists($tmp) || filesize($tmp) === 0) {
            error_log("ImageMagick produced no output for {$filepath}");
            @unlink($tmp);
            return false;
        }

        $origSize = filesize($filepath);
        $newSize = filesize($tmp);

        // 仅当结果更小才替换
        if ($newSize > 0 && $newSize <= $origSize) {
            if (!@rename($tmp, $filepath)) {
                // 在 Windows 上 rename 可能失败，尝试 copy + unlink
                if (@copy($tmp, $filepath)) {
                    @unlink($tmp);
                } else {
                    error_log("ImageMagick: failed to replace original file for {$filepath}");
                    @unlink($tmp);
                    return false;
                }
            }
            clearstatcache(true, $filepath);
            return true;
        }

        // 未变小则丢弃临时文件
        @unlink($tmp);
        error_log("ImageMagick: output not smaller (orig={$origSize}, new={$newSize}) for {$filepath}");
        return false;
    } catch (Exception $e) {
        error_log("ImageMagick compression error: " . $e->getMessage());
        return false;
    }
}

/**
 * 压缩图片（使用 TinyPNG）
 */
function compress_with_tinypng($filepath) {
    try {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new Exception('文件不可读');
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png'])) {
            error_log("TinyPNG skip: unsupported type .$ext for $filepath");
            return false;
        }

        if (!function_exists('curl_init')) {
            throw new Exception('服务器未启用 cURL 扩展');
        }

        $api_records = get_active_compression_api_keys();
        if (empty($api_records) && defined('TINIFY_API_KEYS') && is_array(TINIFY_API_KEYS)) {
            foreach (TINIFY_API_KEYS as $legacy_key) {
                if (is_string($legacy_key) && $legacy_key !== '') {
                    $api_records[] = [
                        'id' => null,
                        'name' => 'legacy',
                        'api_key' => $legacy_key,
                    ];
                }
            }
        }

        if (empty($api_records)) {
            throw new Exception('未配置可用的 TinyPNG API Key');
        }

        usort($api_records, static function (array $a, array $b): int {
            $a_used = (int)($a['used_count'] ?? 0);
            $b_used = (int)($b['used_count'] ?? 0);
            if ($a_used === $b_used) {
                return 0;
            }
            return $a_used < $b_used ? -1 : 1;
        });

        foreach ($api_records as $api) {
            $key = (string)($api['api_key'] ?? '');
            $api_id = isset($api['id']) ? (string)$api['id'] : null;
            if ($key === '') {
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.tinify.com/shrink",
                CURLOPT_USERPWD => "api:$key",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => file_get_contents($filepath),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'LitePic/2.2.0',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                error_log("TinyPNG cURL error with key {$key}: {$err}");
                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, 0, 'cURL: ' . $err);
                }
                continue;
            }

            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, (int)$header_size);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            if ($status === 429) {
                error_log("TinyPNG API key limit reached for key {$key}");
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, 429, 'rate_limited');
                }
                continue;
            }
            if ($status >= 400) {
                $snippet = substr($body ?? '', 0, 200);
                error_log("TinyPNG HTTP {$status} with key {$key}. Body: {$snippet}");
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, $status, 'http_error');
                }
                continue;
            }

            // 成功：下载压缩后的图片
            $data = json_decode($body, true);
            $downloadUrl = $data['output']['url'] ?? null;
            if (!$downloadUrl) {
                error_log("TinyPNG: missing output url. Body snippet: " . substr($body ?? '', 0, 200));
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, $status, 'missing_output_url');
                }
                continue;
            }

            $download_ch = curl_init($downloadUrl);
            curl_setopt($download_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($download_ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($download_ch, CURLOPT_TIMEOUT, 20);
            $compressed_image = curl_exec($download_ch);
            if ($compressed_image === false) {
                error_log("TinyPNG download error: " . curl_error($download_ch));
                if (PHP_VERSION_ID < 80500) {
                    curl_close($download_ch);
                }
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, $status, 'download_failed');
                }
                continue;
            }
            if (PHP_VERSION_ID < 80500) {
                curl_close($download_ch);
            }

            if (file_put_contents($filepath, $compressed_image) !== false) {
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, true, $status, null);
                }
                return true;
            } else {
                error_log("TinyPNG: failed to write compressed file to $filepath");
                if ($api_id !== null) {
                    record_compression_api_usage($api_id, false, $status, 'write_failed');
                }
            }
        }
        throw new Exception('所有 API key 均尝试失败或超时');
    } catch (Exception $e) {
        error_log("Compression failed: " . $e->getMessage());
        return false;
    }
}

/**
 * 压缩 API Key 存储文件路径
 */
function get_compression_api_keys_file(): string {
    return __DIR__ . '/data/compression_api_keys.json';
}

/**
 * 压缩 API Key 在 .env 的存储键名
 */
function get_compression_api_keys_env_key(): string {
    return 'COMPRESSION_API_KEYS_JSON';
}

/**
 * 获取压缩 API Key 列表
 */
function get_compression_api_keys(): array {
    $data = [];
    $raw_env = trim((string)env_value(get_compression_api_keys_env_key(), ''));
    if ($raw_env !== '') {
        $decoded = json_decode($raw_env, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // 兼容旧 JSON 文件：若 .env 未配置则回退读取，并自动迁移到 .env
    if (empty($data)) {
        $file = get_compression_api_keys_file();
        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                    write_env_kv([
                        get_compression_api_keys_env_key() => env_quote_for_file(
                            json_encode(array_values($data), JSON_UNESCAPED_UNICODE)
                        ),
                    ]);
                }
            }
        }
    }

    $rows = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $api_key = (string)($row['api_key'] ?? '');
        if ($api_key === '') {
            continue;
        }
        $rows[] = [
            'id' => (string)($row['id'] ?? uniqid('cmp_', true)),
            'name' => (string)($row['name'] ?? 'TinyPNG'),
            'api_key' => $api_key,
            'enabled' => (bool)($row['enabled'] ?? true),
            'used_count' => (int)($row['used_count'] ?? 0),
            'success_count' => (int)($row['success_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'last_used_at' => $row['last_used_at'] ?? null,
            'last_status_code' => (int)($row['last_status_code'] ?? 0),
            'last_error' => $row['last_error'] ?? null,
            'created_at' => (string)($row['created_at'] ?? date('c')),
        ];
    }

    return $rows;
}

/**
 * 保存压缩 API Key 列表
 */
function save_compression_api_keys(array $keys): bool {
    $payload = json_encode(array_values($keys), JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return false;
    }
    return write_env_kv([
        get_compression_api_keys_env_key() => env_quote_for_file($payload),
    ]);
}

/**
 * 新增压缩 API Key
 */
function add_compression_api_key(string $name, string $api_key): bool {
    $name = trim($name);
    $api_key = trim($api_key);
    if ($api_key === '') {
        return false;
    }
    if ($name === '') {
        $name = 'TinyPNG';
    }

    $keys = get_compression_api_keys();
    $keys[] = [
        'id' => uniqid('cmp_', true),
        'name' => $name,
        'api_key' => $api_key,
        'enabled' => true,
        'used_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'last_used_at' => null,
        'last_status_code' => 0,
        'last_error' => null,
        'created_at' => date('c'),
    ];

    return save_compression_api_keys($keys);
}

/**
 * 启用/禁用压缩 API Key
 */
function set_compression_api_enabled(string $id, bool $enabled): bool {
    $keys = get_compression_api_keys();
    $updated = false;
    foreach ($keys as &$key) {
        if ((string)($key['id'] ?? '') === $id) {
            $key['enabled'] = $enabled;
            $updated = true;
            break;
        }
    }
    unset($key);

    return $updated ? save_compression_api_keys($keys) : false;
}

/**
 * 删除压缩 API Key
 */
function delete_compression_api_key(string $id): bool {
    $keys = get_compression_api_keys();
    $before = count($keys);
    $keys = array_values(array_filter($keys, static function (array $key) use ($id): bool {
        return (string)($key['id'] ?? '') !== $id;
    }));

    if (count($keys) === $before) {
        return false;
    }

    return save_compression_api_keys($keys);
}

/**
 * 获取启用状态的压缩 API Key
 */
function get_active_compression_api_keys(): array {
    return array_values(array_filter(get_compression_api_keys(), static function (array $key): bool {
        return !empty($key['enabled']) && !empty($key['api_key']);
    }));
}

/**
 * 记录压缩 API Key 使用统计
 */
function record_compression_api_usage(string $id, bool $success, int $status_code = 0, ?string $error = null): void {
    $keys = get_compression_api_keys();
    $updated = false;

    foreach ($keys as &$key) {
        if ((string)($key['id'] ?? '') !== $id) {
            continue;
        }

        $key['used_count'] = (int)($key['used_count'] ?? 0) + 1;
        if ($success) {
            $key['success_count'] = (int)($key['success_count'] ?? 0) + 1;
            $key['last_error'] = null;
        } else {
            $key['failed_count'] = (int)($key['failed_count'] ?? 0) + 1;
            $key['last_error'] = $error;
        }
        $key['last_status_code'] = $status_code;
        $key['last_used_at'] = date('c');
        $updated = true;
        break;
    }
    unset($key);

    if ($updated) {
        save_compression_api_keys($keys);
    }
}

/**
 * 使用 GD 扩展压缩图片（本地兜底）
 */
function compress_with_gd(string $filepath, int $quality = 85): bool {
    if (!extension_loaded('gd')) {
        return false;
    }
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return false;
    }

    $ext = strtolower((string)pathinfo($filepath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return false;
    }

    $original_size = filesize($filepath);
    if ($original_size === false || $original_size <= 0) {
        return false;
    }

    $quality = max(10, min(100, $quality));

    $tmp_path = $filepath . '.gdtmp';
    @unlink($tmp_path);

    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }
        $img = @imagecreatefromjpeg($filepath);
        if ($img === false) {
            return false;
        }
        $ok = @imagejpeg($img, $tmp_path, $quality);
        if ($ok !== true) {
            @unlink($tmp_path);
            return false;
        }
    } else {
        if (!function_exists('imagecreatefrompng')) {
            return false;
        }
        $img = @imagecreatefrompng($filepath);
        if ($img === false) {
            return false;
        }
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $png_level = max(0, min(9, (int)round((100 - $quality) / 11)));
        $ok = @imagepng($img, $tmp_path, $png_level);
        if ($ok !== true) {
            @unlink($tmp_path);
            return false;
        }
    }

    if (!file_exists($tmp_path)) {
        return false;
    }

    clearstatcache(true, $tmp_path);
    $new_size = filesize($tmp_path);
    if ($new_size === false || $new_size <= 0) {
        @unlink($tmp_path);
        return false;
    }

    if ($new_size > $original_size) {
        @unlink($tmp_path);
        return false;
    }

    if (!@rename($tmp_path, $filepath)) {
        if (@copy($tmp_path, $filepath)) {
            @unlink($tmp_path);
        } else {
            @unlink($tmp_path);
            return false;
        }
    }

    clearstatcache(true, $filepath);
    return true;
}

/**
 * 是否支持压缩
 */
function can_compress_extension(string $ext): bool {
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png'], true);
}

/**
 * 是否支持转换 WebP
 */
function can_convert_webp_extension(string $ext): bool {
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'], true);
}

function can_convert_avif_extension(string $ext): bool {
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'], true);
}

function can_convert_preferred_extension(string $ext): bool {
    return CONVERT_PREFERRED_FORMAT === 'avif'
        ? can_convert_avif_extension($ext)
        : can_convert_webp_extension($ext);
}

/**
 * 获取压缩模式
 */
function get_compression_mode(): string {
    $mode = strtolower(trim((string)COMPRESSION_MODE));
    $allowed = ['hybrid', 'local', 'imagemagick', 'gd', 'tinypng'];
    return in_array($mode, $allowed, true) ? $mode : 'hybrid';
}

/**
 * 按配置模式执行压缩
 */
function compress_image_by_mode(string $path, int $quality = 85): array {
    $mode = get_compression_mode();
    $order = [];

    switch ($mode) {
        case 'imagemagick':
            $order = ['imagemagick'];
            break;
        case 'gd':
            $order = ['gd'];
            break;
        case 'tinypng':
            $order = ['tinypng'];
            break;
        case 'local':
            $order = ['imagemagick', 'gd'];
            break;
        case 'hybrid':
        default:
            $order = ['imagemagick', 'gd', 'tinypng'];
            break;
    }

    foreach ($order as $method) {
        if ($method === 'imagemagick' && function_exists('compress_with_imagemagick') && compress_with_imagemagick($path, $quality)) {
            return ['success' => true, 'method' => 'imagemagick', 'mode' => $mode];
        }
        if ($method === 'gd' && function_exists('compress_with_gd') && compress_with_gd($path, $quality)) {
            return ['success' => true, 'method' => 'gd', 'mode' => $mode];
        }
        if ($method === 'tinypng' && defined('ENABLE_COMPRESSION') && ENABLE_COMPRESSION && function_exists('compress_with_tinypng') && compress_with_tinypng($path)) {
            return ['success' => true, 'method' => 'tinypng', 'mode' => $mode];
        }
    }

    return ['success' => false, 'method' => null, 'mode' => $mode];
}

/**
 * 上传后自动压缩（不中断上传流程）
 */
function auto_compress_uploaded_image(string $filename): array {
    $result = [
        'enabled' => AUTO_COMPRESS_ON_UPLOAD,
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

    if (!AUTO_COMPRESS_ON_UPLOAD) {
        $result['skip_reason'] = 'disabled';
        return $result;
    }

    // 互斥策略：开启自动转 WebP 时，自动压缩跳过
    if (defined('AUTO_CONVERT_WEBP_ON_UPLOAD') && AUTO_CONVERT_WEBP_ON_UPLOAD) {
        $result['skip_reason'] = 'webp_enabled';
        return $result;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!can_compress_extension($ext)) {
        $result['skip_reason'] = 'unsupported_format';
        return $result;
    }

    $path = get_file_path($filename);
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
    $result['before_size_text'] = format_filesize($before);

    $compress_result = compress_image_by_mode($path, 85);
    $method = $compress_result['method'];

    clearstatcache(true, $path);
    $after = filesize($path);
    if ($after === false || $after <= 0) {
        $result['skip_reason'] = 'size_unavailable_after';
        return $result;
    }

    $result['after_size'] = $after;
    $result['after_size_text'] = format_filesize($after);

    if ($method === null) {
        $result['skip_reason'] = 'compress_failed';
        return $result;
    }

    $saved = max(0, $before - $after);
    $result['saved_bytes'] = $saved;
    $result['saved_size_text'] = format_filesize($saved);
    $result['saved_percent'] = $before > 0 ? round(($saved / $before) * 100, 2) : 0;
    $result['method'] = $method;

    if ($saved <= 0) {
        $result['skip_reason'] = 'not_reduced';
        return $result;
    }

    $result['compressed'] = true;
    create_thumbnail($filename, true);
    return $result;
}

/**
 * 上传后自动转换 WebP（不中断上传流程）
 */
function auto_convert_uploaded_to_webp(string $filename): array {
    $result = [
        'enabled' => AUTO_CONVERT_WEBP_ON_UPLOAD,
        'attempted' => false,
        'created' => false,
        'skip_reason' => null,
        'filename' => null,
        'url' => null,
    ];

    if (!AUTO_CONVERT_WEBP_ON_UPLOAD) {
        $result['skip_reason'] = 'disabled';
        return $result;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!can_convert_webp_extension($ext)) {
        $result['skip_reason'] = 'unsupported_format';
        return $result;
    }

    $path = get_file_path($filename);
    if (!file_exists($path)) {
        $result['skip_reason'] = 'missing_file';
        return $result;
    }

    $result['attempted'] = true;
    if (!convert_to_webp($path)) {
        $result['skip_reason'] = 'convert_failed';
        return $result;
    }

    $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
    if (!is_string($webp_path) || !file_exists($webp_path)) {
        $result['skip_reason'] = 'output_missing';
        return $result;
    }

    $webp_filename = get_image_identifier_from_path($webp_path) ?? basename($webp_path);
    create_thumbnail($webp_filename, true);

    $result['created'] = true;
    $result['filename'] = $webp_filename;
    $result['url'] = get_img_url($webp_filename);
    return $result;
}

function auto_convert_uploaded_to_avif(string $filename): array {
    $result = [
        'enabled' => AUTO_CONVERT_AVIF_ON_UPLOAD,
        'attempted' => false,
        'created' => false,
        'skip_reason' => null,
        'filename' => null,
        'url' => null,
    ];

    if (!AUTO_CONVERT_AVIF_ON_UPLOAD) {
        $result['skip_reason'] = 'disabled';
        return $result;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!can_convert_avif_extension($ext)) {
        $result['skip_reason'] = 'unsupported_format';
        return $result;
    }

    $path = get_file_path($filename);
    if (!file_exists($path)) {
        $result['skip_reason'] = 'missing_file';
        return $result;
    }

    $result['attempted'] = true;
    if (!convert_to_avif($path)) {
        $result['skip_reason'] = 'convert_failed';
        return $result;
    }

    $avif_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $path);
    if (!is_string($avif_path) || !file_exists($avif_path)) {
        $result['skip_reason'] = 'output_missing';
        return $result;
    }

    $avif_filename = get_image_identifier_from_path($avif_path) ?? basename($avif_path);
    create_thumbnail($avif_filename, true);

    $result['created'] = true;
    $result['filename'] = $avif_filename;
    $result['url'] = get_img_url($avif_filename);
    return $result;
}

/**
 * 记录上传后处理调试日志（用于定位为何未压缩/未转换）
 */
function log_upload_post_process_debug(string $filename, array $compress, array $webp, array $avif = []): void {
    $original_name = get_original_filename($filename) ?? $filename;
    $level = (
        (!empty($compress['attempted']) && empty($compress['compressed'])) ||
        (!empty($webp['attempted']) && empty($webp['created'])) ||
        (!empty($avif['attempted']) && empty($avif['created']))
    ) ? 'warning' : 'info';

    $log_data = [
        'filename' => $filename,
        'original_name' => $original_name,
        'compress' => [
            'enabled' => (bool)($compress['enabled'] ?? false),
            'attempted' => (bool)($compress['attempted'] ?? false),
            'compressed' => (bool)($compress['compressed'] ?? false),
            'method' => $compress['method'] ?? null,
            'skip_reason' => $compress['skip_reason'] ?? null,
            'before_size' => $compress['before_size_text'] ?? null,
            'after_size' => $compress['after_size_text'] ?? null,
            'saved' => $compress['saved_size_text'] ?? null,
            'saved_percent' => $compress['saved_percent'] ?? 0,
        ],
        'webp' => [
            'enabled' => (bool)($webp['enabled'] ?? false),
            'attempted' => (bool)($webp['attempted'] ?? false),
            'created' => (bool)($webp['created'] ?? false),
            'skip_reason' => $webp['skip_reason'] ?? null,
            'filename' => $webp['filename'] ?? null,
        ],
    ];

    if (!empty($avif)) {
        $log_data['avif'] = [
            'enabled' => (bool)($avif['enabled'] ?? false),
            'attempted' => (bool)($avif['attempted'] ?? false),
            'created' => (bool)($avif['created'] ?? false),
            'skip_reason' => $avif['skip_reason'] ?? null,
            'filename' => $avif['filename'] ?? null,
        ];
    }

    debug_log('Upload post-process report', $log_data, $level);
}

/**
 * 上传后自动处理（压缩 + WebP）
 */
function run_upload_post_process(string $filename): array {
    $compress = auto_compress_uploaded_image($filename);
    $webp = auto_convert_uploaded_to_webp($filename);
    $avif = auto_convert_uploaded_to_avif($filename);
    log_upload_post_process_debug($filename, $compress, $webp, $avif);

    return [
        'auto_compress' => $compress,
        'auto_webp' => $webp,
        'auto_avif' => $avif,
    ];
}

/**
 * 保存图片
 */
function save_image_with_type($image, $filepath, $mime) {
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($image, $filepath, 90);
            break;
        case 'image/png':
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, $filepath);
            break;
        case 'image/gif':
            imagegif($image, $filepath);
            break;
    }
}

/**
 * 转换为 WebP 格式
 */
function convert_to_webp($filepath) {
    try {
        if (!function_exists('imagewebp')) {
            throw new Exception('当前环境不支持 WebP');
        }

        if (!file_exists($filepath)) {
            throw new Exception('原始文件不存在');
        }

        $image_info = getimagesize($filepath);
        if (!$image_info) {
            throw new Exception('无法获取图片信息');
        }

        $mime_type = (string)($image_info['mime'] ?? '');
        $source = create_image_resource($filepath, $mime_type);
        
        if (!$source) {
            throw new Exception('无法创建图片资源');
        }

        // 设置 WebP 输出路径
        $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $filepath);
        if (!is_string($webp_path) || $webp_path === '') {
            throw new Exception('无法确定 WebP 输出路径');
        }
        
        // 转换为 WebP
        $result = imagewebp($source, $webp_path, 80);

        if (!$result) {
            throw new Exception('WebP 生成失败');
        }

        // 保存原始文件名映射
        $original_identifier = get_image_identifier_from_path($filepath) ?? basename($filepath);
        $original_filename = get_original_filename($original_identifier) ?? basename($filepath);
        $webp_filename = get_image_identifier_from_path($webp_path) ?? basename($webp_path);
        save_original_filename($webp_filename, $original_filename);

        return true;
    } catch (Exception $e) {
        error_log("WebP conversion error: " . $e->getMessage());
        return false;
    }
}

function convert_to_avif($filepath) {
    try {
        if (!function_exists('imageavif')) {
            throw new Exception('当前环境不支持 AVIF');
        }

        if (!file_exists($filepath)) {
            throw new Exception('原始文件不存在');
        }

        $image_info = getimagesize($filepath);
        if (!$image_info) {
            throw new Exception('无法获取图片信息');
        }

        $mime_type = (string)($image_info['mime'] ?? '');
        $source = create_image_resource($filepath, $mime_type);
        
        if (!$source) {
            throw new Exception('无法创建图片资源');
        }

        // 设置 AVIF 输出路径
        $avif_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.avif', $filepath);
        if (!is_string($avif_path) || $avif_path === '') {
            throw new Exception('无法确定 AVIF 输出路径');
        }
        
        // 转换为 AVIF
        $result = imageavif($source, $avif_path, 80);

        if (!$result) {
            throw new Exception('AVIF 生成失败');
        }

        // 保存原始文件名映射
        $original_identifier = get_image_identifier_from_path($filepath) ?? basename($filepath);
        $original_filename = get_original_filename($original_identifier) ?? basename($filepath);
        $avif_filename = get_image_identifier_from_path($avif_path) ?? basename($avif_path);
        save_original_filename($avif_filename, $original_filename);

        return true;
    } catch (Exception $e) {
        error_log("AVIF conversion error: " . $e->getMessage());
        return false;
    }
}

/**
 * 创建图片资源
 */
function can_allocate_memory_for_image(string $filepath, string $mime): bool {
    $info = @getimagesize($filepath);
    if (!is_array($info)) {
        return true;
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return true;
    }

    $channels = 4; // GD 内部常按 truecolor 处理，按 RGBA 估算
    $estimated = (int)($width * $height * $channels * 1.8); // 额外给中间缓冲留冗余

    $limitBytes = ini_size_to_bytes((string)ini_get('memory_limit'));
    if ($limitBytes <= 0) {
        return true; // -1 或无法解析，视为不限
    }

    $usedBytes = (int)memory_get_usage(true);
    $safety = 8 * 1024 * 1024; // 额外保留 8MB 安全余量

    return ($usedBytes + $estimated + $safety) < $limitBytes;
}

function create_image_resource($filepath, $mime) {
    $safePath = (string)$filepath;
    $safeMime = (string)$mime;
    if (!can_allocate_memory_for_image($safePath, $safeMime)) {
        debug_log('Skip image resource create due to memory limit', [
            'file' => basename($safePath),
            'mime' => $safeMime,
            'memory_limit' => ini_get('memory_limit'),
            'memory_used' => memory_get_usage(true),
        ], 'warning');
        return null;
    }

    switch ($mime) {
        case 'image/jpeg':
            return function_exists('imagecreatefromjpeg') ? imagecreatefromjpeg($filepath) : null;
        case 'image/png':
            if (!function_exists('imagecreatefrompng')) {
                return null;
            }
            $source = imagecreatefrompng($filepath);
            if ($source) {
                imagepalettetotruecolor($source);
                imagealphablending($source, true);
                imagesavealpha($source, true);
            }
            return $source;
        case 'image/gif':
            return function_exists('imagecreatefromgif') ? imagecreatefromgif($filepath) : null;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($filepath) : null;
        case 'image/avif':
            return function_exists('imagecreatefromavif') ? imagecreatefromavif($filepath) : null;
        default:
            return null;
    }
}

/**
 * 重新整理文件数组
 */
function reArrayFiles($files) {
    $file_array = [];
    $file_count = count($files['name']);
    $file_keys = array_keys($files);

    for ($i = 0; $i < $file_count; $i++) {
        foreach ($file_keys as $key) {
            $file_array[$i][$key] = $files[$key][$i];
        }
    }

    return $file_array;
}

/**
 * 将 php.ini 尺寸字符串转为字节数（如 2M / 512K / 1G）
 */
function ini_size_to_bytes($value): int {
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0;
    }

    $unit = strtolower(substr($raw, -1));
    $num = (float)$raw;
    if ($num <= 0) {
        return 0;
    }

    switch ($unit) {
        case 'p':
            $num *= 1024;
            // no break
        case 't':
            $num *= 1024;
            // no break
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
            break;
        default:
            // 纯数字按字节处理
            break;
    }

    return (int)round($num);
}

/**
 * 获取 PHP 上传限制（upload_max_filesize 与 post_max_size 的较小值）
 */
function get_php_upload_limit_bytes(): int {
    $upload_max = ini_size_to_bytes(ini_get('upload_max_filesize'));
    $post_max = ini_size_to_bytes(ini_get('post_max_size'));

    if ($upload_max <= 0 && $post_max <= 0) {
        return 0;
    }
    if ($upload_max <= 0) {
        return $post_max;
    }
    if ($post_max <= 0) {
        return $upload_max;
    }
    return min($upload_max, $post_max);
}

/**
 * 获取应用可用的实际上传上限（系统配置与 PHP 限制取最小值）
 */
function get_effective_upload_max_bytes(): int {
    $php_limit = get_php_upload_limit_bytes();
    if ($php_limit <= 0) {
        return (int)MAX_FILE_SIZE;
    }
    return min((int)MAX_FILE_SIZE, $php_limit);
}

/**
 * 将上传错误码映射为可读消息
 */
function get_upload_error_message(int $error_code, string $filename = ''): string {
    $name = $filename !== '' ? "文件 {$filename} " : '文件 ';
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return $name . '超过 PHP 上传限制（upload_max_filesize/post_max_size）。当前约为 ' . format_filesize(get_php_upload_limit_bytes());
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
            return $name . '上传失败（错误码: ' . $error_code . '）';
    }
}

/**
 * 标准化上传文件数组（兼容单文件和多文件结构）
 */
function normalize_uploaded_files(array $raw_files): array {
    if (!isset($raw_files['name'])) {
        return [];
    }

    if (!is_array($raw_files['name'])) {
        return [$raw_files];
    }

    return reArrayFiles($raw_files);
}

/**
 * 统一处理上传文件并返回结果数组
 *
 * @param array<int, array<string, mixed>> $files
 * @return array<int, array<string, mixed>>
 */
function handle_uploaded_files(array $files): array {
    $results = [];

    foreach ($files as $file) {
        $original_name = (string)($file['name'] ?? '');
        $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upload_error !== UPLOAD_ERR_OK) {
            $results[] = [
                'status' => 'error',
                'message' => get_upload_error_message($upload_error, $original_name),
            ];
            continue;
        }

        $ext = strtolower((string)pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_TYPES, true)) {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 类型不支持",
            ];
            continue;
        }

        // 增加 MIME / 文件头校验（防止扩展名伪造）
        $tmp_name = (string)($file['tmp_name'] ?? '');
        if ($tmp_name !== '' && !validate_upload_mime($tmp_name, $ext)) {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 内容与实际类型不符或包含危险内容",
            ];
            continue;
        }

        if ((int)($file['size'] ?? 0) > get_effective_upload_max_bytes()) {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 超过大小限制（当前上限 " . format_filesize(get_effective_upload_max_bytes()) . '）',
            ];
            continue;
        }

        if ($tmp_name === '') {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 上传源无效",
            ];
            continue;
        }

        $filename = generate_filename($ext);
        $storage_path = get_storage_path();
        $target = $storage_path . $filename;

        if (!is_dir($storage_path) && !mkdir($storage_path, 0777, true) && !is_dir($storage_path)) {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 存储目录创建失败",
            ];
            continue;
        }

        if (!move_uploaded_file($tmp_name, $target)) {
            $results[] = [
                'status' => 'error',
                'message' => "文件 {$original_name} 保存失败",
            ];
            continue;
        }

        $identifier = get_image_identifier_from_path($target) ?? $filename;

        save_original_filename($identifier, $original_name);
        create_thumbnail($identifier);
        $processing = run_upload_post_process($identifier);

        $final_filename = $identifier;
        $final_url = get_img_url($identifier);
        $original_deleted = false;

        // 若启用了自动转 WebP 且转换成功，则仅保留 WebP，删除原图
        if (!empty($processing['auto_webp']['created']) && !empty($processing['auto_webp']['filename'])) {
            $webp_filename = normalize_image_identifier((string)$processing['auto_webp']['filename']);
            $webp_path = get_file_path($webp_filename);
            if (file_exists($webp_path)) {
                $origin_path = get_file_path($identifier);
                if (file_exists($origin_path) && basename($origin_path) !== get_image_display_name($webp_filename)) {
                    if (!KEEP_ORIGINAL_AFTER_PROCESS) {
                        @unlink($origin_path);
                        delete_thumbnail($identifier);
                        $original_deleted = true;
                    }
                }
                $final_filename = $webp_filename;
                $final_url = get_img_url($webp_filename);
            }
        }

        // 若启用了自动转 AVIF 且转换成功，则仅保留 AVIF，删除之前的文件
        if (!empty($processing['auto_avif']['created']) && !empty($processing['auto_avif']['filename'])) {
            $avif_filename = normalize_image_identifier((string)$processing['auto_avif']['filename']);
            $avif_path = get_file_path($avif_filename);
            if (file_exists($avif_path)) {
                $prev_path = get_file_path($final_filename);
                if (file_exists($prev_path) && basename($prev_path) !== get_image_display_name($avif_filename)) {
                    if (!KEEP_ORIGINAL_AFTER_PROCESS) {
                        @unlink($prev_path);
                        delete_thumbnail($final_filename);
                        $original_deleted = true;
                    }
                }
                $final_filename = $avif_filename;
                $final_url = get_img_url($avif_filename);
            }
        }

        $final_thumbnail_url = $final_url;
        if (can_generate_thumbnail($final_filename) && create_thumbnail((string)$final_filename)) {
            $final_thumbnail_url = get_thumbnail_url((string)$final_filename);
        }

        $remote_sync = remote_storage_sync_file_and_thumbnail($final_filename);
        $processing['original_deleted'] = $original_deleted;
        $processing['final_filename'] = $final_filename;
        $processing['remote_storage'] = $remote_sync;

        $results[] = [
            'status' => 'success',
            'filename' => $final_filename,
            'original_name' => $original_name,
            'url' => $final_url,
            'thumbnail_url' => $final_thumbnail_url,
            'processing' => $processing,
        ];
    }

    return $results;
}

/**
 * 保存设置
 */
function save_settings($settings) {
    $config_path = __DIR__ . '/config.php';
    $config_content = file_get_contents($config_path);
    
    foreach ($settings as $key => $value) {
        if (is_array($value)) {
            $value_str = '[' . implode(', ', $value) . ']';
        } else if (is_bool($value)) {
            $value_str = $value ? 'true' : 'false';
        } else {
            $value_str = "'" . addslashes($value) . "'";
        }
        
        $pattern = "/define\('$key',\s*[^)]+\);/";
        $replacement = "define('$key', $value_str);";
        $config_content = preg_replace($pattern, $replacement, $config_content);
    }
    
    file_put_contents($config_path, $config_content);
}

/**
 * 格式化文件大小
 */
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
    $fallback = [
        'id'      => strtolower(PHP_OS_FAMILY ?: 'unknown'),
        'name'    => php_uname('s'),
        'version' => php_uname('r'),
        'pretty'  => trim(php_uname('s') . ' ' . php_uname('r')),
    ];

    $raw = @file_get_contents('/etc/os-release');
    if (!is_string($raw) || $raw === '') {
        return $fallback;
    }

    $kv = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        // 去掉值两侧的引号
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $kv[$k] = $v;
    }

    $id      = strtolower($kv['ID'] ?? $fallback['id']);
    $name    = $kv['NAME'] ?? $fallback['name'];
    $version = $kv['VERSION_ID'] ?? '';

    // 把 NAME 里的 "GNU/Linux" / "Linux" 通用后缀去掉，得到品牌名
    // ("Debian GNU/Linux" → "Debian", "Ubuntu" → "Ubuntu")。
    $brand = trim((string)preg_replace('~\s*(GNU/)?Linux\s*$~i', '', $name));
    if ($brand === '') {
        $brand = $name;
    }

    // 品牌 + VERSION_ID（"Debian 13"），拿不到 VERSION_ID 时退回 PRETTY_NAME。
    $pretty = $version !== '' ? trim($brand . ' ' . $version) : ($kv['PRETTY_NAME'] ?? $name);

    return [
        'id'      => $id,
        'name'    => $name,
        'version' => $version,
        'pretty'  => $pretty,
    ];
}

/**
 * 采集服务器运行状态（用于设置页实时面板）
 */
function get_server_uptime_seconds(): ?int {
    // 不用 is_readable 探测——它不接受 @ 抑制，会在 open_basedir 限制下打出警告。
    // 直接 @file_get_contents 即可，访问被禁时返回 false 落到下面的 fallback。
    $raw = @file_get_contents('/proc/uptime');
    if (is_string($raw) && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $matches)) {
        return max(0, (int)floor((float)$matches[1]));
    }

    if (function_exists('shell_exec')) {
        $boot_raw = @shell_exec('sysctl -n kern.boottime 2>/dev/null');
        if (is_string($boot_raw) && preg_match('/sec\s*=\s*(\d+)/', $boot_raw, $matches)) {
            return max(0, time() - (int)$matches[1]);
        }
    }

    return null;
}

function get_server_runtime_metrics(): array {
    $php_upload_limit = get_php_upload_limit_bytes();
    $configured_upload_limit = (int)MAX_FILE_SIZE;
    $server_now = time();
    $uptime_seconds = get_server_uptime_seconds();
    $availability_24h_percent = $uptime_seconds !== null
        ? round((min($uptime_seconds, 24 * 60 * 60) / (24 * 60 * 60)) * 100, 2)
        : null;

    $memory_limit_bytes = ini_size_to_bytes((string)ini_get('memory_limit'));
    $memory_used_bytes = (int)memory_get_usage(true);
    $memory_peak_bytes = (int)memory_get_peak_usage(true);

    // 尝试读取系统物理内存（优先于 PHP memory_limit）
    $system_mem_total = 0;
    $system_mem_used = 0;
    if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'BSD') {
        // 优先直接读 /proc/meminfo（@ 在 open_basedir 下会静默 fail），shell_exec 仅作 fallback
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!is_string($meminfo) || $meminfo === '') {
            $meminfo = function_exists('shell_exec') ? @shell_exec('cat /proc/meminfo 2>/dev/null') : '';
        }
        if (is_string($meminfo) && $meminfo !== '') {
            $memTotal = 0;
            $memAvailable = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memTotal = (int)$m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memAvailable = (int)$m[1] * 1024;
            } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $memAvailable = (int)$m[1] * 1024;
            }
            if ($memTotal > 0) {
                $system_mem_total = $memTotal;
                $system_mem_used = max(0, $memTotal - $memAvailable);
            }
        }
    } elseif (function_exists('shell_exec')) {
        if (PHP_OS_FAMILY === 'Darwin') {
            $hw_memsize = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if (is_string($hw_memsize) && is_numeric(trim($hw_memsize))) {
                $system_mem_total = (int)trim($hw_memsize);
            }
            $vm_stat = @shell_exec('vm_stat 2>/dev/null');
            if (is_string($vm_stat) && $vm_stat !== '') {
                $page_size = 4096;
                if (preg_match('/page size of (\d+) bytes/', $vm_stat, $m)) {
                    $page_size = (int)$m[1];
                }
                $pages = ['free' => 0, 'active' => 0, 'inactive' => 0, 'wired down' => 0, 'speculative' => 0];
                foreach ($pages as $key => &$val) {
                    $pattern = '/Pages ' . preg_quote($key, '/') . ':\s+(\d+)\./';
                    if (preg_match($pattern, $vm_stat, $m)) {
                        $val = (int)$m[1];
                    }
                }
                unset($val);
                $system_mem_used = ($pages['active'] + $pages['inactive'] + $pages['wired down'] + $pages['speculative']) * $page_size;
            }
        }
    }

    // 如果读取到系统内存，使用系统内存；否则回退到 PHP 内存限制
    if ($system_mem_total > 0) {
        $memory_total_bytes = $system_mem_total;
        $memory_used_bytes_display = $system_mem_used;
    } else {
        $memory_total_bytes = $memory_limit_bytes;
        $memory_used_bytes_display = $memory_used_bytes;
    }

    $disk_total = @disk_total_space(__DIR__);
    $disk_free = @disk_free_space(__DIR__);
    $disk_total_bytes = is_numeric($disk_total) ? (int)$disk_total : 0;
    $disk_free_bytes = is_numeric($disk_free) ? (int)$disk_free : 0;
    $disk_used_bytes = max(0, $disk_total_bytes - $disk_free_bytes);

    $load_avg = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    $load_1 = (is_array($load_avg) && isset($load_avg[0])) ? (float)$load_avg[0] : null;
    $load_5 = (is_array($load_avg) && isset($load_avg[1])) ? (float)$load_avg[1] : null;
    $load_15 = (is_array($load_avg) && isset($load_avg[2])) ? (float)$load_avg[2] : null;

    // CPU 核数：优先数 /proc/cpuinfo 的 processor 行（不依赖 shell_exec）
    $cpu_cores = null;
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpuinfo) && $cpuinfo !== '') {
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
        if ($count > 0) {
            $cpu_cores = $count;
        }
    }
    if ($cpu_cores === null && function_exists('shell_exec')) {
        $nproc = @shell_exec('nproc 2>/dev/null');
        if (is_string($nproc) && ctype_digit(trim($nproc))) {
            $cpu_cores = (int)trim($nproc);
        }
        if ($cpu_cores === null) {
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (is_string($sysctl) && ctype_digit(trim($sysctl))) {
                $cpu_cores = (int)trim($sysctl);
            }
        }
    }

    // uptime 文本：基于 $uptime_seconds 自己拼，避免依赖被沙箱禁用的 `uptime` 命令
    $uptime_text = '当前环境不支持读取';
    if ($uptime_seconds !== null) {
        $days = intdiv($uptime_seconds, 86400);
        $hours = intdiv($uptime_seconds % 86400, 3600);
        $mins = intdiv($uptime_seconds % 3600, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days . ' 天';
        if ($hours > 0) $parts[] = $hours . ' 小时';
        if ($mins > 0 || empty($parts)) $parts[] = $mins . ' 分钟';
        $uptime_text = implode(' ', $parts);
    } elseif (function_exists('shell_exec')) {
        $uptime_raw = @shell_exec('uptime 2>/dev/null');
        if (is_string($uptime_raw) && trim($uptime_raw) !== '') {
            $uptime_text = trim($uptime_raw);
        }
    }

    $memory_usage_percent = 0.0;
    if ($memory_total_bytes > 0) {
        $memory_usage_percent = round(($memory_used_bytes_display / $memory_total_bytes) * 100, 2);
    }

    $disk_usage_percent = 0.0;
    if ($disk_total_bytes > 0) {
        $disk_usage_percent = round(($disk_used_bytes / $disk_total_bytes) * 100, 2);
    }

    return [
        'server_ip' => (function (): string {
            // 优先通过系统命令获取实际局域网 IP
            if (function_exists('shell_exec')) {
                if (PHP_OS_FAMILY === 'Darwin') {
                    foreach (['en0', 'en1', 'en2', 'en3'] as $iface) {
                        $ip = @shell_exec('ipconfig getifaddr ' . $iface . ' 2>/dev/null');
                        if (is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                            $trimmed = trim($ip);
                            if ($trimmed !== '' && $trimmed !== '127.0.0.1' && $trimmed !== '::1') {
                                return $trimmed;
                            }
                        }
                    }
                }
                if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'BSD' || PHP_OS_FAMILY === 'Darwin') {
                    $ip = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
                    if (is_string($ip)) {
                        $trimmed = trim($ip);
                        if ($trimmed !== '' && $trimmed !== '127.0.0.1') {
                            return $trimmed;
                        }
                    }
                    $ip = @shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '/src/ {print $7; exit}'");
                    if (is_string($ip)) {
                        $trimmed = trim($ip);
                        if ($trimmed !== '' && $trimmed !== '127.0.0.1') {
                            return $trimmed;
                        }
                    }
                }
            }
            $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
            if ($addr !== '' && $addr !== '127.0.0.1' && $addr !== '::1' && $addr !== 'localhost') {
                return $addr;
            }
            $host = gethostbyname((string)gethostname());
            if ($host !== (string)gethostname() && $host !== '127.0.0.1') {
                return $host;
            }
            return $addr ?: '127.0.0.1';
        })(),
        'os' => get_distro_info()['pretty'],
        'distro' => get_distro_info(),
        'php_version' => PHP_VERSION,
        'php_sapi' => (string)php_sapi_name(),
        'php_upload_limit_bytes' => $php_upload_limit,
        'config_upload_limit_bytes' => $configured_upload_limit,
        'php_upload_limit_text' => format_filesize($php_upload_limit),
        'config_upload_limit_text' => format_filesize($configured_upload_limit),
        'upload_limit_ok' => $php_upload_limit >= $configured_upload_limit,
        'uptime_text' => $uptime_text,
        'uptime_seconds' => $uptime_seconds,
        'availability_24h_percent' => $availability_24h_percent,
        'cpu_cores' => $cpu_cores,
        'cpu_load' => [
            'load_1' => $load_1,
            'load_5' => $load_5,
            'load_15' => $load_15,
            'text' => ($load_1 !== null && $load_5 !== null && $load_15 !== null)
                ? sprintf('%.2f / %.2f / %.2f', $load_1, $load_5, $load_15)
                : '不可用',
        ],
        'memory' => [
            'limit_bytes' => $memory_total_bytes,
            'used_bytes' => $memory_used_bytes_display,
            'peak_bytes' => $memory_peak_bytes,
            'usage_percent' => $memory_usage_percent,
            'text' => format_filesize($memory_used_bytes_display) . ' / ' . format_filesize($memory_total_bytes > 0 ? $memory_total_bytes : $memory_used_bytes_display),
            'peak_text' => format_filesize($memory_peak_bytes),
        ],
        'disk' => [
            'total_bytes' => $disk_total_bytes,
            'used_bytes' => $disk_used_bytes,
            'free_bytes' => $disk_free_bytes,
            'usage_percent' => $disk_usage_percent,
            'text' => format_filesize($disk_used_bytes) . ' / ' . format_filesize($disk_total_bytes > 0 ? $disk_total_bytes : $disk_used_bytes),
            'free_text' => format_filesize($disk_free_bytes),
        ],
        'capability' => [
            'gd' => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick'),
            'avif' => (function_exists('imagecreatefromavif') && function_exists('imageavif'))
                || (extension_loaded('imagick') && in_array('AVIF', @(new Imagick())->queryFormats('AVIF') ?: [], true)),
            'webp' => function_exists('imagewebp'),
        ],
    ];
}

/**
 * 获取访问量
 */
function get_visit_count() {
    $count_file = __DIR__ . '/data/visit_count.txt';
    
    if (!is_dir(dirname($count_file))) {
        mkdir(dirname($count_file), 0777, true);
    }
    
    $count = file_exists($count_file) ? (int)file_get_contents($count_file) : 0;
    $count++;
    file_put_contents($count_file, $count);
    
    return $count;
}

/**
 * 页脚统计缓存文件
 */
function get_footer_stats_cache_file(): string {
    return __DIR__ . '/data/footer_stats_cache.json';
}

/**
 * 获取页脚统计缓存（TTL 秒）
 */
function get_footer_stats_cached(int $ttl = 45): array {
    $cache_file = get_footer_stats_cache_file();
    if (is_file($cache_file)) {
        $raw = file_get_contents($cache_file);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (
                is_array($data) &&
                isset($data['ts'], $data['image_count'], $data['total_size']) &&
                ((time() - (int)$data['ts']) <= $ttl)
            ) {
                return [
                    'image_count' => (int)$data['image_count'],
                    'total_size' => (int)$data['total_size'],
                ];
            }
        }
    }

    $images = get_uploaded_images();
    $count = count($images);
    $size = 0;
    foreach ($images as $image) {
        $path = get_file_path($image);
        if (is_file($path)) {
            $size += (int)filesize($path);
        }
    }

    $payload = [
        'ts' => time(),
        'image_count' => $count,
        'total_size' => $size,
    ];
    @file_put_contents($cache_file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return [
        'image_count' => $count,
        'total_size' => $size,
    ];
}

/**
 * 获取上传图片总数
 */
function get_image_count() {
    $stats = get_footer_stats_cached();
    return (int)$stats['image_count'];
}

/**
 * 获取已使用空间大小
 */
function get_total_size() {
    $stats = get_footer_stats_cached();
    return (int)$stats['total_size'];
}

/**
 * 验证管理员权限
 */
function is_admin(): bool {
    if (!isset($_COOKIE[API_KEY_COOKIE]) || ADMIN_API_KEY === '') {
        return false;
    }

    return hash_equals(hash('sha256', ADMIN_API_KEY), (string)$_COOKIE[API_KEY_COOKIE]);
}

/**
 * 读取请求头
 */
function get_request_header(string $name): ?string {
    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server_key])) {
        return trim((string)$_SERVER[$server_key]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return null;
}

/**
 * 获取请求携带的 API Key
 */
function get_request_api_key(): ?string {
    $key = get_request_header('X-API-Key');
    if (!empty($key)) {
        return $key;
    }

    $auth = get_request_header('Authorization');
    if (!empty($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * 验证第三方 API 请求权限（支持 Cookie 登录或 API Key）
 */
function has_upload_api_access(): bool {
    if (is_admin()) {
        return true;
    }

    $api_key = get_request_api_key();
    if (empty($api_key)) {
        return false;
    }

    if (ADMIN_API_KEY !== '' && hash_equals(ADMIN_API_KEY, $api_key)) {
        return true;
    }

    if (defined('THIRD_PARTY_API_KEYS') && is_array(THIRD_PARTY_API_KEYS)) {
        foreach (THIRD_PARTY_API_KEYS as $allowed_key) {
            if (is_string($allowed_key) && $allowed_key !== '' && hash_equals($allowed_key, $api_key)) {
                return true;
            }
        }
    }

    if (verify_managed_api_token($api_key)) {
        return true;
    }

    return false;
}

/**
 * 验证后台管理接口权限（仅管理员 Cookie 或管理员主密钥）
 */
function is_api_request_authorized(): bool {
    if (is_admin()) {
        return true;
    }

    $api_key = get_request_api_key();
    if ($api_key === null || $api_key === '') {
        return false;
    }

    return ADMIN_API_KEY !== '' && hash_equals(ADMIN_API_KEY, $api_key);
}

/**
 * 管理型 API Token 存储文件路径
 */
function get_api_tokens_file(): string {
    return __DIR__ . '/data/api_tokens.json';
}

/**
 * 管理型 API Token 在 .env 的存储键名
 */
function get_managed_api_tokens_env_key(): string {
    return 'MANAGED_API_TOKENS_JSON';
}

/**
 * 读取管理型 API Token 列表
 */
function get_managed_api_tokens(): array {
    $tokens = [];
    $raw_env = trim((string)env_value(get_managed_api_tokens_env_key(), ''));
    if ($raw_env !== '') {
        $decoded = json_decode($raw_env, true);
        if (is_array($decoded)) {
            $tokens = $decoded;
        }
    }

    // 兼容旧 JSON 文件：若 .env 未配置则回退读取，并自动迁移到 .env
    if (empty($tokens)) {
        $file = get_api_tokens_file();
        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $tokens = $decoded;
                    write_env_kv([
                        get_managed_api_tokens_env_key() => env_quote_for_file(
                            json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE)
                        ),
                    ]);
                }
            }
        }
    }

    $migrated = false;
    $tokens = array_map(static function ($item) use (&$migrated) {
        if (!is_array($item)) {
            return $item;
        }
        if (array_key_exists('token_plain', $item)) {
            unset($item['token_plain']);
            $migrated = true;
        }
        return $item;
    }, $tokens);

    $tokens = array_values(array_filter($tokens, static function ($item) {
        return is_array($item) && isset($item['id'], $item['token_hash']);
    }));

    if ($migrated) {
        save_managed_api_tokens($tokens);
    }

    return $tokens;
}

/**
 * 保存管理型 API Token 列表
 */
function save_managed_api_tokens(array $tokens): bool {
    $payload = json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return false;
    }
    return write_env_kv([
        get_managed_api_tokens_env_key() => env_quote_for_file($payload),
    ]);
}

/**
 * 创建管理型 API Token
 */
function create_managed_api_token(string $name = 'token'): ?string {
    $name = trim($name);
    if ($name === '') {
        $name = 'token';
    }

    try {
        $plain = 'ltp_' . bin2hex(random_bytes(24));
    } catch (Exception $e) {
        return null;
    }

    $tokens = get_managed_api_tokens();
    $tokens[] = [
        'id' => uniqid('tok_', true),
        'name' => $name,
        'token_hash' => hash('sha256', $plain),
        'created_at' => date('c'),
        'last_used_at' => null,
        'revoked_at' => null,
    ];

    if (!save_managed_api_tokens($tokens)) {
        return null;
    }

    return $plain;
}

/**
 * 撤销管理型 API Token
 */
function revoke_managed_api_token(string $token_id): bool {
    $tokens = get_managed_api_tokens();
    $updated = false;

    foreach ($tokens as &$token) {
        if (($token['id'] ?? '') === $token_id) {
            $token['revoked_at'] = date('c');
            $updated = true;
            break;
        }
    }
    unset($token);

    return $updated ? save_managed_api_tokens($tokens) : false;
}

/**
 * 验证管理型 API Token
 */
function verify_managed_api_token(string $plain_token): bool {
    if ($plain_token === '') {
        return false;
    }

    $tokens = get_managed_api_tokens();
    $hash = hash('sha256', $plain_token);
    $matched = false;

    foreach ($tokens as &$token) {
        $revoked_at = $token['revoked_at'] ?? null;
        $token_hash = $token['token_hash'] ?? '';
        if (!empty($revoked_at) || !is_string($token_hash) || $token_hash === '') {
            continue;
        }

        if (hash_equals($token_hash, $hash)) {
            $token['last_used_at'] = date('c');
            $matched = true;
            break;
        }
    }
    unset($token);

    if ($matched) {
        save_managed_api_tokens($tokens);
    }

    return $matched;
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
    session_init_safe();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);
    $now = time();

    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return true;
    }

    $attempts = (array)$_SESSION[$key];
    // 只保留最近 5 分钟内的失败记录
    $recent = [];
    foreach ($attempts as $t) {
        if ($now - (int)$t < 300) {
            $recent[] = $t;
        }
    }
    $_SESSION[$key] = $recent;

    // 5 分钟内超过 5 次则封禁
    return count($recent) < 5;
}

/**
 * 记录一次登录失败
 */
function record_login_failure(): void {
    session_init_safe();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    $_SESSION[$key][] = time();
}

/**
 * 上传文件 MIME 类型校验
 * 返回 true 表示通过，false 表示不通过
 */
function validate_upload_mime(string $tmp_name, string $ext): bool {
    if (!is_file($tmp_name) || !is_readable($tmp_name)) {
        return false;
    }

    // SVG 单独处理：需检查是否包含恶意脚本（基础防护）
    if ($ext === 'svg') {
        $content = file_get_contents($tmp_name);
        if ($content === false) {
            return false;
        }
        $lower = strtolower($content);
        // 检测常见的危险标签和事件处理器
        $dangerous = ['<script', 'javascript:', 'onload=', 'onerror=', 'onmouseover=', 'onfocus='];
        foreach ($dangerous as $d) {
            if (str_contains($lower, $d)) {
                return false;
            }
        }
        return true;
    }

    // 使用 finfo 获取真实 MIME 类型
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);
            if ($mime === false) {
                return false;
            }
        } else {
            return false;
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp_name);
        if ($mime === false) {
            return false;
        }
    } else {
        // 无 MIME 检测扩展时，使用 getimagesize 做降级校验
        $info = @getimagesize($tmp_name);
        if ($info === false) {
            return false;
        }
        $mime = $info['mime'] ?? '';
    }

    $allowed_mimes = [
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

    if (!isset($allowed_mimes[$mime])) {
        return false;
    }

    return in_array($ext, $allowed_mimes[$mime], true);
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

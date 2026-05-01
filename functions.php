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
    return (new \LitePic\Service\Storage\RemoteStorage())->credentialsValid();
}

function remote_storage_usage(): string {
    return (new \LitePic\Service\Storage\RemoteStorage())->usage();
}

function remote_storage_mode(): string {
    return (new \LitePic\Service\Storage\RemoteStorage())->mode();
}

function remote_storage_enabled(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->isEnabled();
}

function remote_storage_config_valid(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->isConfigValid();
}

function remote_storage_public_delivery_enabled(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicDeliveryEnabled();
}

function remote_storage_public_url_for_object_key(string $object_key): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForObjectKey($object_key);
}

function remote_storage_public_url_for_identifier(string $identifier): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForIdentifier($identifier);
}

function remote_storage_public_url_for_local_path(string $local_path): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForLocalPath($local_path);
}

function remote_storage_queue_delete_object(string $object_key, ?int $delay_seconds = null): void {
    $delay = $delay_seconds ?? (defined('REMOTE_STORAGE_DELETE_DELAY_SECONDS') ? (int)REMOTE_STORAGE_DELETE_DELAY_SECONDS : 86400);
    (new \LitePic\Repository\RemoteDeleteQueueRepository())->enqueue($object_key, $delay);
}

function remote_storage_process_delete_queue(int $limit = 25): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->processDeleteQueue($limit);
}

function remote_storage_prefix(): string {
    return \LitePic\Service\Storage\RemoteStorage::prefix();
}

function remote_storage_relative_path(string $local_path): ?string {
    return \LitePic\Service\Storage\RemoteStorage::relativePath($local_path);
}

function remote_storage_object_key_from_local_path(string $local_path): ?string {
    return \LitePic\Service\Storage\RemoteStorage::objectKeyFromLocalPath($local_path);
}

function remote_storage_object_key_for_filename(string $filename): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->objectKeyForFilename($filename);
}

function remote_storage_object_key_for_thumbnail(string $filename): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->objectKeyForThumbnail($filename);
}

function remote_storage_guess_content_type(string $path): string {
    return \LitePic\Service\Storage\RemoteStorage::guessContentType($path);
}

function remote_storage_endpoint_host(): string {
    return \LitePic\Service\Storage\RemoteStorage::endpointHost();
}

function remote_storage_endpoint_base(): string {
    return \LitePic\Service\Storage\RemoteStorage::endpointBase();
}

function remote_storage_encoded_key(string $object_key): string {
    return \LitePic\Service\Storage\RemoteStorage::encodeKey($object_key);
}

function remote_storage_request(string $method, string $object_key, ?string $body = null, string $content_type = 'application/octet-stream'): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->objectRequest($method, $object_key, $body, $content_type);
}

function remote_storage_upload_local_file(string $local_path): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->uploadLocalFile($local_path);
}

function remote_storage_delete_object(string $object_key): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->deleteObject($object_key);
}

function remote_storage_sync_file_and_thumbnail(string $filename): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($filename);
}

function remote_storage_delete_file_and_thumbnail(string $filename): void {
    (new \LitePic\Service\Storage\RemoteStorage())->deleteFileAndThumbnail($filename);
}

function remote_storage_bucket_request(string $method, array $query = [], string $body = '', string $content_type = 'application/xml'): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->bucketRequest($method, $query, $body, $content_type);
}

function remote_storage_list_objects(string $prefix = '', string $continuation_token = ''): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->listObjects($prefix, $continuation_token);
}

function remote_storage_delete_all_objects(): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->deleteAllObjects();
}

function remote_storage_test_connection(): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->testConnection();
}

function remote_storage_sync_all_local_images(): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->syncAllLocalImages();
}

function remote_storage_restore_all_to_local(): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->restoreAllToLocal();
}

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

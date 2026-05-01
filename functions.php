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

function write_env_kv(array $updates): bool {
    return \LitePic\Core\Config::write($updates);
}

/**
 * 获取上传目录中所有图片文件
 */
function get_uploaded_images() {
    try {
        return (new \LitePic\Repository\ImageRepository())->listIdentifiers('date-desc');
    } catch (\Throwable $e) {
        \LitePic\Core\Logger::error('Error getting uploaded images', ['error' => $e->getMessage()]);
        return [];
    }
}

function normalize_image_identifier(string $identifier): string {
    return \LitePic\Service\Image\PathService::normalizeIdentifier($identifier);
}

function get_image_identifier_from_path(string $path): ?string {
    return \LitePic\Service\Image\PathService::identifierFromPath($path);
}

function get_img_url($filename) {
    return \LitePic\Service\Image\ImageUrl::forIdentifier((string)$filename);
}

function get_thumbnail_url(string $filename): string {
    return \LitePic\Service\Image\ImageUrl::thumbnailUrl($filename);
}

function remote_storage_credentials_valid(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->credentialsValid();
}

function remote_storage_enabled(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->isEnabled();
}

function remote_storage_config_valid(): bool {
    return (new \LitePic\Service\Storage\RemoteStorage())->isConfigValid();
}

function remote_storage_public_url_for_identifier(string $identifier): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForIdentifier($identifier);
}

function remote_storage_public_url_for_local_path(string $local_path): ?string {
    return (new \LitePic\Service\Storage\RemoteStorage())->publicUrlForLocalPath($local_path);
}

function remote_storage_sync_file_and_thumbnail(string $filename): array {
    return (new \LitePic\Service\Storage\RemoteStorage())->syncFileAndThumbnail($filename);
}

function remote_storage_delete_file_and_thumbnail(string $filename): void {
    (new \LitePic\Service\Storage\RemoteStorage())->deleteFileAndThumbnail($filename);
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

function import_task_has_work(array $options): bool {
    return \LitePic\Repository\ImportQueueRepository::hasWork($options);
}

function import_task_enqueue(string $filename, array $options): bool {
    return (new \LitePic\Service\Importer\Importer())->enqueue($filename, $options);
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
    $info = new \LitePic\Service\Image\ImageInfo($repo);

    $toItems = static function (array $names) use ($info): array {
        $items = [];
        foreach ($names as $name) {
            $row = $info->get((string)$name);
            if ($row === null) continue;
            $items[] = [
                'filename' => (string)$row['filename'],
                'original_name' => (string)($row['original_name'] ?? $row['filename']),
                'url' => (string)$row['url'],
                'thumb_url' => (string)($row['thumb_url'] ?? $row['url']),
                'size' => (int)($row['size'] ?? 0),
                'size_text' => \LitePic\Core\Format::filesize((int)($row['size'] ?? 0)),
                'dimensions' => (string)($row['dimensions'] ?? ''),
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'format' => (string)($row['format'] ?? ''),
                'time' => (int)($row['time'] ?? 0),
                'time_text' => date('Y-m-d H:i', (int)($row['time'] ?? time())),
                'request_count' => (int)($row['request_count'] ?? 0),
            ];
        }
        return $items;
    };

    if ($all) {
        $rows = $repo->listAll($sort, $query);
        $names = array_map(static fn ($r) => (string)$r['filename'], $rows);
        $total = count($names);
        return [
            'items' => $toItems($names),
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
        'items' => $toItems($names),
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

function compress_image_by_mode(string $path, int $quality = 85): array {
    return (new \LitePic\Service\Image\CompressionService())->compress($path, $quality);
}

function can_convert_avif_extension(string $ext): bool {
    return \LitePic\Service\Image\ImageFormat::canConvertAvif($ext);
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

function convert_to_webp($filepath) {
    return (new \LitePic\Service\Image\ConversionService())->toWebp((string)$filepath);
}

function convert_to_avif($filepath) {
    return (new \LitePic\Service\Image\ConversionService())->toAvif((string)$filepath);
}

function create_image_resource($filepath, $mime) {
    return \LitePic\Service\Image\ConversionService::createImageResource((string)$filepath, (string)$mime);
}

function apply_watermark_to_image(string $filename): array {
    return (new \LitePic\Service\Image\WatermarkService())->apply($filename);
}

function hotlink_allowed_hosts(): array {
    return (new \LitePic\Service\Hotlink\HotlinkProtection())->allowedHosts();
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

function ini_size_to_bytes($value): int {
    return \LitePic\Service\Upload\UploadService::iniSizeToBytes($value);
}

function get_php_upload_limit_bytes(): int {
    return \LitePic\Service\Upload\UploadService::phpUploadLimitBytes();
}

function get_effective_upload_max_bytes(): int {
    return (new \LitePic\Service\Upload\UploadService())->maxBytes();
}

function normalize_uploaded_files(array $raw_files): array {
    return \LitePic\Service\Upload\UploadService::normaliseFilesArray($raw_files);
}

function handle_uploaded_files(array $files): array {
    return (new \LitePic\Service\Upload\UploadService())->handle($files);
}

function format_filesize($bytes) {
    return \LitePic\Core\Format::filesize($bytes);
}

function detect_web_server_software(?string $software = null): array {
    return (new \LitePic\Service\Stats\ServerInfo())->webServer($software);
}

function get_server_runtime_metrics(): array {
    return (new \LitePic\Service\Stats\ServerInfo())->runtimeMetrics();
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

/**
 * ============================================
 * 安全加固函数（CSRF、速率限制、MIME校验）
 * ============================================
 */

/**
 * 初始化 Session（用于 CSRF Token 和登录速率限制）
 */
function session_init_safe(): void {
    \LitePic\Core\Session::start();
}

function csrf_token_get(): string {
    return \LitePic\Core\Csrf::token();
}

function csrf_token_verify(?string $token): bool {
    return \LitePic\Core\Csrf::verify($token);
}

function csrf_token_input(): string {
    return \LitePic\Core\Csrf::inputField();
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


function safe_error_message(Throwable $e): string {
    return \LitePic\Core\Response::safeMessage($e);
}

function error_response(string $message, int $errorCode = 400): void {
    \LitePic\Core\Response::error($message, $errorCode);
}

function success_response(array $data): void {
    \LitePic\Core\Response::success($data);
}

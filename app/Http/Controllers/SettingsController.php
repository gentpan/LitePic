<?php
declare(strict_types=1);

namespace LitePic\Http\Controllers;

use LitePic\Core\Config;
use LitePic\Core\Format;
use LitePic\Repository\ApiTokenRepository;
use LitePic\Repository\CompressionKeyRepository;
use LitePic\Service\Hotlink\HotlinkProtection;
use LitePic\Service\Image\ThumbnailService;
use LitePic\Service\Image\WatermarkService;
use LitePic\Service\Importer\Importer;
use LitePic\Service\Stats\ServerInfo;
use LitePic\Service\Storage\RemoteStorage;

/**
 * Handles every POST submission on the /settings page.
 *
 * `dispatch($formAction)` returns a uniform shape:
 *   [
 *     'message'        => string  // human-readable result for the flash banner
 *     'type'           => 'success' | 'error'
 *     'created_token'  => string   // optional — only when create_token returned
 *     'home_bg_url'    => string   // optional — only when background changed
 *     'home_bg_path'   => string   // optional — same
 *     'saved_settings' => array    // optional — current values for the JS to apply
 *   ]
 *
 * Each `handle*()` method covers one form_action and returns the same
 * shape. The page glue that calls dispatch is just a flash-cookie
 * setter and a PRG redirect.
 */
final class SettingsController
{
    private const SUPPORTED_REMOTE_STORAGE_USAGE = ['backup', 'storage'];
    private const SUPPORTED_WATERMARK_TYPES = ['text', 'image'];
    private const SUPPORTED_WATERMARK_POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center'];
    private const SUPPORTED_COMPRESSION_MODES = ['tinypng', 'gd', 'imagemagick'];
    private const SUPPORTED_CONVERT_FORMATS = ['webp', 'avif'];

    /**
     * @return array{message:string,type:string,created_token?:string,home_bg_url?:string,home_bg_path?:string,saved_settings?:array}
     */
    public function dispatch(string $formAction): array
    {
        return match ($formAction) {
            'create_token' => $this->handleCreateToken(),
            'revoke_token' => $this->handleRevokeToken(),
            'add_compression_api' => $this->handleAddCompressionApi(),
            'toggle_compression_api' => $this->handleToggleCompressionApi(),
            'delete_compression_api' => $this->handleDeleteCompressionApi(),
            'save_remote_storage' => $this->handleSaveRemoteStorage(),
            'test_remote_storage' => $this->handleTestRemoteStorage(),
            'scan_import_uploads' => $this->handleScanImportUploads(),
            'process_import_tasks' => $this->handleProcessImportTasks(),
            'generate_all_thumbnails' => $this->handleGenerateAllThumbnails(),
            'sync_remote_storage_all' => $this->handleSyncRemoteStorageAll(),
            'restore_remote_storage_all' => $this->handleRestoreRemoteStorageAll(),
            'purge_remote_storage' => $this->handlePurgeRemoteStorage(),
            'save_settings' => $this->handleSaveSettings(),
            default => ['message' => '', 'type' => 'success'],
        };
    }

    private function handleCreateToken(): array
    {
        $name = trim((string)($_POST['token_name'] ?? ''));
        $created = (new ApiTokenRepository())->createSafely($name);
        if ($created === null) {
            return ['message' => '创建 API Token 失败', 'type' => 'error'];
        }
        return [
            'message' => 'API Token 已创建，请立即复制保存。',
            'type' => 'success',
            'created_token' => $created,
        ];
    }

    private function handleRevokeToken(): array
    {
        $tokenId = trim((string)($_POST['token_id'] ?? ''));
        if ($tokenId === '' || !(new ApiTokenRepository())->revoke($tokenId)) {
            return ['message' => '撤销 Token 失败', 'type' => 'error'];
        }
        return ['message' => 'Token 已撤销', 'type' => 'success'];
    }

    private function handleAddCompressionApi(): array
    {
        $apiKey = trim((string)($_POST['compression_api_key'] ?? ''));
        if (!(new CompressionKeyRepository())->create('', $apiKey)) {
            return ['message' => '添加压缩 API Key 失败', 'type' => 'error'];
        }
        return ['message' => '压缩 API Key 已添加', 'type' => 'success'];
    }

    private function handleToggleCompressionApi(): array
    {
        $id = trim((string)($_POST['compression_api_id'] ?? ''));
        $enable = ((string)($_POST['enable'] ?? '0')) === '1';
        if ($id === '' || !(new CompressionKeyRepository())->setEnabled($id, $enable)) {
            return ['message' => '更新压缩 API 状态失败', 'type' => 'error'];
        }
        return [
            'message' => $enable ? '压缩 API 已启用' : '压缩 API 已禁用',
            'type' => 'success',
        ];
    }

    private function handleDeleteCompressionApi(): array
    {
        $id = trim((string)($_POST['compression_api_id'] ?? ''));
        if ($id === '' || !(new CompressionKeyRepository())->delete($id)) {
            return ['message' => '删除压缩 API Key 失败', 'type' => 'error'];
        }
        return ['message' => '压缩 API Key 已删除', 'type' => 'success'];
    }

    private function handleSaveRemoteStorage(): array
    {
        $usage = $this->resolveRemoteStorageUsage();
        $updated = Config::write(RemoteStorage::envFromPostedForm());
        if (!$updated) {
            return ['message' => '保存 R2/S3 设置失败，请检查 .env 写入权限', 'type' => 'error'];
        }

        $complete = RemoteStorage::postedFormIsComplete();
        if ($complete) {
            $message = $usage === 'storage'
                ? 'R2/S3 设置已保存，云端存储已启用'
                : 'R2/S3 设置已保存，远程备份已启用';
        } else {
            $message = $usage === 'storage'
                ? 'R2/S3 设置已保存；云端存储需要填写公网访问域名和所有必填项'
                : 'R2/S3 设置已保存；必填项未完整，远程备份已停用';
        }
        return [
            'message' => $message,
            'type' => 'success',
            'saved_settings' => ['remote_storage_usage' => $usage],
        ];
    }

    private function handleTestRemoteStorage(): array
    {
        $test = (new RemoteStorage())->testConnection();
        return [
            'message' => empty($test['success']) ? '测试失败' : '测试成功',
            'type' => empty($test['success']) ? 'error' : 'success',
        ];
    }

    private function handleScanImportUploads(): array
    {
        $sourcePath = trim((string)($_POST['scan_source_path'] ?? ''));
        $createThumbnail = self::boolFromPost('scan_create_thumbnail');
        $autoCompress = self::boolFromPost('scan_auto_compress');

        $convertFormat = strtolower(trim((string)($_POST['scan_convert_format'] ?? 'webp')));
        if (!in_array($convertFormat, self::SUPPORTED_CONVERT_FORMATS, true)) {
            $convertFormat = 'webp';
        }

        $hasCombinedConvert = array_key_exists('scan_auto_convert', $_POST);
        $autoConvert = $hasCombinedConvert
            ? self::boolFromPost('scan_auto_convert')
            : (self::boolFromPost('scan_auto_webp') || self::boolFromPost('scan_auto_avif'));
        if (!$hasCombinedConvert) {
            if (self::boolFromPost('scan_auto_avif')) {
                $convertFormat = 'avif';
            } elseif (self::boolFromPost('scan_auto_webp')) {
                $convertFormat = 'webp';
            }
        }

        $autoWebp = $autoConvert && $convertFormat === 'webp';
        $autoAvif = $autoConvert && $convertFormat === 'avif';

        $warnings = [];
        $cap = ServerInfo::compressionCapability();
        if ($autoWebp && empty($cap['webp'])) {
            $autoWebp = false;
            $warnings[] = 'WebP 支持未启用，已跳过导入时自动转 WebP';
        }
        if ($autoAvif && empty($cap['avif'])) {
            $autoAvif = false;
            $warnings[] = 'AVIF 支持未启用，已跳过导入时自动转 AVIF';
        }

        $report = (new Importer())->scanAndImport([
            'create_thumbnail' => $createThumbnail,
            'auto_compress' => $autoCompress,
            'auto_webp' => $autoWebp,
            'auto_avif' => $autoAvif,
            'source_path' => $sourcePath,
        ]);

        $message = sprintf(
            '扫描完成：扫描 %d，导入 %d，重复 %d，失败 %d，导入任务 %d',
            (int)($report['scanned'] ?? 0),
            (int)($report['imported'] ?? 0),
            (int)($report['duplicates'] ?? 0),
            (int)($report['failed'] ?? 0),
            (int)($report['tasks_queued'] ?? 0)
        );
        if ((int)($report['tasks_queued'] ?? 0) > 0) {
            $message .= '；缩略图、压缩、转换等后处理已进入任务队列，请分批处理';
        }
        if (!empty($report['errors']) && is_array($report['errors'])) {
            $message .= '；错误：' . implode(' | ', array_slice($report['errors'], 0, 3));
        }
        if (!empty($warnings)) {
            $message .= '；提示：' . implode('；', $warnings);
        }
        $hasFailure = (int)($report['failed'] ?? 0) > 0 || !empty($warnings);
        return ['message' => $message, 'type' => $hasFailure ? 'error' : 'success'];
    }

    private function handleProcessImportTasks(): array
    {
        $report = (new Importer())->processQueue(8);
        $message = sprintf(
            '导入任务处理完成：处理 %d，成功 %d，失败 %d，剩余 %d，缩略图 %d，压缩 %d，转 WebP %d，转 AVIF %d，水印 %d',
            (int)($report['processed'] ?? 0),
            (int)($report['succeeded'] ?? 0),
            (int)($report['failed'] ?? 0),
            (int)($report['pending'] ?? 0),
            (int)($report['thumb_created'] ?? 0),
            (int)($report['compressed'] ?? 0),
            (int)($report['webp_created'] ?? 0),
            (int)($report['avif_created'] ?? 0),
            (int)($report['watermark_applied'] ?? 0)
        );
        if (!empty($report['errors']) && is_array($report['errors'])) {
            $message .= '；错误：' . implode(' | ', array_slice($report['errors'], 0, 3));
        }
        return [
            'message' => $message,
            'type' => ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success',
        ];
    }

    private function handleGenerateAllThumbnails(): array
    {
        $report = (new ThumbnailService())->generateAll(true);
        $message = sprintf(
            '缩略图生成完成：总计 %d，成功 %d，跳过 %d，失败 %d',
            (int)($report['total'] ?? 0),
            (int)($report['created'] ?? 0),
            (int)($report['skipped'] ?? 0),
            (int)($report['failed'] ?? 0)
        );
        return [
            'message' => $message,
            'type' => ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success',
        ];
    }

    private function handleSyncRemoteStorageAll(): array
    {
        $report = (new RemoteStorage())->syncAllLocalImages();
        return [
            'message' => (string)($report['message'] ?? '远程同步失败'),
            'type' => !empty($report['success']) ? 'success' : 'error',
        ];
    }

    private function handleRestoreRemoteStorageAll(): array
    {
        $report = (new RemoteStorage())->restoreAllToLocal();
        return [
            'message' => (string)($report['message'] ?? '远程恢复失败'),
            'type' => !empty($report['success']) ? 'success' : 'error',
        ];
    }

    private function handlePurgeRemoteStorage(): array
    {
        $result = (new RemoteStorage())->deleteAllObjects();
        return [
            'message' => (string)($result['message'] ?? '远程清理失败'),
            'type' => !empty($result['success']) ? 'success' : 'error',
        ];
    }

    /**
     * The big one — saves the bulk of /settings into .env, .user.ini,
     * and .htaccess. Returns extras the page render needs to refresh
     * client-side state (e.g. updated background URL).
     */
    private function handleSaveSettings(): array
    {
        $warnings = [];
        $notes = [];
        $extras = [];

        // Site identity + upload limits
        $siteName = trim((string)($_POST['site_name'] ?? SITE_NAME));
        $siteDescription = trim((string)($_POST['site_description'] ?? SITE_DESCRIPTION));
        $maxFileSizeMb = max(1, min(50, (int)($_POST['max_file_size_mb'] ?? (int)round(MAX_FILE_SIZE / 1024 / 1024))));
        $allowedTypes = $this->parseAllowedUploadTypes($warnings);
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        $adminApiKey = trim((string)($_POST['admin_api_key'] ?? ADMIN_API_KEY));
        $autoCompressOnUpload = self::boolFromPost('auto_compress_on_upload');

        // Home background image (optional upload)
        $homeBackgroundImage = $this->settleHomeBackground($warnings, $notes, $extras);

        // Format conversion: combined or split radio + checkbox
        [$autoConvertWebp, $autoConvertAvif, $convertPreferredFormat] = $this->resolveConvertSettings($warnings);

        $keepOriginalAfterProcess = self::boolFromPost('keep_original_after_process');

        // Watermark
        [$watermarkEnabled, $watermarkType, $watermarkText, $watermarkPosition,
         $watermarkOpacity, $watermarkFontSize, $watermarkMargin, $watermarkColor,
         $watermarkFontPath, $watermarkImagePath, $watermarkImageWidth,
         $watermarkPanelEnabled, $watermarkPanelOpacity, $watermarkPanelPadding,
         $watermarkPanelRadius] = $this->resolveWatermarkSettings($warnings);

        // Hotlink
        $apacheHotlinkEnabled = self::boolFromPost('apache_hotlink_protection_enabled');
        $hotlinkAllowedDomains = trim((string)($_POST['hotlink_allowed_domains'] ?? implode(',', HOTLINK_ALLOWED_DOMAINS)));
        $hotlinkAllowEmptyReferer = self::boolFromPost('hotlink_allow_empty_referer');

        // Access log stats
        $accessLogStatsEnabled = self::boolFromPost('access_log_stats_enabled');
        $accessLogPaths = trim((string)($_POST['access_log_paths'] ?? implode(',', ACCESS_LOG_PATHS)));
        $accessLogCacheTtl = max(30, min(86400, (int)($_POST['access_log_cache_ttl'] ?? ACCESS_LOG_CACHE_TTL)));
        $accessLogMaxMb = max(1, min(500, (int)($_POST['access_log_max_mb'] ?? (int)ceil(ACCESS_LOG_MAX_BYTES / 1024 / 1024))));
        $accessLogMaxBytes = $accessLogMaxMb * 1024 * 1024;

        // Remote storage usage warning (fields are saved by the dedicated handler)
        $remoteStorageUsage = $this->resolveRemoteStorageUsage();
        if ($remoteStorageUsage === 'storage'
            && trim((string)($_POST['s3_public_base_url'] ?? S3_PUBLIC_BASE_URL)) === ''
        ) {
            $warnings[] = '云端存储模式需要填写公网访问域名，否则图片链接会回退为本站本地地址';
        }

        // Mutual exclusion: convert wins over compress
        if (($autoConvertWebp || $autoConvertAvif) && $autoCompressOnUpload) {
            $autoCompressOnUpload = false;
        }

        $compressionMode = trim((string)($_POST['compression_mode'] ?? COMPRESSION_MODE));
        if (!in_array($compressionMode, self::SUPPORTED_COMPRESSION_MODES, true)) {
            $compressionMode = 'imagemagick';
        }

        // Persist everything that goes into .env in one shot
        $envWritten = Config::write(array_merge([
            'SITE_NAME' => Format::envQuote($siteName),
            'SITE_DESCRIPTION' => Format::envQuote($siteDescription),
            'MAX_FILE_SIZE_MB' => (string)$maxFileSizeMb,
            'UPLOAD_ALLOWED_TYPES' => implode(',', $allowedTypes),
            'COOKIE_SECURE' => $isHttps ? 'true' : 'false',
            'ADMIN_API_KEY' => Format::envQuote($adminApiKey),
            'HOME_BACKGROUND_IMAGE' => Format::envQuote($homeBackgroundImage),
            'AUTO_COMPRESS_ON_UPLOAD' => $autoCompressOnUpload ? 'true' : 'false',
            'AUTO_CONVERT_WEBP_ON_UPLOAD' => $autoConvertWebp ? 'true' : 'false',
            'AUTO_CONVERT_AVIF_ON_UPLOAD' => $autoConvertAvif ? 'true' : 'false',
            'CONVERT_PREFERRED_FORMAT' => $convertPreferredFormat,
            'KEEP_ORIGINAL_AFTER_PROCESS' => $keepOriginalAfterProcess ? 'true' : 'false',
            'COMPRESSION_MODE' => $compressionMode,
            'WATERMARK_ENABLED' => $watermarkEnabled ? 'true' : 'false',
            'WATERMARK_TYPE' => $watermarkType,
            'WATERMARK_TEXT' => Format::envQuote($watermarkText),
            'WATERMARK_POSITION' => $watermarkPosition,
            'WATERMARK_OPACITY' => (string)$watermarkOpacity,
            'WATERMARK_FONT_SIZE' => (string)$watermarkFontSize,
            'WATERMARK_MARGIN' => (string)$watermarkMargin,
            'WATERMARK_COLOR' => Format::envQuote($watermarkColor),
            'WATERMARK_FONT_PATH' => Format::envQuote($watermarkFontPath),
            'WATERMARK_IMAGE_PATH' => Format::envQuote($watermarkImagePath),
            'WATERMARK_IMAGE_WIDTH' => (string)$watermarkImageWidth,
            'WATERMARK_PANEL_ENABLED' => $watermarkPanelEnabled ? 'true' : 'false',
            'WATERMARK_PANEL_OPACITY' => (string)$watermarkPanelOpacity,
            'WATERMARK_PANEL_PADDING' => (string)$watermarkPanelPadding,
            'WATERMARK_PANEL_RADIUS' => (string)$watermarkPanelRadius,
            // HOTLINK_PROTECTION_ENABLED is .htaccess-driven and not flipped here
            'HOTLINK_PROTECTION_ENABLED' => 'false',
            'HOTLINK_ALLOWED_DOMAINS' => Format::envQuote($hotlinkAllowedDomains),
            'HOTLINK_ALLOW_EMPTY_REFERER' => $hotlinkAllowEmptyReferer ? 'true' : 'false',
            'ACCESS_LOG_STATS_ENABLED' => $accessLogStatsEnabled ? 'true' : 'false',
            'ACCESS_LOG_PATHS' => Format::envQuote($accessLogPaths),
            'ACCESS_LOG_CACHE_TTL' => (string)$accessLogCacheTtl,
            'ACCESS_LOG_MAX_BYTES' => (string)$accessLogMaxBytes,
        ], RemoteStorage::envFromPostedForm()));

        $iniPath = APP_ROOT . '/.user.ini';
        // post_max_size has to be a touch bigger than upload_max_filesize so multipart
        // headers don't push us over the limit.
        $postMaxSizeMb = min(52, $maxFileSizeMb + 2);
        $iniWritten = self::writeUserIni($iniPath, [
            'open_basedir' => ServerInfo::openBasedirValue($iniPath),
            'upload_max_filesize' => $maxFileSizeMb . 'M',
            'post_max_size' => $postMaxSizeMb . 'M',
            'max_file_uploads' => '50',
            'memory_limit' => '256M',
        ]);

        $htaccessPath = APP_ROOT . '/.htaccess';
        $htaccessWritten = HotlinkProtection::writeApacheRules(
            $htaccessPath,
            $apacheHotlinkEnabled,
            $hotlinkAllowedDomains,
            $hotlinkAllowEmptyReferer
        );
        if (!$htaccessWritten) {
            $warnings[] = $apacheHotlinkEnabled
                ? '防盗链规则写入 .htaccess 失败，请检查站点根目录写入权限'
                : '防盗链规则从 .htaccess 移除失败，请检查站点根目录写入权限';
        } elseif ($apacheHotlinkEnabled) {
            $webServer = (new ServerInfo())->webServer();
            if (empty($webServer['uses_htaccess'])) {
                $warnings[] = sprintf(
                    '当前检测为 %s，.htaccess 通常不会生效，请按使用说明添加对应服务器规则',
                    (string)$webServer['label']
                );
            }
        }

        if (!$envWritten) {
            $message = '写入 .env 失败，请检查文件权限';
            $type = 'error';
        } elseif (!$iniWritten) {
            $message = '设置已写入 .env，但写入 .user.ini 失败，请检查文件权限';
            $type = 'error';
        } else {
            $details = array_merge($notes, $warnings);
            $message = empty($details) ? '保存成功' : '保存成功；' . implode('；', $details);
            $type = empty($warnings) ? 'success' : 'error';
        }

        $extras['saved_settings'] = [
            'auto_compress_on_upload' => $autoCompressOnUpload,
            'auto_convert_on_upload' => $autoConvertWebp || $autoConvertAvif,
            'convert_preferred_format' => $convertPreferredFormat,
            'upload_allowed_types' => $allowedTypes,
            'remote_storage_usage' => $remoteStorageUsage,
            'keep_original_after_process' => $keepOriginalAfterProcess,
            'watermark_enabled' => $watermarkEnabled,
            'watermark_type' => $watermarkType,
            'apache_hotlink_protection_enabled' => HotlinkProtection::apacheRulesEnabled($htaccessPath),
            'hotlink_allow_empty_referer' => $hotlinkAllowEmptyReferer,
            'watermark_panel_enabled' => $watermarkPanelEnabled,
            'access_log_stats_enabled' => $accessLogStatsEnabled,
        ];

        return ['message' => $message, 'type' => $type] + $extras;
    }

    /**
     * @return array<int, string>
     */
    private function parseAllowedUploadTypes(array &$warnings): array
    {
        $raw = $_POST['upload_allowed_types'] ?? ALLOWED_UPLOAD_TYPES;
        if (!is_array($raw)) {
            $raw = explode(',', (string)$raw);
        }
        $cleaned = [];
        foreach ($raw as $type) {
            $type = strtolower(ltrim(trim((string)$type), '.'));
            if ($type !== '' && in_array($type, SUPPORTED_IMAGE_TYPES, true) && !in_array($type, $cleaned, true)) {
                $cleaned[] = $type;
            }
        }
        if (empty($cleaned)) {
            $cleaned = ALLOWED_UPLOAD_TYPES;
            if (empty($cleaned)) {
                $cleaned = SUPPORTED_IMAGE_TYPES;
            }
            $warnings[] = '至少需要保留一种允许上传格式，已保留原配置';
        }
        return $cleaned;
    }

    private function settleHomeBackground(array &$warnings, array &$notes, array &$extras): string
    {
        $defaultPath = defined('SETTINGS_DEFAULT_HOME_BACKGROUND')
            ? SETTINGS_DEFAULT_HOME_BACKGROUND
            : '/static/images/background.jpg';
        $current = HOME_BACKGROUND_IMAGE;

        if (self::boolFromPost('home_background_reset')) {
            $extras['home_bg_url'] = self::homeBackgroundUrl($defaultPath);
            $extras['home_bg_path'] = ltrim($defaultPath, '/');
            $notes[] = '首页背景图已恢复默认';
            return $defaultPath;
        }

        $error = null;
        $uploaded = self::storeHomeBackgroundUpload('home_background_upload', $error);
        if ($uploaded !== null) {
            $extras['home_bg_url'] = self::homeBackgroundUrl($uploaded);
            $extras['home_bg_path'] = ltrim($uploaded, '/');
            $notes[] = '首页背景图已更新';
            return $uploaded;
        }
        if ($error !== null) {
            $warnings[] = '首页背景图上传失败：' . $error;
        }
        return $current;
    }

    /**
     * @return array{0:bool,1:bool,2:string} [autoWebp, autoAvif, preferredFormat]
     */
    private function resolveConvertSettings(array &$warnings): array
    {
        $cap = ServerInfo::compressionCapability();
        $preferred = trim((string)($_POST['convert_preferred_format'] ?? CONVERT_PREFERRED_FORMAT));
        if (!in_array($preferred, self::SUPPORTED_CONVERT_FORMATS, true)) {
            $preferred = 'webp';
        }
        $hasCombined = array_key_exists('auto_convert_on_upload', $_POST);
        $autoConvert = $hasCombined
            ? self::boolFromPost('auto_convert_on_upload')
            : (self::boolFromPost('auto_convert_webp_on_upload') || self::boolFromPost('auto_convert_avif_on_upload'));
        if (!$hasCombined) {
            if (self::boolFromPost('auto_convert_avif_on_upload')) {
                $preferred = 'avif';
            } elseif (self::boolFromPost('auto_convert_webp_on_upload')) {
                $preferred = 'webp';
            }
        }
        $autoWebp = $autoConvert && $preferred === 'webp';
        $autoAvif = $autoConvert && $preferred === 'avif';
        if ($autoWebp && empty($cap['webp'])) {
            $autoWebp = false;
            $warnings[] = 'WebP 支持未启用，已关闭上传后自动转换 WebP';
        }
        if ($autoAvif && empty($cap['avif'])) {
            $autoAvif = false;
            $warnings[] = 'AVIF 支持未启用，已关闭上传后自动转换 AVIF';
        }
        return [$autoWebp, $autoAvif, $preferred];
    }

    /**
     * @return array{0:bool,1:string,2:string,3:string,4:int,5:int,6:int,7:string,8:string,9:string,10:int,11:bool,12:int,13:int,14:int}
     */
    private function resolveWatermarkSettings(array &$warnings): array
    {
        $enabled = self::boolFromPost('watermark_enabled');
        $type = strtolower(trim((string)($_POST['watermark_type'] ?? WATERMARK_TYPE)));
        if (!in_array($type, self::SUPPORTED_WATERMARK_TYPES, true)) $type = 'text';
        $text = trim((string)($_POST['watermark_text'] ?? WATERMARK_TEXT));
        $position = trim((string)($_POST['watermark_position'] ?? WATERMARK_POSITION));
        if (!in_array($position, self::SUPPORTED_WATERMARK_POSITIONS, true)) $position = 'bottom-right';
        $opacity = max(1, min(100, (int)($_POST['watermark_opacity'] ?? WATERMARK_OPACITY)));
        $fontSize = max(8, min(72, (int)($_POST['watermark_font_size'] ?? WATERMARK_FONT_SIZE)));
        $margin = max(0, min(240, (int)($_POST['watermark_margin'] ?? WATERMARK_MARGIN)));
        $color = trim((string)($_POST['watermark_color'] ?? WATERMARK_COLOR));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#ffffff';

        $fontPath = trim((string)($_POST['watermark_font_path'] ?? WATERMARK_FONT_PATH));
        $imagePath = trim((string)($_POST['watermark_image_path'] ?? WATERMARK_IMAGE_PATH));
        $imageWidth = max(24, min(800, (int)($_POST['watermark_image_width'] ?? WATERMARK_IMAGE_WIDTH)));

        // Optional uploads: font (.ttf/.otf) or PNG image
        $fontError = null;
        $uploadedFont = WatermarkService::storeUploadedAsset('watermark_font_upload', ['ttf', 'otf'], $fontError);
        if ($uploadedFont !== null) {
            $fontPath = $uploadedFont;
        } elseif ($fontError !== null) {
            $warnings[] = '字体上传失败：' . $fontError;
        }
        $imageError = null;
        $uploadedImage = WatermarkService::storeUploadedAsset('watermark_image_upload', ['png'], $imageError);
        if ($uploadedImage !== null) {
            $imagePath = $uploadedImage;
        } elseif ($imageError !== null) {
            $warnings[] = 'PNG 水印上传失败：' . $imageError;
        }
        if (self::boolFromPost('watermark_image_clear')) {
            $imagePath = '';
        }

        $panelEnabled = self::boolFromPost('watermark_panel_enabled');
        $panelOpacity = max(1, min(100, (int)($_POST['watermark_panel_opacity'] ?? WATERMARK_PANEL_OPACITY)));
        $panelPadding = max(0, min(80, (int)($_POST['watermark_panel_padding'] ?? WATERMARK_PANEL_PADDING)));
        $panelRadius = max(0, min(80, (int)($_POST['watermark_panel_radius'] ?? WATERMARK_PANEL_RADIUS)));

        // Sanity gates
        if ($enabled && $type === 'text' && $text === '') {
            $enabled = false;
            $warnings[] = '水印文字为空，已关闭自动水印';
        }
        if ($enabled && $type === 'text' && preg_match('/[^\x20-\x7E]/', $text) && $fontPath === '') {
            $warnings[] = '水印包含中文或其他非 ASCII 字符，建议配置字体文件路径，否则会跳过写入';
        }
        if ($imagePath !== '' && (!is_file($imagePath) || strtolower((string)pathinfo($imagePath, PATHINFO_EXTENSION)) !== 'png')) {
            $imagePath = '';
            $warnings[] = 'PNG 水印路径无效，已清空图片水印';
        }
        if ($enabled && $type === 'image' && $imagePath === '') {
            $enabled = false;
            $warnings[] = '图片水印未配置 PNG 路径，已关闭自动水印';
        }

        return [
            $enabled, $type, $text, $position,
            $opacity, $fontSize, $margin, $color,
            $fontPath, $imagePath, $imageWidth,
            $panelEnabled, $panelOpacity, $panelPadding, $panelRadius,
        ];
    }

    private function resolveRemoteStorageUsage(): string
    {
        $usage = strtolower(trim((string)($_POST['remote_storage_usage'] ?? REMOTE_STORAGE_USAGE)));
        return in_array($usage, self::SUPPORTED_REMOTE_STORAGE_USAGE, true) ? $usage : 'backup';
    }

    public static function boolFromPost(string $key): bool
    {
        return isset($_POST[$key]) && $_POST[$key] === '1';
    }

    /**
     * Equivalent of Config::write() but for `.user.ini` (preserves
     * non-tracked keys; rewrites only the ones we care about).
     */
    public static function writeUserIni(string $iniPath, array $updates): bool
    {
        $lines = [];
        if (is_file($iniPath)) {
            $existing = file($iniPath, FILE_IGNORE_NEW_LINES);
            if ($existing !== false) $lines = $existing;
        }
        $remaining = $updates;
        foreach ($lines as $i => $line) {
            if (!is_string($line)) continue;
            if (!preg_match('/^\s*([a-zA-Z0-9_.]+)\s*=/', $line, $m)) continue;
            $key = trim((string)$m[1]);
            if (!array_key_exists($key, $remaining)) continue;
            $lines[$i] = $key . '=' . (string)$remaining[$key];
            unset($remaining[$key]);
        }
        if (!empty($remaining)) {
            if (!empty($lines) && trim((string)end($lines)) !== '') $lines[] = '';
            foreach ($remaining as $key => $value) {
                $lines[] = $key . '=' . (string)$value;
            }
        }
        $content = implode(PHP_EOL, $lines);
        if ($content !== '') $content .= PHP_EOL;
        return file_put_contents($iniPath, $content, LOCK_EX) !== false;
    }

    /**
     * Append a cache-busting `?v=` query so a new background image
     * doesn't get served from the browser cache.
     */
    public static function homeBackgroundUrl(string $webPath): string
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
        $path = parse_url($webPath, PHP_URL_PATH);
        $file = is_string($path) && str_starts_with($path, '/') ? $appRoot . $path : '';
        $version = $file !== '' && is_file($file) ? (string)filemtime($file) : (string)time();
        return $webPath . (str_contains($webPath, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    /**
     * Receive an uploaded JPG/PNG/WebP background image, normalise it
     * to JPEG, and put it under static/images/. Returns the new web
     * path, or null if no usable upload arrived (with `$error` set if
     * the upload was attempted but rejected).
     */
    public static function storeHomeBackgroundUpload(string $field, ?string &$error = null): ?string
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $file = $_FILES[$field];
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) return null;
        if ($errorCode !== UPLOAD_ERR_OK) {
            $error = '上传文件失败，请检查 PHP 上传限制';
            return null;
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = '上传临时文件无效';
            return null;
        }
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $error = '首页背景图仅支持 JPG/JPEG/PNG/WebP';
            return null;
        }

        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
        $dir = $appRoot . '/static/images';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = '背景图目录不可写';
            return null;
        }
        if (!is_writable($dir)) {
            $error = '背景图目录不可写';
            return null;
        }

        $filename = 'background-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $target = $dir . '/' . $filename;
        $temp = $dir . '/.' . $filename . '.tmp';

        if ($mime === 'image/jpeg') {
            if (!move_uploaded_file($tmp, $temp)) {
                $error = '写入首页背景失败';
                return null;
            }
        } else {
            if (!extension_loaded('gd')) {
                $error = 'PNG/WebP 转 JPG 需要启用 GD；请改传 JPG/JPEG';
                return null;
            }
            $createFn = $mime === 'image/png' ? 'imagecreatefrompng' : 'imagecreatefromwebp';
            if (!function_exists($createFn)) {
                $error = '当前 PHP GD 不支持该图片格式；请改传 JPG/JPEG';
                return null;
            }
            $source = @$createFn($tmp);
            if (!$source) {
                $error = '读取背景图失败';
                return null;
            }
            $w = imagesx($source);
            $h = imagesy($source);
            $canvas = imagecreatetruecolor($w, $h);
            if (!$canvas) {
                imagedestroy($source);
                $error = '处理背景图失败';
                return null;
            }
            $fill = imagecolorallocate($canvas, 12, 12, 12);
            imagefill($canvas, 0, 0, $fill);
            imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
            $saved = imagejpeg($canvas, $temp, 90);
            imagedestroy($canvas);
            imagedestroy($source);
            if (!$saved) {
                $error = '转换背景图失败';
                return null;
            }
        }

        if (!rename($temp, $target)) {
            @unlink($temp);
            $error = '写入首页背景失败';
            return null;
        }
        @chmod($target, 0644);
        clearstatcache(true, $target);
        return '/static/images/' . $filename;
    }

    public static function isAjaxRequest(): bool
    {
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');
    }
}

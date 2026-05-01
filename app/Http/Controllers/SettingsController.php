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
    private const SUPPORTED_CONVERSION_ENGINES = ['auto', 'imagick', 'gd'];
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
        // ADMIN_API_KEY 已不再随主设置表单提交（改由 /api/auth.php 的
        // change_password 路径单独保存），这里只读取当前值好把它再写一次回 DB
        // 即可，避免别的字段保存时把已有密码覆盖成空。
        $adminApiKey = (string)ADMIN_API_KEY;
        $autoCompressOnUpload = self::boolFromPost('auto_compress_on_upload');

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

        // Image view counter (PHP-based — no Web 服务器 access.log dependency)
        $imageViewCounterEnabled = self::boolFromPost('image_view_counter_enabled');

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
        $conversionEngine = strtolower(trim((string)($_POST['conversion_engine'] ?? (defined('CONVERSION_ENGINE') ? CONVERSION_ENGINE : 'auto'))));
        if (!in_array($conversionEngine, self::SUPPORTED_CONVERSION_ENGINES, true)) {
            $conversionEngine = 'auto';
        }
        // URL_PREFIX — 自由文本前缀（自动规范化为小写、补 /、剔除非法字符）。
        // 用户可填 /uploads/、/、/i/、/img/、/photo/、/p/、/foo-bar/ 等任意 ASCII 单词。
        $urlPrefixRaw = trim((string)($_POST['url_prefix'] ?? (defined('URL_PREFIX') ? URL_PREFIX : '/uploads/')));
        $urlPrefix = self::normalizeUrlPrefix($urlPrefixRaw);
        if ($urlPrefix === '') {
            $warnings[] = '图片链接前缀格式无效，已保留原配置（必须以 / 开头和结尾，仅允许小写字母数字 _ -，不能跟系统路径冲突）';
            $urlPrefix = defined('URL_PREFIX') ? URL_PREFIX : '/uploads/';
        }

        // Persist everything that goes into .env in one shot
        $envWritten = Config::write(array_merge([
            'SITE_NAME' => Format::envQuote($siteName),
            'SITE_DESCRIPTION' => Format::envQuote($siteDescription),
            'MAX_FILE_SIZE_MB' => (string)$maxFileSizeMb,
            'UPLOAD_ALLOWED_TYPES' => implode(',', $allowedTypes),
            'COOKIE_SECURE' => $isHttps ? 'true' : 'false',
            'ADMIN_API_KEY' => Format::envQuote($adminApiKey),
            'AUTO_COMPRESS_ON_UPLOAD' => $autoCompressOnUpload ? 'true' : 'false',
            'AUTO_CONVERT_WEBP_ON_UPLOAD' => $autoConvertWebp ? 'true' : 'false',
            'AUTO_CONVERT_AVIF_ON_UPLOAD' => $autoConvertAvif ? 'true' : 'false',
            'CONVERT_PREFERRED_FORMAT' => $convertPreferredFormat,
            'KEEP_ORIGINAL_AFTER_PROCESS' => $keepOriginalAfterProcess ? 'true' : 'false',
            'COMPRESSION_MODE' => $compressionMode,
            'CONVERSION_ENGINE' => $conversionEngine,
            'URL_PREFIX' => $urlPrefix,
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
            'IMAGE_VIEW_COUNTER_ENABLED' => $imageViewCounterEnabled ? 'true' : 'false',
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
            // 详细诊断：判断到底是哪一种权限问题，给用户明确的修复方法
            $diag = '';
            if (!is_file($htaccessPath)) {
                $diag = '（.htaccess 文件不存在）';
            } elseif (!is_writable($htaccessPath)) {
                $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($htaccessPath))['name'] ?? '?') : (string)fileowner($htaccessPath);
                $current = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'php';
                $perms = substr(sprintf('%o', fileperms($htaccessPath)), -3);
                $diag = sprintf('（文件 owner=%s，当前 PHP user=%s，perms=%s — 修复：`chmod 666 .htaccess` 或 `chown %s .htaccess`）', $owner, $current, $perms, $current);
            }
            $warnings[] = ($apacheHotlinkEnabled
                ? '防盗链规则写入 .htaccess 失败'
                : '防盗链规则从 .htaccess 移除失败') . $diag;
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
            'image_view_counter_enabled' => $imageViewCounterEnabled,
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

        // 任何会被服务端 / Apache / Nginx 当成可执行 / 模板 / 配置 / 脚本
        // 处理的扩展名都拉黑（防止用户脚滑写错把 .php 当图片格式加进白名单）。
        // 即使 MIME 校验也会再拦一道，这里属于"双保险"。
        $blacklist = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht',
            'asp', 'aspx', 'jsp', 'jspx',
            'sh', 'bash', 'zsh', 'cgi', 'pl', 'py', 'rb',
            'exe', 'bat', 'cmd', 'com', 'msi', 'dll',
            'js', 'mjs', 'html', 'htm', 'xhtml',
            'htaccess', 'ini', 'env', 'conf',
        ];

        $cleaned = [];
        $rejected = [];
        foreach ($raw as $type) {
            // 用户可以自填扩展名（heic / jxl / raw / dng 等不在 SUPPORTED 列表里
            // 的也允许），后端只做格式合法性 sanitize：
            //   • 去掉前导 . / 空白
            //   • 只接受字母 + 数字（任意 unicode 字母会被去掉，避免奇怪后缀）
            //   • 长度 1–10 个字符
            //   • 转小写
            //   • 黑名单拒绝（见 $blacklist）
            // 内容是不是真的图片由 UploadService::validateMime 在上传时把关。
            $type = strtolower(ltrim(trim((string)$type), '.'));
            $type = preg_replace('/[^a-z0-9]/', '', $type) ?? '';
            if ($type === '' || strlen($type) > 10) continue;
            if (in_array($type, $blacklist, true)) {
                $rejected[] = $type;
                continue;
            }
            if (!in_array($type, $cleaned, true)) {
                $cleaned[] = $type;
            }
        }

        if (!empty($rejected)) {
            $warnings[] = '已拒绝可执行 / 脚本类扩展名：.' . implode(', .', array_unique($rejected));
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
     * Sanitise + normalise a user-entered URL prefix.
     *
     * Rules:
     *   • Must start AND end with '/'
     *   • At most one segment between, only [a-z0-9_-]
     *   • Reserved words (api / static / assets / data / logs / settings /
     *     gallery / upload / docs / stats) rejected — would collide with
     *     framework routes
     *   • Empty input or '/' → returns '/' (root, valid)
     *   • Returns '' (empty string) on completely invalid input so caller
     *     can warn user and revert to previous setting.
     */
    public static function normalizeUrlPrefix(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '/') return '/';
        // Lowercase + drop non-allowed chars (keep only / a-z 0-9 _ -)
        $cleaned = preg_replace('#[^a-z0-9_/\-]#', '', strtolower($raw));
        if ($cleaned === null || $cleaned === '') return '';
        // Ensure leading / + trailing /
        if ($cleaned[0] !== '/') $cleaned = '/' . $cleaned;
        if (substr($cleaned, -1) !== '/') $cleaned .= '/';
        // Final shape: /<word>/   (word optional → root '/' allowed)
        if (!preg_match('#^/([a-z0-9][a-z0-9_-]*/)?$#', $cleaned)) return '';
        // Reserved words must not be reused as image prefix
        $reserved = ['api/', 'static/', 'assets/', 'data/', 'logs/', 'settings/', 'gallery/', 'upload/', 'docs/', 'stats/'];
        if (in_array(substr($cleaned, 1), $reserved, true)) return '';
        return $cleaned;
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

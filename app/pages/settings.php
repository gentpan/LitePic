<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}


if (!is_admin()) {
    header('Location: /upload');
    exit;
}

const SETTINGS_FLASH_COOKIE = 'settings_flash_once';
const SETTINGS_FLASH_TTL = 120;

$page_title = '系统设置';
$message = '';
$message_type = 'success';
$created_token = '';
if (!empty($_COOKIE[SETTINGS_FLASH_COOKIE])) {
    $raw = base64_decode((string)$_COOKIE[SETTINGS_FLASH_COOKIE], true);
    if (is_string($raw) && $raw !== '') {
        $flash = json_decode($raw, true);
        if (is_array($flash)) {
            $message = trim((string)($flash['message'] ?? ''));
            $message_type = ((string)($flash['type'] ?? 'success') === 'error') ? 'error' : 'success';
            $created_token = trim((string)($flash['created_token'] ?? ''));
        }
    }
    // 读取后立即清理，确保只显示一次
    setcookie(SETTINGS_FLASH_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bool_from_post(string $key): bool {
    return isset($_POST[$key]) && $_POST[$key] === '1';
}

function env_encode_value(string $value): string {
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
}

function write_env_values(string $env_path, array $updates): bool {
    $lines = [];
    if (is_file($env_path)) {
        $existing = file($env_path, FILE_IGNORE_NEW_LINES);
        if ($existing !== false) {
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

function write_user_ini_values(string $ini_path, array $updates): bool {
    $lines = [];
    if (is_file($ini_path)) {
        $existing = file($ini_path, FILE_IGNORE_NEW_LINES);
        if ($existing !== false) {
            $lines = $existing;
        }
    }

    $remaining = $updates;
    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }
        if (!preg_match('/^\s*([a-zA-Z0-9_.]+)\s*=/', $line, $matches)) {
            continue;
        }
        $key = trim((string)$matches[1]);
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

    return file_put_contents($ini_path, $content, LOCK_EX) !== false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form_action = (string)($_POST['form_action'] ?? 'save_settings');

    if ($form_action === 'create_token') {
        $token_name = trim((string)($_POST['token_name'] ?? ''));
        $created = create_managed_api_token($token_name);
        if ($created === null) {
            $message = '创建 API Token 失败';
            $message_type = 'error';
        } else {
            $message = 'API Token 已创建，请立即复制保存。';
            $message_type = 'success';
            $created_token = $created;
        }
    } elseif ($form_action === 'revoke_token') {
        $token_id = trim((string)($_POST['token_id'] ?? ''));
        if ($token_id === '' || !revoke_managed_api_token($token_id)) {
            $message = '撤销 Token 失败';
            $message_type = 'error';
        } else {
            $message = 'Token 已撤销';
            $message_type = 'success';
        }
    } elseif ($form_action === 'add_compression_api') {
        $api_name = trim((string)($_POST['compression_api_name'] ?? ''));
        $api_key = trim((string)($_POST['compression_api_key'] ?? ''));
        if (!add_compression_api_key($api_name, $api_key)) {
            $message = '添加压缩 API Key 失败';
            $message_type = 'error';
        } else {
            $message = '压缩 API Key 已添加';
            $message_type = 'success';
        }
    } elseif ($form_action === 'toggle_compression_api') {
        $api_id = trim((string)($_POST['compression_api_id'] ?? ''));
        $enable = ((string)($_POST['enable'] ?? '0')) === '1';
        if ($api_id === '' || !set_compression_api_enabled($api_id, $enable)) {
            $message = '更新压缩 API 状态失败';
            $message_type = 'error';
        } else {
            $message = $enable ? '压缩 API 已启用' : '压缩 API 已禁用';
            $message_type = 'success';
        }
    } elseif ($form_action === 'delete_compression_api') {
        $api_id = trim((string)($_POST['compression_api_id'] ?? ''));
        if ($api_id === '' || !delete_compression_api_key($api_id)) {
            $message = '删除压缩 API Key 失败';
            $message_type = 'error';
        } else {
            $message = '压缩 API Key 已删除';
            $message_type = 'success';
        }
    } elseif ($form_action === 'test_remote_storage') {
        $test = remote_storage_test_connection();
        if (!empty($test['success'])) {
            $message = '测试成功';
            $message_type = 'success';
        } else {
            $message = '测试失败';
            $message_type = 'error';
        }
    } elseif ($form_action === 'scan_import_uploads') {
        $scan_create_thumbnail = bool_from_post('scan_create_thumbnail');
        $scan_auto_compress = bool_from_post('scan_auto_compress');
        $scan_auto_webp = bool_from_post('scan_auto_webp');
        if ($scan_auto_webp && $scan_auto_compress) {
            $scan_auto_compress = false;
        }
        $report = scan_and_import_uploads([
            'create_thumbnail' => $scan_create_thumbnail,
            'auto_compress' => $scan_auto_compress,
            'auto_webp' => $scan_auto_webp,
        ]);
        $message = sprintf(
            '扫描完成：扫描 %d，导入 %d，重复 %d，失败 %d，缩略图 %d，压缩 %d，转 WebP %d，跳过压缩 %d，跳过 WebP %d',
            (int)($report['scanned'] ?? 0),
            (int)($report['imported'] ?? 0),
            (int)($report['duplicates'] ?? 0),
            (int)($report['failed'] ?? 0),
            (int)($report['thumb_created'] ?? 0),
            (int)($report['compressed'] ?? 0),
            (int)($report['webp_created'] ?? 0),
            (int)($report['skip_compress'] ?? 0),
            (int)($report['skip_webp'] ?? 0)
        );
        if (!empty($report['errors']) && is_array($report['errors'])) {
            $message .= '；错误：' . implode(' | ', array_slice($report['errors'], 0, 3));
        }
        $message_type = ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success';
    } elseif ($form_action === 'generate_all_thumbnails') {
        $report = generate_all_thumbnails(true);
        $message = sprintf(
            '缩略图生成完成：总计 %d，成功 %d，跳过 %d，失败 %d',
            (int)($report['total'] ?? 0),
            (int)($report['created'] ?? 0),
            (int)($report['skipped'] ?? 0),
            (int)($report['failed'] ?? 0)
        );
        $message_type = ((int)($report['failed'] ?? 0) > 0) ? 'error' : 'success';
    } elseif ($form_action === 'sync_remote_storage_all') {
        $report = remote_storage_sync_all_local_images();
        $message = (string)($report['message'] ?? '远程同步失败');
        $message_type = !empty($report['success']) ? 'success' : 'error';
    } elseif ($form_action === 'restore_remote_storage_all') {
        $report = remote_storage_restore_all_to_local();
        $message = (string)($report['message'] ?? '远程恢复失败');
        $message_type = !empty($report['success']) ? 'success' : 'error';
    } elseif ($form_action === 'purge_remote_storage') {
        $result = remote_storage_delete_all_objects();
        $message = (string)($result['message'] ?? '远程清理失败');
        $message_type = !empty($result['success']) ? 'success' : 'error';
    } else {
        $site_name = trim((string)($_POST['site_name'] ?? SITE_NAME));
        $site_description = trim((string)($_POST['site_description'] ?? SITE_DESCRIPTION));
        $max_file_size_mb = max(1, min(50, (int)($_POST['max_file_size_mb'] ?? (int)round(MAX_FILE_SIZE / 1024 / 1024))));
        $is_https = (
            (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        $admin_api_key = trim((string)($_POST['admin_api_key'] ?? ADMIN_API_KEY));
        $auto_compress_on_upload = bool_from_post('auto_compress_on_upload');
        $auto_convert_webp_on_upload = bool_from_post('auto_convert_webp_on_upload');
        // 互斥策略：开启自动转 WebP 时，自动压缩强制关闭，避免流程冲突
        if ($auto_convert_webp_on_upload && $auto_compress_on_upload) {
            $auto_compress_on_upload = false;
        }
        $compression_mode = trim((string)($_POST['compression_mode'] ?? COMPRESSION_MODE));
        $allowed_modes = ['hybrid', 'local', 'imagemagick', 'gd', 'tinypng'];
        if (!in_array($compression_mode, $allowed_modes, true)) {
            $compression_mode = 'hybrid';
        }
        $remote_storage_mode = strtolower(trim((string)($_POST['remote_storage_mode'] ?? REMOTE_STORAGE_MODE)));
        $allowed_storage_modes = ['off', 'sync', 'backup'];
        if (!in_array($remote_storage_mode, $allowed_storage_modes, true)) {
            $remote_storage_mode = 'off';
        }
        $s3_provider = strtolower(trim((string)($_POST['s3_provider'] ?? S3_PROVIDER)));
        if (!in_array($s3_provider, ['r2', 's3'], true)) {
            $s3_provider = 'r2';
        }
        $s3_bucket = trim((string)($_POST['s3_bucket'] ?? S3_BUCKET));
        $s3_region = trim((string)($_POST['s3_region'] ?? S3_REGION));
        $s3_endpoint = trim((string)($_POST['s3_endpoint'] ?? S3_ENDPOINT));
        $s3_key = trim((string)($_POST['s3_key'] ?? S3_KEY));
        $s3_secret = trim((string)($_POST['s3_secret'] ?? S3_SECRET));
        $s3_path_prefix = trim((string)($_POST['s3_path_prefix'] ?? S3_PATH_PREFIX), '/');
        $s3_public_base_url = trim((string)($_POST['s3_public_base_url'] ?? S3_PUBLIC_BASE_URL));

        $env_path = APP_ROOT . '/.env';
        $updated = write_env_values($env_path, [
            'SITE_NAME' => env_encode_value($site_name),
            'SITE_DESCRIPTION' => env_encode_value($site_description),
            'MAX_FILE_SIZE_MB' => (string)$max_file_size_mb,
            'COOKIE_SECURE' => $is_https ? 'true' : 'false',
            'ADMIN_API_KEY' => env_encode_value($admin_api_key),
            'AUTO_COMPRESS_ON_UPLOAD' => $auto_compress_on_upload ? 'true' : 'false',
            'AUTO_CONVERT_WEBP_ON_UPLOAD' => $auto_convert_webp_on_upload ? 'true' : 'false',
            'COMPRESSION_MODE' => $compression_mode,
            'REMOTE_STORAGE_MODE' => $remote_storage_mode,
            'S3_PROVIDER' => $s3_provider,
            'S3_BUCKET' => env_encode_value($s3_bucket),
            'S3_REGION' => env_encode_value($s3_region),
            'S3_ENDPOINT' => env_encode_value($s3_endpoint),
            'S3_KEY' => env_encode_value($s3_key),
            'S3_SECRET' => env_encode_value($s3_secret),
            'S3_PATH_PREFIX' => env_encode_value($s3_path_prefix),
            'S3_PUBLIC_BASE_URL' => env_encode_value($s3_public_base_url),
        ]);

        $ini_path = APP_ROOT . '/.user.ini';
        // post_max_size 稍大于 upload_max_filesize，避免 multipart 头导致被 post_max_size 拒绝
        $post_max_size_mb = min(52, $max_file_size_mb + 2);
        $ini_updated = write_user_ini_values($ini_path, [
            'upload_max_filesize' => $max_file_size_mb . 'M',
            'post_max_size' => $post_max_size_mb . 'M',
            'max_file_uploads' => '50',
            'memory_limit' => '256M',
        ]);

        if (!$updated) {
            $message = '写入 .env 失败，请检查文件权限';
            $message_type = 'error';
        } elseif (!$ini_updated) {
            $message = '设置已写入 .env，但写入 .user.ini 失败，请检查文件权限';
            $message_type = 'error';
        } else {
            $message = '保存成功';
            $message_type = 'success';
        }
    }

    // PRG: 提交后重定向为 GET，避免浏览器刷新时重复提交表单
    $flash_payload = base64_encode((string)json_encode([
        'message' => $message,
        'type' => $message_type,
        'created_token' => $created_token,
    ], JSON_UNESCAPED_UNICODE));
    setcookie(SETTINGS_FLASH_COOKIE, $flash_payload, [
        'expires' => time() + SETTINGS_FLASH_TTL,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: /settings');
    exit;
}

$managed_tokens = get_managed_api_tokens();
$active_tokens = array_values(array_filter($managed_tokens, static function ($token) {
    return empty($token['revoked_at']);
}));
$compression_api_keys = get_compression_api_keys();
$compression_api_active_count = count(array_filter($compression_api_keys, static function (array $row): bool {
    return !empty($row['enabled']);
}));
$current_compression_mode = get_compression_mode();
$configured_upload_limit_bytes = (int)MAX_FILE_SIZE;
$runtime_upload_limit_bytes = get_php_upload_limit_bytes();
$metrics = get_server_runtime_metrics();
$current_php_sapi = (string)($metrics['php_sapi'] ?? php_sapi_name());
$server_ip = (string)($metrics['server_ip'] ?? '-');
$server_os = (string)($metrics['os'] ?? '-');
$server_uptime = (string)($metrics['uptime_text'] ?? '-');
$availability_24h_percent = isset($metrics['availability_24h_percent']) && is_numeric($metrics['availability_24h_percent'])
    ? max(0.0, min(100.0, (float)$metrics['availability_24h_percent']))
    : 0.0;
$compression_capability = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [
    'gd' => false,
    'imagick' => false,
    'curl' => false,
    'webp' => false,
];
$memory_text = (string)($metrics['memory']['text'] ?? '-');
$memory_peak_text = (string)($metrics['memory']['peak_text'] ?? '-');
$cpu_load_text = (string)($metrics['cpu_load']['text'] ?? '不可用');
$cpu_cores_text = isset($metrics['cpu_cores']) && is_numeric($metrics['cpu_cores']) ? (string)$metrics['cpu_cores'] : '不可用';
$disk_text = (string)($metrics['disk']['text'] ?? '-');
$disk_free_text = (string)($metrics['disk']['free_text'] ?? '-');
$memory_usage_percent = max(0.0, min(100.0, (float)($metrics['memory']['usage_percent'] ?? 0)));
$cpu_load_1 = isset($metrics['cpu_load']['load_1']) && is_numeric($metrics['cpu_load']['load_1']) ? (float)$metrics['cpu_load']['load_1'] : null;
$cpu_cores_num = isset($metrics['cpu_cores']) && is_numeric($metrics['cpu_cores']) ? (int)$metrics['cpu_cores'] : 0;
$cpu_load_percent = 0.0;
if ($cpu_load_1 !== null && $cpu_cores_num > 0) {
    $cpu_load_percent = max(0.0, min(100.0, round(($cpu_load_1 / $cpu_cores_num) * 100, 2)));
}
$disk_usage_percent = max(0.0, min(100.0, (float)($metrics['disk']['usage_percent'] ?? 0)));

require_once APP_ROOT . '/header.php';
?>

<main class="container page-main">
    <section class="page-shell">
        <div class="page-shell-header">
            <h2 class="page-shell-title">
                <i class="fa-light fa-gear"></i>
                <span>系统设置</span>
            </h2>
        </div>

        <div class="page-shell-body">
            <div class="settings-layout">
                <form method="post" class="settings-panel">
                    <input type="hidden" name="form_action" value="save_settings">

                    <section class="settings-block settings-block-runtime">
                        <div class="settings-block-header">
                            <h3>服务器信息</h3>
                            <p>运行环境与压缩能力检测</p>
                        </div>
                        <div class="runtime-overview">
                            <div class="runtime-overview-main">
                                <div class="runtime-overview-copy">
                                    <span class="runtime-section-label">运行概览</span>
                                    <h4>服务器正常</h4>
                                </div>
                                <div class="runtime-overview-stats">
                                    <div class="runtime-overview-stat">
                                        <span class="runtime-overview-stat-label">服务器运行时间</span>
                                        <strong id="metricUptimeDuration">解析中</strong>
                                    </div>
                                    <div class="runtime-overview-stat">
                                        <span class="runtime-overview-stat-label">近 24 小时在线率</span>
                                        <strong id="metricAvailability24h"><?= htmlspecialchars(number_format($availability_24h_percent, 1)) ?>%</strong>
                                    </div>
                                    <div class="runtime-overview-stat">
                                        <span class="runtime-overview-stat-label">在线用户</span>
                                        <strong id="metricUptimeUsers">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <div class="runtime-section-head">
                                <span class="runtime-section-label">资源占用</span>
                            </div>
                            <div class="runtime-resource-grid">
                                <article class="runtime-metric-card">
                                    <div class="runtime-metric-head">
                                        <span class="runtime-metric-icon"><i class="fa-light fa-memory"></i></span>
                                        <div>
                                            <span class="runtime-metric-label">内存占用</span>
                                            <strong class="runtime-metric-value" id="metricMemory"><?= htmlspecialchars($memory_text) ?></strong>
                                        </div>
                                        <span class="runtime-metric-badge" id="metricMemoryPercent"><?= htmlspecialchars(number_format($memory_usage_percent, 1)) ?>%</span>
                                    </div>
                                    <div class="runtime-progress">
                                        <span class="runtime-progress-bar" id="metricMemoryBar" style="width: <?= htmlspecialchars((string)$memory_usage_percent) ?>%"></span>
                                    </div>
                                </article>
                                <article class="runtime-metric-card">
                                    <div class="runtime-metric-head">
                                        <span class="runtime-metric-icon runtime-metric-icon-cpu"><i class="fa-light fa-microchip"></i></span>
                                        <div class="runtime-metric-copy runtime-metric-copy-inline">
                                            <span class="runtime-metric-label">CPU 负载</span>
                                            <div class="runtime-inline-meta">
                                                <strong class="runtime-metric-value" id="metricCpuLoad"><?= htmlspecialchars($cpu_load_text) ?></strong>
                                                <span class="runtime-metric-sub">核心数 <span id="metricCpuCores"><?= htmlspecialchars($cpu_cores_text) ?></span></span>
                                            </div>
                                        </div>
                                        <span class="runtime-metric-badge" id="metricCpuLoadPercent"><?= htmlspecialchars(number_format($cpu_load_percent, 1)) ?>%</span>
                                    </div>
                                    <div class="runtime-progress runtime-progress-cpu">
                                        <span class="runtime-progress-bar" id="metricCpuLoadBar" style="width: <?= htmlspecialchars((string)$cpu_load_percent) ?>%"></span>
                                    </div>
                                </article>
                                <article class="runtime-metric-card runtime-metric-card-disk">
                                    <div class="runtime-metric-head">
                                        <span class="runtime-metric-icon runtime-metric-icon-disk"><i class="fa-light fa-hard-drive"></i></span>
                                        <div>
                                            <span class="runtime-metric-label">磁盘占用</span>
                                            <strong class="runtime-metric-value" id="metricDisk"><?= htmlspecialchars($disk_text) ?></strong>
                                            <span class="runtime-metric-sub">剩余空间：<span id="metricDiskFree"><?= htmlspecialchars($disk_free_text) ?></span></span>
                                        </div>
                                        <span class="runtime-metric-badge" id="metricDiskPercent"><?= htmlspecialchars(number_format($disk_usage_percent, 1)) ?>%</span>
                                    </div>
                                    <div class="runtime-progress runtime-progress-disk">
                                        <span class="runtime-progress-bar" id="metricDiskBar" style="width: <?= htmlspecialchars((string)$disk_usage_percent) ?>%"></span>
                                    </div>
                                </article>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <div class="runtime-section-head">
                                <span class="runtime-section-label">环境信息</span>
                            </div>
                            <div class="runtime-meta-grid">
                                <article class="server-info-item">
                                    <span class="server-info-label">PHP 版本</span>
                                    <span class="server-info-value" id="metricPhpVersion"><?= htmlspecialchars((string)($metrics['php_version'] ?? PHP_VERSION)) ?></span>
                                </article>
                                <article class="server-info-item">
                                    <span class="server-info-label">PHP SAPI</span>
                                    <span class="server-info-value" id="metricPhpSapi"><?= htmlspecialchars($current_php_sapi) ?></span>
                                </article>
                                <article class="server-info-item">
                                    <span class="server-info-label">系统版本</span>
                                    <span class="server-info-value" id="metricOs"><?= htmlspecialchars($server_os) ?></span>
                                </article>
                                <article class="server-info-item">
                                    <span class="server-info-label">服务器 IP</span>
                                    <span class="server-info-value" id="metricServerIp"><?= htmlspecialchars($server_ip) ?></span>
                                </article>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <div class="runtime-section-head">
                                <span class="runtime-section-label">上传与能力</span>
                            </div>
                            <?php $upload_ok = $runtime_upload_limit_bytes >= $configured_upload_limit_bytes; ?>
                            <article class="runtime-upload-card">
                                <div class="runtime-upload-head">
                                    <div>
                                        <span class="server-info-label">上传上限（运行时 / 配置）</span>
                                        <strong class="runtime-upload-value" id="metricUploadLimit">
                                            <?= htmlspecialchars(format_filesize($runtime_upload_limit_bytes) . ' / ' . format_filesize($configured_upload_limit_bytes)) ?>
                                        </strong>
                                    </div>
                                    <span class="status-pill <?= $upload_ok ? 'is-on' : 'is-warn' ?>" id="metricUploadStatus">
                                        <?= $upload_ok ? '一致' : '未生效' ?>
                                    </span>
                                </div>
                            </article>
                            <div class="server-capability-grid">
                                <article class="server-capability-item">
                                    <span class="server-info-label">GD 扩展</span>
                                    <span class="status-pill <?= $compression_capability['gd'] ? 'is-on' : 'is-off' ?>" id="metricCapGd">
                                        <?= $compression_capability['gd'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="server-capability-item">
                                    <span class="server-info-label">ImageMagick 扩展</span>
                                    <span class="status-pill <?= $compression_capability['imagick'] ? 'is-on' : 'is-off' ?>" id="metricCapImagick">
                                        <?= $compression_capability['imagick'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="server-capability-item">
                                    <span class="server-info-label">cURL 扩展</span>
                                    <span class="status-pill <?= $compression_capability['curl'] ? 'is-on' : 'is-off' ?>" id="metricCapCurl">
                                        <?= $compression_capability['curl'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                                <article class="server-capability-item">
                                    <span class="server-info-label">WebP 支持</span>
                                    <span class="status-pill <?= $compression_capability['webp'] ? 'is-on' : 'is-off' ?>" id="metricCapWebp">
                                        <?= $compression_capability['webp'] ? '已启用' : '未启用' ?>
                                    </span>
                                </article>
                            </div>
                        </div>
                    </section>

                    <section class="settings-block">
                        <div class="settings-block-header">
                            <h3>基础设置</h3>
                            <p>站点信息、上传规则和压缩策略</p>
                        </div>

                        <div class="settings-cols">
                            <div class="field">
                                <label for="siteName">站点名称</label>
                                <input id="siteName" class="settings-input" type="text" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>">
                            </div>
                            <div class="field">
                                <label for="siteDescription">站点描述</label>
                                <input id="siteDescription" class="settings-input" type="text" name="site_description" value="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
                            </div>
                            <div class="field">
                                <label for="maxFileSize">最大上传大小（MB）</label>
                                <input id="maxFileSize" class="settings-number" type="number" min="1" max="50" name="max_file_size_mb" value="<?= (int)round(MAX_FILE_SIZE / 1024 / 1024) ?>">
                            </div>
                            <div class="field">
                                <label for="compressionMode">压缩方式</label>
                                <select id="compressionMode" class="settings-input" name="compression_mode">
                                    <option value="hybrid" <?= $current_compression_mode === 'hybrid' ? 'selected' : '' ?>>混合模式（ImageMagick -> GD -> TinyPNG）</option>
                                    <option value="local" <?= $current_compression_mode === 'local' ? 'selected' : '' ?>>仅本地（ImageMagick -> GD）</option>
                                    <option value="imagemagick" <?= $current_compression_mode === 'imagemagick' ? 'selected' : '' ?>>仅 ImageMagick</option>
                                    <option value="gd" <?= $current_compression_mode === 'gd' ? 'selected' : '' ?>>仅 PHP GD</option>
                                    <option value="tinypng" <?= $current_compression_mode === 'tinypng' ? 'selected' : '' ?>>仅 TinyPNG API</option>
                                </select>
                            </div>
                        </div>

                        <div class="checks">
                            <label class="check-item">
                                <input id="autoCompressOnUpload" type="checkbox" name="auto_compress_on_upload" value="1" <?= AUTO_COMPRESS_ON_UPLOAD ? 'checked' : '' ?>>
                                <span>上传后自动压缩（支持 JPG/JPEG/PNG）</span>
                            </label>
                            <label class="check-item">
                                <input id="autoConvertWebpOnUpload" type="checkbox" name="auto_convert_webp_on_upload" value="1" <?= AUTO_CONVERT_WEBP_ON_UPLOAD ? 'checked' : '' ?>>
                                <span>上传后自动转换 WebP（支持 JPG/JPEG/PNG/GIF）</span>
                            </label>
                        </div>
                    </section>

                    <section class="settings-block">
                        <div class="settings-block-header">
                            <h3>远程存储同步（R2 / S3）</h3>
                            <p>上传后自动同步原图和缩略图到对象存储</p>
                        </div>

                        <div class="settings-cols">
                            <div class="settings-col">
                                <div class="field">
                                    <label for="remoteStorageMode">同步模式</label>
                                    <select id="remoteStorageMode" class="settings-input" name="remote_storage_mode">
                                        <option value="off" <?= REMOTE_STORAGE_MODE === 'off' ? 'selected' : '' ?>>关闭</option>
                                        <option value="sync" <?= REMOTE_STORAGE_MODE === 'sync' ? 'selected' : '' ?>>同步（实时上传）</option>
                                        <option value="backup" <?= REMOTE_STORAGE_MODE === 'backup' ? 'selected' : '' ?>>备份（当前同实时上传）</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="s3Provider">存储提供商</label>
                                    <select id="s3Provider" class="settings-input" name="s3_provider">
                                        <option value="r2" <?= S3_PROVIDER === 'r2' ? 'selected' : '' ?>>Cloudflare R2</option>
                                        <option value="s3" <?= S3_PROVIDER === 's3' ? 'selected' : '' ?>>Amazon S3</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="s3Bucket">Bucket</label>
                                    <input id="s3Bucket" class="settings-input" type="text" name="s3_bucket" value="<?= htmlspecialchars(S3_BUCKET) ?>">
                                </div>
                                <div class="field">
                                    <label for="s3Region">Region</label>
                                    <input id="s3Region" class="settings-input" type="text" name="s3_region" value="<?= htmlspecialchars(S3_REGION) ?>" placeholder="R2 建议 auto">
                                </div>
                            </div>

                            <div class="settings-col">
                                <div class="field">
                                    <label for="s3Endpoint">Endpoint</label>
                                    <input id="s3Endpoint" class="settings-input" type="text" name="s3_endpoint" value="<?= htmlspecialchars(S3_ENDPOINT) ?>" placeholder="https://<accountid>.r2.cloudflarestorage.com">
                                </div>
                                <div class="field">
                                    <label for="s3Key">Access Key</label>
                                    <input id="s3Key" class="settings-input" type="text" name="s3_key" value="<?= htmlspecialchars(S3_KEY) ?>">
                                </div>
                                <div class="field">
                                    <label for="s3Secret">Secret Key</label>
                                    <input id="s3Secret" class="settings-input" type="password" name="s3_secret" value="<?= htmlspecialchars(S3_SECRET) ?>">
                                </div>
                                <div class="field">
                                    <label for="s3PathPrefix">对象路径前缀</label>
                                    <input id="s3PathPrefix" class="settings-input" type="text" name="s3_path_prefix" value="<?= htmlspecialchars(S3_PATH_PREFIX) ?>" placeholder="uploads">
                                </div>
                                <div class="field">
                                    <label for="s3PublicBaseUrl">公网访问域名（可选）</label>
                                    <input id="s3PublicBaseUrl" class="settings-input" type="text" name="s3_public_base_url" value="<?= htmlspecialchars(S3_PUBLIC_BASE_URL) ?>" placeholder="https://cdn.example.com">
                                </div>
                            </div>
                        </div>

                        <p class="settings-meta">说明：当前版本支持实时同步上传与删除。建议先保存后再执行连接测试。</p>

                        <div class="settings-submit-row">
                            <button type="submit" class="btn" name="form_action" value="test_remote_storage">
                                <i class="fa-light fa-plug-circle-check"></i>
                                测试 R2/S3 连接
                            </button>
                            <button
                                type="submit"
                                class="btn js-remote-sync-all-btn"
                                name="form_action"
                                value="sync_remote_storage_all"
                                data-busy-text="正在同步全部图片到远程存储，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-up"></i>
                                一键同步全部到 R2/S3
                            </button>
                            <button
                                type="submit"
                                class="btn js-remote-restore-all-btn"
                                name="form_action"
                                value="restore_remote_storage_all"
                                data-busy-text="正在从远程恢复到本地，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-down"></i>
                                一键恢复到本地
                            </button>
                            <button
                                type="submit"
                                data-busy-text="正在清空远程对象，请勿关闭页面..."
                                class="btn btn-danger js-remote-purge-btn"
                                name="form_action"
                                value="purge_remote_storage">
                                <i class="fa-light fa-trash-can-list"></i>
                                清空 R2/S3 远程对象
                            </button>
                        </div>
                    </section>

                    <section class="settings-block">
                        <div class="settings-block-header">
                            <h3>扫描导入</h3>
                            <p>扫描 upload / uploads 并导入图库，可选生成缩略图、压缩或转 WebP</p>
                        </div>
                        <div class="checks">
                            <label class="check-item scan-option">
                                <input id="scanCreateThumbnail" type="checkbox" name="scan_create_thumbnail" value="1" checked>
                                <span>导入时生成缩略图</span>
                            </label>
                            <label class="check-item scan-option">
                                <input id="scanAutoCompress" type="checkbox" name="scan_auto_compress" value="1">
                                <span>导入时自动压缩（JPG/JPEG/PNG）</span>
                            </label>
                        <label class="check-item scan-option">
                            <input id="scanAutoWebp" type="checkbox" name="scan_auto_webp" value="1" checked>
                            <span>导入时自动转 WebP（JPG/JPEG/PNG/GIF）</span>
                        </label>
                        </div>
                        <div class="settings-submit-row">
                            <button type="submit" class="btn" name="form_action" value="scan_import_uploads">
                                <i class="fa-light fa-folder-open"></i>
                                扫描导入 upload/uploads
                            </button>
                            <button type="submit" class="btn" name="form_action" value="generate_all_thumbnails">
                                <i class="fa-light fa-images"></i>
                                一键生成全部缩略图
                            </button>
                        </div>
                    </section>

                    <section class="settings-block">
                        <div class="settings-block-header">
                            <h3>安全设置</h3>
                            <p>管理后台访问密钥（Cookie Secure 自动按 HTTPS 生效）</p>
                        </div>

                        <div class="field">
                            <label for="adminApiKey">管理员 API Key</label>
                            <div class="secret-input-wrap">
                                <input id="adminApiKey" class="settings-input has-toggle" type="password" name="admin_api_key" value="<?= htmlspecialchars(ADMIN_API_KEY) ?>" autocomplete="off">
                                <button
                                    type="button"
                                    class="secret-toggle-btn"
                                    data-target="adminApiKey"
                                    aria-label="显示或隐藏 API Key"
                                    title="显示/隐藏 API Key">
                                    <i class="fa-light fa-eye"></i>
                                </button>
                            </div>
                        </div>

                    </section>

                    <div class="settings-submit-row">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-light fa-floppy-disk"></i>
                            保存设置
                        </button>
                    </div>
                </form>

                <section class="settings-block">
                    <div class="settings-block-header">
                        <h3>API Token 管理（第三方上传）</h3>
                        <p>可创建、复制和撤销上传 Token</p>
                    </div>

                    <form method="post" class="inline-form">
                        <input type="hidden" name="form_action" value="create_token">
                        <input class="settings-input" type="text" name="token_name" placeholder="Token 名称（如：wordpress-prod）">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-light fa-key"></i>
                            创建 Token
                        </button>
                    </form>

                    <?php if ($created_token !== ''): ?>
                        <div class="settings-callout">
                            <strong>新 Token（仅显示一次）</strong>
                            <div class="inline-form">
                                <input class="settings-input token-input" type="text" readonly value="<?= htmlspecialchars($created_token) ?>">
                                <button type="button" class="btn copy-token-btn" data-copy="<?= htmlspecialchars($created_token) ?>">复制</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="settings-meta">当前启用 Token：<?= count($active_tokens) ?> 个。出于安全原因，已创建 Token 不再长期明文展示。</p>

                    <div class="settings-table-wrap">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>创建时间</th>
                                    <th>最近使用</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_tokens as $token): ?>
                                    <?php
                                    $name = (string)($token['name'] ?? 'token');
                                    $created_at = (string)($token['created_at'] ?? '-');
                                    $last_used_at = (string)($token['last_used_at'] ?? '-');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <td><?= htmlspecialchars($created_at) ?></td>
                                        <td><?= htmlspecialchars($last_used_at) ?></td>
                                        <td>启用中</td>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="form_action" value="revoke_token">
                                                <input type="hidden" name="token_id" value="<?= htmlspecialchars((string)$token['id']) ?>">
                                                <button type="submit" class="btn btn-danger">撤销</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($active_tokens)): ?>
                                    <tr>
                                        <td colspan="5">暂无可用 Token，请创建新的 Token</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="settings-block">
                    <div class="settings-block-header">
                        <h3>图片压缩 API 管理（TinyPNG）</h3>
                        <p>多 Key 轮询与调用监控</p>
                    </div>

                    <form method="post" class="inline-form inline-form-3">
                        <input type="hidden" name="form_action" value="add_compression_api">
                        <input class="settings-input" type="text" name="compression_api_name" placeholder="名称（如：tinify-main）">
                        <input class="settings-input" type="text" name="compression_api_key" placeholder="输入 TinyPNG API Key">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-light fa-plus"></i>
                            添加 Key
                        </button>
                    </form>

                    <p class="settings-meta">已配置 <?= count($compression_api_keys) ?> 个，启用中 <?= $compression_api_active_count ?> 个。系统优先使用调用次数较少的 Key，并记录每个 Key 的调用统计。</p>

                    <div class="settings-table-wrap">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>Key</th>
                                    <th>状态</th>
                                    <th>总调用</th>
                                    <th>成功</th>
                                    <th>失败</th>
                                    <th>最近使用</th>
                                    <th>最近结果</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compression_api_keys as $row): ?>
                                    <?php
                                    $id = (string)($row['id'] ?? '');
                                    $name = (string)($row['name'] ?? 'TinyPNG');
                                    $api_key = (string)($row['api_key'] ?? '');
                                    $enabled = !empty($row['enabled']);
                                    $used_count = (int)($row['used_count'] ?? 0);
                                    $success_count = (int)($row['success_count'] ?? 0);
                                    $failed_count = (int)($row['failed_count'] ?? 0);
                                    $last_used_at = (string)($row['last_used_at'] ?? '-');
                                    $last_status_code = (int)($row['last_status_code'] ?? 0);
                                    $last_error = (string)($row['last_error'] ?? '');
                                    $masked = strlen($api_key) > 8
                                        ? substr($api_key, 0, 4) . str_repeat('*', max(0, strlen($api_key) - 8)) . substr($api_key, -4)
                                        : str_repeat('*', strlen($api_key));
                                    $last_result = $last_status_code > 0 ? 'HTTP ' . $last_status_code : '-';
                                    if ($last_error !== '') {
                                        $last_result .= ' / ' . $last_error;
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        <td><?= htmlspecialchars($masked) ?></td>
                                        <td><?= $enabled ? '启用中' : '已禁用' ?></td>
                                        <td><?= number_format($used_count) ?></td>
                                        <td><?= number_format($success_count) ?></td>
                                        <td><?= number_format($failed_count) ?></td>
                                        <td><?= htmlspecialchars($last_used_at) ?></td>
                                        <td><?= htmlspecialchars($last_result) ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <form method="post">
                                                    <input type="hidden" name="form_action" value="toggle_compression_api">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
                                                    <button type="submit" class="btn"><?= $enabled ? '禁用' : '启用' ?></button>
                                                </form>
                                                <form method="post">
                                                    <input type="hidden" name="form_action" value="delete_compression_api">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <button type="submit" class="btn btn-danger">删除</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($compression_api_keys)): ?>
                                    <tr>
                                        <td colspan="9">暂无压缩 API Key，请先添加</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    const flashMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE) ?>;
    const flashType = <?= json_encode($message_type === 'success' ? 'success' : 'error') ?>;
    let flashShown = false;

    const showFlash = () => {
        if (flashShown || !flashMessage) {
            return true;
        }
        if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
            window.ImgEt.Utils.showNotification(flashMessage, flashType);
            flashShown = true;
            return true;
        }
        return false;
    };

    // 立即尝试一次，避免 DOMContentLoaded 时序导致丢失
    showFlash();

    document.addEventListener('DOMContentLoaded', () => {
        if (!flashShown) {
            showFlash();
            if (!flashShown) {
                setTimeout(showFlash, 80);
            }
        }

        const toggles = document.querySelectorAll('.secret-toggle-btn');
        toggles.forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                if (!targetId) return;
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                const icon = btn.querySelector('i');
                if (!icon) return;
                icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
            });
        });

        const copyButtons = document.querySelectorAll('.copy-token-btn');
        copyButtons.forEach((btn) => {
            btn.addEventListener('click', async () => {
                const value = btn.getAttribute('data-copy') || '';
                if (!value) return;
                try {
                    await navigator.clipboard.writeText(value);
                    if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                        window.ImgEt.Utils.showNotification('Token 已复制', 'success');
                    }
                } catch (e) {
                    if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                        window.ImgEt.Utils.showNotification('复制失败，请手动复制', 'error');
                    }
                }
            });
        });

        const purgeRemoteBtn = document.querySelector('.js-remote-purge-btn');
        const syncRemoteAllBtn = document.querySelector('.js-remote-sync-all-btn');
        const restoreRemoteAllBtn = document.querySelector('.js-remote-restore-all-btn');
        const showBusyToast = (btn) => {
            const text = btn?.getAttribute('data-busy-text') || '任务执行中，请稍候...';
            if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                window.ImgEt.Utils.showNotification(text, 'info');
            }
        };
        if (purgeRemoteBtn) {
            purgeRemoteBtn.addEventListener('click', (event) => {
                event.preventDefault();

                const form = purgeRemoteBtn.closest('form');
                if (!form) return;

                const submitPurge = () => {
                    const actionInput = form.querySelector('input[name="form_action"]');
                    if (actionInput) {
                        actionInput.value = 'purge_remote_storage';
                    }
                    form.submit();
                };

                const confirmMessage = '确认清空远程存储对象吗？将删除当前配置前缀下的所有对象，无法恢复。';
                if (window.ImgEt && window.ImgEt.DialogManager && typeof window.ImgEt.DialogManager.showConfirmDialog === 'function') {
                    window.ImgEt.DialogManager.showConfirmDialog('清空远程对象确认', confirmMessage, () => {
                        showBusyToast(purgeRemoteBtn);
                        submitPurge();
                    });
                } else if (window.confirm(confirmMessage)) {
                    showBusyToast(purgeRemoteBtn);
                    submitPurge();
                }
            });
        }

        if (syncRemoteAllBtn) {
            syncRemoteAllBtn.addEventListener('click', (event) => {
                event.preventDefault();
                const form = syncRemoteAllBtn.closest('form');
                if (!form) return;
                const run = () => {
                    const actionInput = form.querySelector('input[name="form_action"]');
                    if (actionInput) {
                        actionInput.value = 'sync_remote_storage_all';
                    }
                    showBusyToast(syncRemoteAllBtn);
                    form.submit();
                };
                const msg = '确认执行“一键同步全部”吗？将把本地图库全部同步到远程。';
                if (window.ImgEt && window.ImgEt.DialogManager && typeof window.ImgEt.DialogManager.showConfirmDialog === 'function') {
                    window.ImgEt.DialogManager.showConfirmDialog('全量同步确认', msg, run);
                } else if (window.confirm(msg)) {
                    run();
                }
            });
        }

        if (restoreRemoteAllBtn) {
            restoreRemoteAllBtn.addEventListener('click', (event) => {
                event.preventDefault();
                const form = restoreRemoteAllBtn.closest('form');
                if (!form) return;
                const run = () => {
                    const actionInput = form.querySelector('input[name="form_action"]');
                    if (actionInput) {
                        actionInput.value = 'restore_remote_storage_all';
                    }
                    showBusyToast(restoreRemoteAllBtn);
                    form.submit();
                };
                const msg = '确认执行“一键恢复到本地”吗？将从远程下载当前前缀下全部对象到本地。';
                if (window.ImgEt && window.ImgEt.DialogManager && typeof window.ImgEt.DialogManager.showConfirmDialog === 'function') {
                    window.ImgEt.DialogManager.showConfirmDialog('全量恢复确认', msg, run);
                } else if (window.confirm(msg)) {
                    run();
                }
            });
        }

        // 自动处理策略互斥：WebP 开启时禁用自动压缩
        const autoCompressInput = document.getElementById('autoCompressOnUpload');
        const autoWebpInput = document.getElementById('autoConvertWebpOnUpload');
        const syncProcessToggles = () => {
            if (!autoCompressInput || !autoWebpInput) return;
            if (autoWebpInput.checked) {
                autoCompressInput.checked = false;
                autoCompressInput.disabled = true;
            } else {
                autoCompressInput.disabled = false;
            }
        };
        if (autoCompressInput && autoWebpInput) {
            syncProcessToggles();
            autoWebpInput.addEventListener('change', syncProcessToggles);
        }

        // 扫描导入选项互斥：WebP 开启时禁用压缩
        const scanCompressInput = document.getElementById('scanAutoCompress');
        const scanWebpInput = document.getElementById('scanAutoWebp');
        const syncScanProcessToggles = () => {
            if (!scanCompressInput || !scanWebpInput) return;
            if (scanWebpInput.checked) {
                scanCompressInput.checked = false;
                scanCompressInput.disabled = true;
            } else {
                scanCompressInput.disabled = false;
            }
        };
        if (scanCompressInput && scanWebpInput) {
            syncScanProcessToggles();
            scanWebpInput.addEventListener('change', syncScanProcessToggles);
        }

        // 服务器信息实时刷新
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el && typeof value === 'string') {
                el.textContent = value;
            }
        };
        const setCapability = (id, enabled) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('is-on', 'is-off', 'is-warn');
            el.classList.add(enabled ? 'is-on' : 'is-off');
            el.textContent = enabled ? '已启用' : '未启用';
        };
        const clampPercent = (value) => {
            const num = Number(value);
            if (!Number.isFinite(num)) return 0;
            return Math.max(0, Math.min(100, num));
        };
        const setMetricProgress = (barId, badgeId, percent, digits = 1) => {
            const normalized = clampPercent(percent);
            const bar = document.getElementById(barId);
            if (bar) {
                bar.style.width = normalized.toFixed(2) + '%';
            }
            const badge = document.getElementById(badgeId);
            if (badge) {
                badge.textContent = normalized.toFixed(digits) + '%';
            }
        };
        const updateUptimeDisplay = (uptimeText) => {
            const raw = typeof uptimeText === 'string' ? uptimeText.trim() : '';
            setText('metricUptime', raw || '-');

            let duration = raw || '-';
            let users = '-';

            if (raw) {
                const upMatch = raw.match(/\bup\s+(.+?)(?:,\s+\d+\s+users?|\s+\d+\s+users?|\s+load averages?:)/i);
                if (upMatch && upMatch[1]) {
                    duration = upMatch[1].trim().replace(/,\s*$/, '');
                }
                const userMatch = raw.match(/,\s*(\d+)\s+users?/i);
                if (userMatch && userMatch[1]) {
                    users = userMatch[1] + ' 人';
                }
            }

            setText('metricUptimeDuration', duration);
            setText('metricUptimeUsers', users);
        };

        updateUptimeDisplay(<?= json_encode($server_uptime, JSON_UNESCAPED_UNICODE) ?>);
        setText('metricAvailability24h', <?= json_encode(number_format($availability_24h_percent, 1) . '%', JSON_UNESCAPED_UNICODE) ?>);

        const updateSystemStatus = async () => {
            try {
                const resp = await fetch('/api/system_status.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await resp.json();
                if (!resp.ok || !data || data.status !== 'success' || !data.data) {
                    throw new Error((data && data.message) ? data.message : '状态读取失败');
                }

                const s = data.data;
                setText('metricPhpVersion', String(s.php_version ?? '-'));
                setText('metricPhpSapi', String(s.php_sapi ?? '-'));
                setText('metricOs', String(s.os ?? '-'));
                setText('metricServerIp', String(s.server_ip ?? '-'));
                updateUptimeDisplay(String(s.uptime_text ?? '-'));
                const availability24h = Number(s.availability_24h_percent ?? NaN);
                setText('metricAvailability24h', Number.isFinite(availability24h) ? availability24h.toFixed(1) + '%' : '-');
                setText('metricMemory', String((s.memory && s.memory.text) ? s.memory.text : '-'));
                setText('metricCpuCores', String((s.cpu_cores ?? '不可用')));
                setText('metricCpuLoad', String((s.cpu_load && s.cpu_load.text) ? s.cpu_load.text : '不可用'));
                setText('metricDisk', String((s.disk && s.disk.text) ? s.disk.text : '-'));
                setText('metricDiskFree', String((s.disk && s.disk.free_text) ? s.disk.free_text : '-'));
                setMetricProgress('metricMemoryBar', 'metricMemoryPercent', s.memory && s.memory.usage_percent ? s.memory.usage_percent : 0);
                const cpuCores = Number(s.cpu_cores ?? 0);
                const load1 = Number((s.cpu_load && s.cpu_load.load_1) ?? NaN);
                const cpuLoadPercent = Number.isFinite(load1) && cpuCores > 0 ? (load1 / cpuCores) * 100 : 0;
                setMetricProgress('metricCpuLoadBar', 'metricCpuLoadPercent', cpuLoadPercent);
                setMetricProgress('metricDiskBar', 'metricDiskPercent', s.disk && s.disk.usage_percent ? s.disk.usage_percent : 0);

                const runtimeLimit = String((s.php_upload_limit_text ?? '') || '');
                const configuredLimit = String((s.config_upload_limit_text ?? '') || '');
                if (runtimeLimit && configuredLimit) {
                    setText('metricUploadLimit', runtimeLimit + ' / ' + configuredLimit);
                }
                const uploadStatusEl = document.getElementById('metricUploadStatus');
                if (uploadStatusEl) {
                    const uploadOk = !!s.upload_limit_ok;
                    uploadStatusEl.classList.remove('is-on', 'is-off', 'is-warn');
                    uploadStatusEl.classList.add(uploadOk ? 'is-on' : 'is-warn');
                    uploadStatusEl.textContent = uploadOk ? '一致' : '未生效';
                }

                const cap = s.capability || {};
                setCapability('metricCapGd', !!cap.gd);
                setCapability('metricCapImagick', !!cap.imagick);
                setCapability('metricCapCurl', !!cap.curl);
                setCapability('metricCapWebp', !!cap.webp);
            } catch (err) {
                if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                    window.ImgEt.Utils.showNotification('服务器状态刷新失败', 'error');
                }
            }
        };
        setInterval(updateSystemStatus, 3000);

    });

    window.addEventListener('load', () => {
        if (!flashShown) {
            showFlash();
        }
    }, { once: true });
})();
</script>

<?php require_once APP_ROOT . '/footer.php'; ?>

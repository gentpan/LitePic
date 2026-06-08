<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}


if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
    if (\LitePic\Http\Controllers\SettingsController::isAjaxRequest()) {
        \LitePic\Core\Response::json([
            'status' => 'error',
            'type' => 'error',
            'message' => '登录状态已失效，请重新登录后再保存设置',
        ], 401);
    }
    \LitePic\Core\HttpCache::redirect('/upload');
}

const SETTINGS_FLASH_COOKIE = 'settings_flash_once';
const SETTINGS_FLASH_TTL = 120;

$page_title = '系统设置';

// Tab definitions for the split settings page. Each section in the page
// is tagged with one of these keys so we can show only the active tab's
// content. The save_settings handler uses `?? CURRENT_VALUE` defaults so
// fields that aren't part of the active tab keep their existing values.
$settings_tabs = [
    'basic' => [
        'icon' => 'fa-sliders',
        'label' => '基础',
        'description' => '站点名称、描述，以及服务器运行环境概览',
    ],
    'image' => [
        'icon' => 'fa-wand-magic-sparkles',
        'label' => '图片处理',
        'description' => '压缩、格式转换、水印、防盗链、请求统计 — 所有图片相关处理',
    ],
    'storage' => [
        'icon' => 'fa-cloud-arrow-up',
        'label' => '存储与导入',
        'description' => '远程对象存储（S3 / R2）配置，以及扫描已有目录入库',
    ],
    'account' => [
        'icon' => 'fa-user-shield',
        'label' => '账号',
        'description' => '管理员密码、上传 API Token、Passkey 无密码登录',
    ],
    'tasks' => [
        'icon' => 'fa-list-check',
        'label' => '任务',
        'description' => '后台图片处理队列（缩略图 / 压缩 / WebP / AVIF / 水印 / 远程同步）的状态、失败重试、手动触发',
    ],
    'telegram' => [
        'icon' => 'fa-paper-plane',
        'label' => 'Telegram',
        'description' => '绑定 Telegram 机器人 — 直接在聊天里上传图片、管理相册、获取链接',
    ],
    'system' => [
        'icon' => 'fa-database',
        'label' => '系统',
        'description' => 'SQLite 数据库、备份、清理与程序更新',
    ],
];

// 旧 tab key 向后兼容 — 老链接 / 收藏 / 后台 redirect 不会 404，自动落到新分组
$_settings_tab_alias = [
    'general'     => 'basic',
    'compression' => 'image',
    'watermark'   => 'image',
    'import'      => 'storage',
    'auth'        => 'account',
];
if (isset($_GET['tab']) && isset($_settings_tab_alias[(string)$_GET['tab']])) {
    $_GET['tab'] = $_settings_tab_alias[(string)$_GET['tab']];
}
if (isset($_POST['active_tab']) && isset($_settings_tab_alias[(string)$_POST['active_tab']])) {
    $_POST['active_tab'] = $_settings_tab_alias[(string)$_POST['active_tab']];
}

$active_settings_tab = isset($_GET['tab']) && isset($settings_tabs[$_GET['tab']])
    ? (string)$_GET['tab']
    : 'basic';
$posted_settings_tab = isset($_POST['active_tab']) && isset($settings_tabs[$_POST['active_tab']])
    ? (string)$_POST['active_tab']
    : $active_settings_tab;
$message = '';
$message_type = 'success';
$created_token = '';
$saved_settings = [];
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
        'secure' => \LitePic\Core\RequestContext::isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $is_ajax_request = \LitePic\Http\Controllers\SettingsController::isAjaxRequest();
    if (!\LitePic\Core\Csrf::verify($csrfToken)) {
        $message = '安全令牌无效或已过期，请刷新页面后重试';
        $message_type = 'error';
        if ($is_ajax_request) {
            \LitePic\Core\Response::json([
                'status' => 'error',
                'type' => 'error',
                'message' => $message,
                'action' => (string)($_POST['form_action'] ?? 'save_settings'),
            ], 419);
        }
    } else {
        $form_action = (string)($_POST['form_action'] ?? 'save_settings');
        try {
            $result = (new \LitePic\Http\Controllers\SettingsController())->dispatch($form_action);
        } catch (\Throwable $e) {
            \LitePic\Core\Logger::error('Settings action failed', [
                'action' => $form_action,
                'error' => $e->getMessage(),
            ]);
            if ($is_ajax_request) {
                \LitePic\Core\Response::json([
                    'status' => 'error',
                    'type' => 'error',
                    'message' => \LitePic\Core\Response::safeMessage($e),
                    'action' => $form_action,
                ], 500);
            }
            $result = [
                'message' => \LitePic\Core\Response::safeMessage($e),
                'type' => 'error',
            ];
        }

        if (($result['message'] ?? '') !== '') {
            $message = (string)$result['message'];
            $message_type = (string)($result['type'] ?? 'success') === 'error' ? 'error' : 'success';
        }
        if (!empty($result['created_token'])) {
            $created_token = (string)$result['created_token'];
        }
        if (isset($result['saved_settings']) && is_array($result['saved_settings'])) {
            $saved_settings = $result['saved_settings'];
        }

        // AJAX clients (settings JS) take JSON; everyone else gets a PRG redirect.
        if ($is_ajax_request) {
            $import_task_status = null;
            try {
                $import_task_status = (new \LitePic\Service\Importer\Importer())->queueStatus();
            } catch (\Throwable $e) {
                \LitePic\Core\Logger::error('Settings import queue status failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            \LitePic\Core\Response::json([
                'status' => $message_type === 'success' ? 'success' : 'error',
                'type' => $message_type === 'success' ? 'success' : 'error',
                'message' => $message,
                'action' => $form_action,
                'created_token' => $created_token,
                'saved_settings' => $saved_settings,
                'compression_key_added' => $result['compression_key_added'] ?? null,
                'import_task_status' => $import_task_status,
            ]);
        }

        // PRG: cookie-flash + 303 to the same tab so refresh doesn't repost.
        $flashPayload = base64_encode((string)json_encode([
            'message' => $message,
            'type' => $message_type,
            'created_token' => $created_token,
        ], JSON_UNESCAPED_UNICODE));
        setcookie(SETTINGS_FLASH_COOKIE, $flashPayload, [
            'expires' => time() + SETTINGS_FLASH_TTL,
            'path' => '/',
            'secure' => \LitePic\Core\RequestContext::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // 保存后回到当前 tab 的路径化 URL（/settings/<tab>），保持上下文
        $redirect = '/settings';
        if ($posted_settings_tab !== '' && $posted_settings_tab !== 'basic') {
            $redirect .= '/' . rawurlencode($posted_settings_tab);
        }
        \LitePic\Core\HttpCache::redirect($redirect);
    }
}

$managed_tokens = (new \LitePic\Repository\ApiTokenRepository())->allForDisplay();
$active_tokens = array_values(array_filter($managed_tokens, static function ($token) {
    return empty($token['revoked_at']);
}));
$compression_api_keys = (new \LitePic\Repository\CompressionKeyRepository())->all();
$compression_api_active_count = count(array_filter($compression_api_keys, static function (array $row): bool {
    return !empty($row['enabled']);
}));
$current_compression_mode = \LitePic\Service\Image\ImageFormat::compressionMode();
$upload_format_labels = [
    'jpg' => 'JPG',
    'jpeg' => 'JPEG',
    'png' => 'PNG',
    'gif' => 'GIF',
    'webp' => 'WebP',
    'avif' => 'AVIF',
    'heic' => 'HEIC',
    'heif' => 'HEIF',
    'ico' => 'ICO',
    'svg' => 'SVG',
    'bmp' => 'BMP',
    'tiff' => 'TIFF',
    'tif' => 'TIF',
];
$import_task_status = (new \LitePic\Service\Importer\Importer())->queueStatus();
$configured_upload_limit_bytes = (int)MAX_FILE_SIZE;
$runtime_upload_limit_bytes = \LitePic\Service\Upload\UploadService::phpUploadLimitBytes();
$metrics = (new \LitePic\Service\Stats\ServerInfo())->runtimeMetrics();

$current_php_sapi = (string)($metrics['php_sapi'] ?? php_sapi_name());
$server_ip = (string)($metrics['server_ip'] ?? '-');
$server_os = (string)($metrics['distro']['pretty'] ?? $metrics['os'] ?? '-');
$server_distro_id = strtolower((string)($metrics['distro']['id'] ?? ''));
// FontAwesome Brands icon for the detected distro; fallback to fa-linux for anything we don't have a brand mark for.
$distro_icon_map = [
    'debian'     => 'fa-debian',
    'ubuntu'     => 'fa-ubuntu',
    'fedora'     => 'fa-fedora',
    'centos'     => 'fa-centos',
    'rhel'       => 'fa-redhat',
    'redhat'     => 'fa-redhat',
    'opensuse'   => 'fa-suse',
    'suse'       => 'fa-suse',
    'sles'       => 'fa-suse',
];
$server_distro_icon = $distro_icon_map[$server_distro_id] ?? 'fa-linux';
$server_uptime = (string)($metrics['uptime_text'] ?? '-');
// UPTIME 条默认范围随数据量递增:>30 天→90D,满 30 天→30D,不足→1D。
$uptime_default_range = \LitePic\Service\Stats\LivenessTracker::defaultRange();
$availability_24h_percent = isset($metrics['availability_24h_percent']) && is_numeric($metrics['availability_24h_percent'])
    ? max(0.0, min(100.0, (float)$metrics['availability_24h_percent']))
    : 0.0;
$compression_capability = is_array($metrics['capability'] ?? null) ? $metrics['capability'] : [
    'gd' => false,
    'imagick' => false,
    'avif' => false,
    'webp' => false,
    'heic' => false,
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
$hotlink_enabled = defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED;
$web_server = (new \LitePic\Service\Stats\ServerInfo())->webServer();
$server_software = (string)$web_server['raw'];
$server_label = (string)$web_server['label'];
$server_uses_htaccess = !empty($web_server['uses_htaccess']);
$server_uses_nginx_rules = !empty($web_server['uses_nginx_rules']);
$server_uses_caddyfile = !empty($web_server['uses_caddyfile']);

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main settings-page settings-layout"
      data-pjax-container
      data-active-settings-tab="<?= htmlspecialchars($active_settings_tab, ENT_QUOTES, 'UTF-8') ?>">
<div class="settings-shell">
    <nav class="settings-tab-nav" aria-label="设置分类">
        <?php foreach ($settings_tabs as $tab_key => $tab_meta): ?>
            <?php
            $is_active = $tab_key === $active_settings_tab;
            // 路径化 URL — /settings = basic（默认），/settings/<tab> = 其它 tab
            // 由 .htaccess 和 router.php 把 /settings/<tab> 重写到 index.php?tab=<tab>
            $href = '/settings' . ($tab_key === 'basic' ? '' : '/' . rawurlencode($tab_key));
            ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
               data-pjax
               <?= $is_active ? 'aria-current="page"' : '' ?>>
                <i class="fa-light <?= htmlspecialchars((string)$tab_meta['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars((string)$tab_meta['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php
    // tab 状态由 URL 自己承载（/settings/<tab>），不需要 sessionStorage 恢复
    $current_tab_meta = $settings_tabs[$active_settings_tab] ?? null;
    if ($current_tab_meta !== null):
    ?>
    <header class="settings-tab-title" aria-labelledby="settingsTabTitleHeading">
        <span class="settings-tab-title__icon" aria-hidden="true">
            <i class="fa-light <?= htmlspecialchars((string)$current_tab_meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
        </span>
        <div class="settings-tab-title__copy">
            <h2 id="settingsTabTitleHeading" class="settings-tab-title__heading">
                <?= htmlspecialchars((string)$current_tab_meta['label']) ?>
            </h2>
            <?php if (!empty($current_tab_meta['description'])): ?>
                <p class="settings-tab-title__description">
                    <?= htmlspecialchars((string)$current_tab_meta['description']) ?>
                </p>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="settings-panel" id="settingsForm">
                    <?= \LitePic\Core\Csrf::inputField() ?>
                    <input type="hidden" name="form_action" value="save_settings">
                    <input type="hidden" name="active_tab" value="<?= htmlspecialchars($active_settings_tab, ENT_QUOTES, 'UTF-8') ?>">

<?php if (in_array($active_settings_tab, ['basic'], true)): // 服务器信息: 移到基础 tab 作为概览 ?>
                    <section class="settings-block-runtime">
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-server" aria-hidden="true"></i>
                                <span>服务器信息</span>
                            </h3>
                            <p>运行环境与压缩能力检测</p>
                        </div>
                        <div class="runtime-section">
                            <h4 class="runtime-section-label">资源占用</h4>
<?php $gauge_r = 52; $gauge_c = 2 * M_PI * $gauge_r; ?>
                            <div class="runtime-resource-grid runtime-resource-gauges">
                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-memory" id="metricMemoryCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $memory_usage_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricMemoryPercent"><?= htmlspecialchars(number_format($memory_usage_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">内存占用</div>
                                    <div class="runtime-gauge-detail" id="metricMemoryDetail"><?= htmlspecialchars($memory_text) ?></div>
                                </div>

                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-cpu" id="metricCpuLoadCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $cpu_load_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricCpuLoadPercent"><?= htmlspecialchars(number_format($cpu_load_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">CPU 负载</div>
                                    <div class="runtime-gauge-detail" id="metricCpuLoadDetail"><?= htmlspecialchars($cpu_load_text) ?> · <?= htmlspecialchars($cpu_cores_text) ?> 核</div>
                                </div>

                                <div class="runtime-gauge-card">
                                    <div class="runtime-gauge">
                                        <svg class="runtime-gauge-svg" viewBox="0 0 120 120">
                                            <circle class="runtime-gauge-track" cx="60" cy="60" r="<?= $gauge_r ?>"/>
                                            <circle class="runtime-gauge-fill runtime-gauge-fill-disk" id="metricDiskCircle"
                                                    cx="60" cy="60" r="<?= $gauge_r ?>"
                                                    stroke-dasharray="<?= $gauge_c ?>"
                                                    stroke-dashoffset="<?= $gauge_c * (1 - $disk_usage_percent / 100) ?>"/>
                                        </svg>
                                        <div class="runtime-gauge-value" id="metricDiskPercent"><?= htmlspecialchars(number_format($disk_usage_percent, 1)) ?>%</div>
                                    </div>
                                    <div class="runtime-gauge-label">磁盘占用</div>
                                    <div class="runtime-gauge-detail" id="metricDiskDetail">剩余 <?= htmlspecialchars($disk_free_text) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- ====== 整行 UPTIME 条 ====== -->
                        <div class="runtime-section runtime-uptime-section">
                            <div class="runtime-uptime-strip" data-uptime-strip data-uptime-default="<?= htmlspecialchars($uptime_default_range, ENT_QUOTES) ?>">
                                <div class="runtime-uptime-head">
                                    <span class="runtime-uptime-title">UPTIME</span>
                                    <div class="runtime-uptime-ranges" role="tablist" aria-label="Uptime range">
                                        <?php foreach (['90d' => '90D', '30d' => '30D', '1d' => '1D', '1h' => '1H'] as $rangeKey => $rangeLabel): ?>
                                            <button type="button"
                                                    class="runtime-uptime-range<?= $rangeKey === $uptime_default_range ? ' is-active' : '' ?>"
                                                    data-uptime-range="<?= $rangeKey ?>"
                                                    role="tab"
                                                    aria-selected="<?= $rangeKey === $uptime_default_range ? 'true' : 'false' ?>">
                                                <?= $rangeLabel ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="runtime-uptime-percent" data-uptime-percent>—</span>
                                </div>
                                <div class="runtime-uptime-bar" data-uptime-bar role="img" aria-label="服务运行时间分段图">
                                    <!-- segments injected by JS -->
                                    <div class="runtime-uptime-loading">载入中…</div>
                                </div>
                                <div class="runtime-uptime-foot">
                                    <span data-uptime-start>—</span>
                                    <span data-uptime-end>Now</span>
                                </div>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <h4 class="runtime-section-label">环境信息</h4>
                            <div class="runtime-meta-grid">
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-light fa-desktop" aria-hidden="true"></i>
                                        <span>系统版本</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricOs" data-distro-id="<?= htmlspecialchars($server_distro_id) ?>">
                                        <?= htmlspecialchars($server_os) ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-brands fa-php" aria-hidden="true"></i>
                                        <span>PHP 版本</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricPhpVersion"><?= htmlspecialchars((string)($metrics['php_version'] ?? PHP_VERSION)) ?></span>
                                </article>
                                <?php
                                // 从 SERVER_SOFTWARE 抽出第一段版本号（"Apache/2.4.58 (Debian)" -> "2.4.58"）。
                                // PHP 内置开发服务器的 SERVER_SOFTWARE 是 "PHP/x.y.z Development Server"，
                                // 抽出来就是 PHP 版本本身 — 也是合理可读的信息。
                                $server_version = '';
                                if ($server_software !== '' && preg_match('#/([0-9]+(?:\.[0-9]+)*)#', $server_software, $vm)) {
                                    $server_version = $vm[1];
                                }
                                $server_display = $server_version !== ''
                                    ? $server_label . ' ' . $server_version
                                    : $server_label;
                                ?>
                                <article class="border border-border p-4 grid gap-2 relative">
                                    <!-- 「如何配置」定位到卡片右上角红框位置：
                                         父级 .runtime-meta-grid > article 是 flex
                                         items-center justify-center 横向居中布局，
                                         链接如果作为普通 flex item 会挤在中间垂直折行。
                                         absolute + top-2 right-2 让它脱流，不影响主 row。 -->
                                    <a href="https://litepic.io/docs" target="_blank" rel="noopener noreferrer" class="absolute top-2 right-2 z-10 text-xs text-primary no-underline hover:underline inline-flex items-center gap-1 leading-none" title="查看 LitePic 推荐的 Web 服务器配置">
                                        <span>如何配置</span>
                                        <i class="fa-light fa-arrow-up-right-from-square text-[10px]" aria-hidden="true"></i>
                                    </a>
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-light fa-server" aria-hidden="true"></i>
                                        <span>Web 服务器</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricWebServer" title="<?= htmlspecialchars($server_software ?: 'SERVER_SOFTWARE 未提供') ?>">
                                        <?= htmlspecialchars($server_display) ?>
                                    </span>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray runtime-meta-label">
                                        <i class="fa-light fa-network-wired" aria-hidden="true"></i>
                                        <span>服务器 IP</span>
                                    </span>
                                    <span class="text-base text-dark break-all runtime-meta-value" id="metricServerIp"><?= htmlspecialchars($server_ip) ?></span>
                                </article>
                            </div>
                        </div>

                        <div class="runtime-section">
                            <h4 class="runtime-section-label">上传与能力</h4>
                            <?php
                            // 上传上限固定 50MB —— 后台不再让用户改，省掉跟 PHP-FPM /
                            // Nginx client_max_body_size 等多层限制纠缠的问题。
                            // 50MB 对图床场景足够，需要更大请直接改后端常量。
                            $upload_ok = $runtime_upload_limit_bytes >= 50 * 1024 * 1024;
                            ?>
                            <?php
                            // 「未启用 / 未生效」状态下在 status 徽章右边追加一个 ? 图标。
                            // 点击跳到 litepic.io/docs 对应章节，title 属性提供 hover tooltip。
                            // 用 ? 而非「查看启用方法」一行字 — 视觉占地最小，跟徽章并排
                            // 保持卡片紧凑，启用后图标自动消失。
                            $cap_help_icon = static function (string $anchor, string $label = '查看启用方法'): string {
                                $href = 'https://litepic.io/docs#' . $anchor;
                                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="capability-help-icon inline-flex items-center justify-center text-primary no-underline hover:opacity-70 transition-opacity" title="' . htmlspecialchars($label) . '" aria-label="' . htmlspecialchars($label) . '">'
                                    . '<i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>'
                                    . '</a>';
                            };
                            ?>
                            <?php
                            // 5 个能力卡底部徽章统一格式 — 都是 60% 宽 + 28px 高 + mx-auto
                            // 在卡片里绝对居中。? 帮助图标用 absolute 定位贴在徽章右侧，
                            // 不挤占徽章本身的居中位置 — 启用 vs 未启用切换时徽章不会左右
                            // 跳动，只是 ? 出现/消失。
                            $cap_badge_cls = 'flex items-center justify-center w-[60%] mx-auto min-h-[28px] px-2.5 text-sm leading-none border border-transparent whitespace-nowrap';
                            // ? 图标的绝对定位：徽章右边缘在容器 80% 处（50% 中心 + 30% 半宽），
                            // ml-2 留 8px 间距，再加自身约 16px 宽就紧贴右侧。
                            $cap_badge_help = 'absolute top-1/2 -translate-y-1/2 left-[80%] ml-2 capability-help-icon inline-flex items-center justify-center text-primary no-underline hover:opacity-70 transition-opacity';
                            ?>
                            <div class="grid grid-cols-6 gap-3.5 runtime-capability-grid">
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">上传上限</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> is-on" id="metricUploadStatus" title="PHP 与 Web 服务器配置允许的最大单文件上传大小">
                                            <?= htmlspecialchars(\LitePic\Core\Format::filesize($runtime_upload_limit_bytes)) ?>
                                        </span>
                                        <a href="https://litepic.io/docs#php-upload-limits" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="如何在 PHP / Web 服务器配置中调大上传上限" aria-label="如何调大上传上限">
                                            <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">GD 扩展</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> <?= $compression_capability['gd'] ? 'is-on' : 'is-off' ?>" id="metricCapGd">
                                            <?= $compression_capability['gd'] ? '已启用' : '未启用' ?>
                                        </span>
                                        <?php if (!$compression_capability['gd']): ?>
                                            <a href="https://litepic.io/docs#gd" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="查看启用方法" aria-label="查看启用方法">
                                                <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">ImageMagick 扩展</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> <?= $compression_capability['imagick'] ? 'is-on' : 'is-off' ?>" id="metricCapImagick">
                                            <?= $compression_capability['imagick'] ? '已启用' : '未启用' ?>
                                        </span>
                                        <?php if (!$compression_capability['imagick']): ?>
                                            <a href="https://litepic.io/docs#imagick" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="查看启用方法" aria-label="查看启用方法">
                                                <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">AVIF 支持</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> <?= $compression_capability['avif'] ? 'is-on' : 'is-off' ?>" id="metricCapAvif">
                                            <?= $compression_capability['avif'] ? '已启用' : '未启用' ?>
                                        </span>
                                        <?php if (!$compression_capability['avif']): ?>
                                            <a href="https://litepic.io/docs#avif" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="查看启用方法" aria-label="查看启用方法">
                                                <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">WebP 支持</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> <?= $compression_capability['webp'] ? 'is-on' : 'is-off' ?>" id="metricCapWebp">
                                            <?= $compression_capability['webp'] ? '已启用' : '未启用' ?>
                                        </span>
                                        <?php if (!$compression_capability['webp']): ?>
                                            <a href="https://litepic.io/docs#webp" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="查看启用方法" aria-label="查看启用方法">
                                                <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                                <article class="border border-border p-4 grid gap-2">
                                    <span class="text-sm text-gray">HEIC 支持</span>
                                    <div class="relative w-full">
                                        <span class="<?= $cap_badge_cls ?> <?= $compression_capability['heic'] ? 'is-on' : 'is-off' ?>" id="metricCapHeic">
                                            <?= $compression_capability['heic'] ? '已启用' : '未启用' ?>
                                        </span>
                                        <?php if (!$compression_capability['heic']): ?>
                                            <a href="https://litepic.io/docs#heic" target="_blank" rel="noopener noreferrer" class="<?= $cap_badge_help ?>" title="查看启用方法" aria-label="查看 HEIC 启用方法">
                                                <i class="fa-light fa-circle-question text-base" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </section>

<?php endif; // tab: basic (服务器信息) ?>

<?php if (in_array($active_settings_tab, ['basic'], true)): // 上传限制: 移到基础 tab ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
                                <span>上传限制</span>
                            </h3>
                            <p>单文件大小、批量队列数量、上传并发和允许上传的格式白名单</p>
                        </div>

                        <div class="settings-grid">
                            <div class="grid gap-2">
                                <label for="maxFileSizeMb">单文件上限（MB）</label>
                                <input id="maxFileSizeMb" type="number" name="max_file_size_mb" min="1" max="2048" step="1" value="<?= (int)round(MAX_FILE_SIZE / 1024 / 1024) ?>">
                                <span class="settings-field-hint">同时写入 .user.ini 的 upload_max_filesize；线上还需要 Web 服务器限制不低于该值。</span>
                            </div>
                            <div class="grid gap-2">
                                <label for="uploadMaxFiles">单次队列数量上限</label>
                                <input id="uploadMaxFiles" type="number" name="upload_max_files" min="1" max="500" step="1" value="<?= (int)(defined('UPLOAD_MAX_FILES') ? UPLOAD_MAX_FILES : 100) ?>">
                                <span class="settings-field-hint">限制一次选择 / 拖拽最多加入多少张，也会写入 PHP max_file_uploads。</span>
                            </div>
                            <div class="grid gap-2">
                                <label for="uploadMaxConcurrent">上传并发数</label>
                                <input id="uploadMaxConcurrent" type="number" name="upload_max_concurrent" min="1" max="20" step="1" value="<?= (int)(defined('UPLOAD_MAX_CONCURRENT') ? UPLOAD_MAX_CONCURRENT : 20) ?>">
                                <span class="settings-field-hint">推荐 2-4；服务器性能强可以调高，过高会增加网络错误概率。</span>
                            </div>
                            <div class="grid gap-2 col-span-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label for="uploadAllowedTypeNew">允许上传格式</label>
                                    <span class="settings-field-hint">输入扩展名后回车 / 逗号添加；点击 × 移除。可填任意图片格式（heic / jxl / raw / dng 等）；后端会校验文件内容必须是图片，自动拦截 .php / .html 等可执行文件</span>
                                </div>
                                <?php
                                // 标签编辑器 — 现有允许扩展名作为 chip，每个 chip 配
                                // 一个隐藏 input[name=upload_allowed_types[]] 让表单提交带上
                                $allowed_for_tags = array_values(array_unique(array_map(
                                    static fn($x) => strtolower(ltrim((string)$x, '.')),
                                    is_array(ALLOWED_UPLOAD_TYPES) ? ALLOWED_UPLOAD_TYPES : []
                                )));
                                $preset_for_tags = array_values(array_diff(SUPPORTED_IMAGE_TYPES, $allowed_for_tags));
                                ?>
                                <div class="settings-format-tags" data-format-tags>
                                    <div class="settings-format-tags__chips" data-format-tags-chips>
                                        <?php foreach ($allowed_for_tags as $type): ?>
                                            <span class="settings-format-tags__chip" data-format-tag-chip>
                                                <span>.<?= htmlspecialchars($type) ?></span>
                                                <input type="hidden" name="upload_allowed_types[]" value="<?= htmlspecialchars($type) ?>">
                                                <button type="button" class="settings-format-tags__remove" data-format-tag-remove aria-label="移除 <?= htmlspecialchars($type) ?>">
                                                    <i class="fa-light fa-xmark" aria-hidden="true"></i>
                                                </button>
                                            </span>
                                        <?php endforeach; ?>
                                        <input
                                            id="uploadAllowedTypeNew"
                                            type="text"
                                            class="settings-format-tags__input"
                                            data-format-tags-input
                                            placeholder="输入扩展名 + 回车，例如 webp"
                                            autocomplete="off"
                                            spellcheck="false"
                                            maxlength="10">
                                    </div>
                                    <?php if (!empty($preset_for_tags)): ?>
                                        <div class="settings-format-tags__presets" data-format-tags-presets>
                                            <span class="settings-format-tags__presets-label">快速添加：</span>
                                            <?php foreach ($preset_for_tags as $type): ?>
                                                <button type="button" class="settings-format-tags__preset" data-format-tag-preset value="<?= htmlspecialchars($type) ?>">
                                                    + .<?= htmlspecialchars($type) ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>
<?php endif; // tab: basic (上传限制) ?>

<?php if (in_array($active_settings_tab, ['system'], true)): ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-rotate" aria-hidden="true"></i>
                                <span>程序更新</span>
                            </h3>
                            <p>连接版本服务器获取新版安装包，只替换程序文件，保留数据库、图片、配置和用户上传内容</p>
                        </div>

                        <div class="db-meta-badges">
                            <span class="db-badge">
                                <i class="fa-light fa-code-branch" aria-hidden="true"></i>
                                <span class="db-badge__label">当前版本</span>
                                <code class="db-badge__value">v<?= htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8') ?></code>
                            </span>
                            <span class="db-badge" data-update-latest-badge>
                                <i class="fa-light fa-cloud-arrow-down" aria-hidden="true"></i>
                                <span class="db-badge__label">最新版本</span>
                                <code class="db-badge__value" data-update-latest>未检查</code>
                            </span>
                            <span class="db-badge">
                                <i class="fa-light fa-shield-check" aria-hidden="true"></i>
                                <span class="db-badge__label">保护数据</span>
                                <code class="db-badge__value">data / <?= htmlspecialchars(defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads') ?> / .env</code>
                            </span>
                            <span class="db-badge">
                                <i class="fa-light fa-box-archive" aria-hidden="true"></i>
                                <span class="db-badge__label">更新备份</span>
                                <code class="db-badge__value">data/update-backups</code>
                            </span>
                        </div>

                        <p class="cleanup-note">
                            <i class="fa-light fa-circle-info" aria-hidden="true"></i>
                            <span>更新前会生成程序文件快照；更新过程不会覆盖 <code>.env</code>、<code>.user.ini</code>、<code>data/</code>、<code><?= htmlspecialchars(defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads') ?>/</code>、<code>logs/</code> 和 <code>static/images/</code>。如服务器未启用 ZipArchive 或站点根目录不可写，会自动停止。</span>
                        </p>

                        <div class="cleanup-toolbar" data-update-panel>
                            <button type="button" class="btn btn--secondary" data-update-check>
                                <i class="fa-light fa-magnifying-glass" aria-hidden="true"></i>
                                <span>检查更新</span>
                            </button>
                            <button type="button" class="btn btn--primary" data-update-install disabled>
                                <i class="fa-light fa-cloud-arrow-down" aria-hidden="true"></i>
                                <span>立即更新</span>
                            </button>
                            <span class="cleanup-status" data-update-status>尚未检查更新</span>
                        </div>

                        <script>
                        (function() {
                            const root = document.currentScript.closest('section');
                            const checkBtn = root?.querySelector('[data-update-check]');
                            const installBtn = root?.querySelector('[data-update-install]');
                            const statusEl = root?.querySelector('[data-update-status]');
                            const latestEl = root?.querySelector('[data-update-latest]');
                            const csrf = window.CSRF_TOKEN || '';

                            const setStatus = (text, kind) => {
                                if (!statusEl) return;
                                statusEl.textContent = text;
                                statusEl.classList.remove('is-on', 'is-off', 'is-warn');
                                if (kind) statusEl.classList.add(kind);
                            };

                            const parseJson = async (resp) => {
                                const data = await resp.json().catch(() => ({}));
                                if (!resp.ok || data.status !== 'success') {
                                    throw new Error(data.message || `HTTP ${resp.status}`);
                                }
                                return data;
                            };

                            const runCheck = async (manual) => {
                                if (!checkBtn) return;
                                checkBtn.disabled = true;
                                if (installBtn) installBtn.disabled = true;
                                setStatus(manual ? '正在连接版本服务器...' : '自动检测更新中...');
                                try {
                                    const data = await fetch('/api/v1/update/check?_=' + Date.now(), {
                                        cache: 'no-store',
                                        credentials: 'same-origin',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                    }).then(parseJson);
                                    if (latestEl) latestEl.textContent = data.latest ? `v${data.latest}` : '未发现';
                                    if (data.has_update) {
                                        if (installBtn) installBtn.disabled = false;
                                        setStatus(`发现新版本 v${data.latest}`, 'is-warn');
                                    } else if (data.current_ahead) {
                                        setStatus(`当前版本 v${data.current} 高于服务器最新版本 v${data.latest}`, 'is-warn');
                                    } else {
                                        setStatus('当前已经是最新版本', 'is-on');
                                    }
                                } catch (e) {
                                    setStatus('检查失败：' + (e.message || '未知错误'), 'is-off');
                                } finally {
                                    checkBtn.disabled = false;
                                }
                            };

                            checkBtn?.addEventListener('click', () => runCheck(true));
                            // 自动检测 —— 设置页打开即查一次(后端 6h 缓存,廉价,无需手动点)
                            runCheck(false);

                            installBtn?.addEventListener('click', async () => {
                                const ok = window.ImgEt?.DialogManager?.confirm
                                    ? await window.ImgEt.DialogManager.confirm('立即更新 LitePic', '更新时会短暂进入维护模式，并自动保护数据库、图片和配置文件。确认继续吗？')
                                    : confirm('确定立即更新 LitePic？更新时会短暂进入维护模式，并自动保护数据库、图片和配置文件。');
                                if (!ok) return;
                                checkBtn.disabled = true;
                                installBtn.disabled = true;
                                installBtn.innerHTML = '<i class="fa-light fa-spinner fa-spin" aria-hidden="true"></i><span>更新中...</span>';
                                setStatus('正在下载并替换程序文件，请勿关闭页面...');
                                try {
                                    const data = await fetch('/api/v1/update/install', {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest',
                                            'X-CSRF-Token': csrf,
                                        },
                                    }).then(parseJson);
                                    latestEl.textContent = data.latest ? `v${data.latest}` : '已更新';
                                    setStatus(data.message || '更新完成，正在刷新页面...', 'is-on');
                                    setTimeout(() => window.location.reload(), 1200);
                                } catch (e) {
                                    setStatus('更新失败：' + (e.message || '未知错误'), 'is-off');
                                    checkBtn.disabled = false;
                                    installBtn.disabled = false;
                                    installBtn.innerHTML = '<i class="fa-light fa-cloud-arrow-down" aria-hidden="true"></i><span>立即更新</span>';
                                }
                            });
                        })();
                        </script>
                    </section>

                    <?php
                    // Database summary — list every SQLite table + row count, file size, schema version
                    $db_summary = (new \LitePic\Service\Stats\ServerInfo())->databaseSummary();
                    ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-database" aria-hidden="true"></i>
                                <span>SQLite 数据库</span>
                            </h3>
                            <p>所有应用状态（设置、图片元数据、Token、Passkey、压缩 Key 等）都存在这一个文件里</p>
                        </div>

                        <div class="db-meta-badges">
                            <span class="db-badge" title="<?= htmlspecialchars($db_summary['path']) ?>">
                                <i class="fa-light fa-folder-open" aria-hidden="true"></i>
                                <span class="db-badge__label">文件路径</span>
                                <code class="db-badge__value"><?= htmlspecialchars(basename($db_summary['path'])) ?></code>
                            </span>
                            <span class="db-badge">
                                <i class="fa-light fa-hard-drive" aria-hidden="true"></i>
                                <span class="db-badge__label">文件大小</span>
                                <code class="db-badge__value"><?= htmlspecialchars($db_summary['size_text']) ?></code>
                            </span>
                            <span class="db-badge">
                                <i class="fa-light fa-tag" aria-hidden="true"></i>
                                <span class="db-badge__label">Schema 版本</span>
                                <code class="db-badge__value">v<?= $db_summary['schema_version'] !== null ? (int)$db_summary['schema_version'] : '?' ?></code>
                            </span>
                            <span class="db-badge">
                                <i class="fa-light fa-bolt" aria-hidden="true"></i>
                                <span class="db-badge__label">Journal Mode</span>
                                <code class="db-badge__value"><?= htmlspecialchars((string)($db_summary['journal_mode'] ?? '-')) ?></code>
                            </span>
                        </div>

                        <div class="overflow-auto border">
                            <table class="w-full">
                                <thead>
                                    <tr>
                                        <th class="text-left text-sm">表</th>
                                        <th class="text-right text-sm">行数</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($db_summary['tables'])): ?>
                                        <tr>
                                            <td colspan="2" class="text-sm text-gray">数据库尚未初始化</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($db_summary['tables'] as $tbl): ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($tbl['name']) ?></code></td>
                                                <td class="text-right"><?= number_format($tbl['rows']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <p class="m-0 text-xs text-gray">
                            备份方法：直接复制 <code><?= htmlspecialchars(basename($db_summary['path'])) ?></code> + <code><?= htmlspecialchars(defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads') ?>/</code> 目录即可。
                            下方「数据库备份」section 提供 UI 备份 / 恢复 / 自动定时 / R2 同步。
                        </p>
                    </section>

                    <?php
                    // Database backup management — manual backup + schedule + R2 sync
                    $_backup_svc = new \LitePic\Service\Backup\DatabaseBackup();
                    $_backups = $_backup_svc->listLocalBackups();
                    $_backup_enabled = $_backup_svc->isScheduleEnabled();
                    $_backup_interval_h = (new \LitePic\Repository\SettingsRepository())
                        ->getInt(\LitePic\Service\Backup\DatabaseBackup::SETTING_INTERVAL_HOURS,
                                 \LitePic\Service\Backup\DatabaseBackup::DEFAULT_INTERVAL_HOURS);
                    $_backup_keep = $_backup_svc->keepCount();
                    $_backup_to_remote = $_backup_svc->syncToRemote();
                    $_backup_remote_enabled = (new \LitePic\Service\Storage\RemoteStorage())->isEnabled();
                    $_last_backup_at = $_backup_svc->lastRunAt();
                    $_last_backup_text = $_last_backup_at > 0
                        ? date('Y-m-d H:i:s', $_last_backup_at) . '（' . max(0, time() - $_last_backup_at) . ' 秒前）'
                        : '从未运行';
                    ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-shield-check" aria-hidden="true"></i>
                                <span>数据库备份</span>
                            </h3>
                            <p>用 SQLite VACUUM INTO 生成一致性快照（无需停机），可定时执行 + 自动同步到 R2/S3 做异地容灾</p>
                        </div>

                        <div class="settings-toggle-list">
                            <label class="settings-toggle-row" for="dbBackupEnabled">
                                <span class="settings-toggle-copy">启用自动定时备份</span>
                                <input id="dbBackupEnabled" data-backup-input type="checkbox" class="settings-switch-input"
                                       <?= $_backup_enabled ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <label class="settings-toggle-row" for="dbBackupToRemote">
                                <span class="settings-toggle-copy">
                                    备份完成后同步到云端（R2 / S3）
                                    <?php if (!$_backup_remote_enabled): ?>
                                        <small class="text-gray">— 当前未配置远程存储，开启此项也不会生效</small>
                                    <?php endif; ?>
                                </span>
                                <input id="dbBackupToRemote" data-backup-input type="checkbox" class="settings-switch-input"
                                       <?= $_backup_to_remote ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                        </div>

                        <div class="settings-grid">
                            <div class="grid gap-2">
                                <label for="dbBackupIntervalHours">备份间隔（小时）</label>
                                <select id="dbBackupIntervalHours" data-backup-input>
                                    <option value="1"   <?= $_backup_interval_h === 1   ? 'selected' : '' ?>>每小时</option>
                                    <option value="6"   <?= $_backup_interval_h === 6   ? 'selected' : '' ?>>每 6 小时</option>
                                    <option value="12"  <?= $_backup_interval_h === 12  ? 'selected' : '' ?>>每 12 小时</option>
                                    <option value="24"  <?= $_backup_interval_h === 24  ? 'selected' : '' ?>>每天（推荐）</option>
                                    <option value="72"  <?= $_backup_interval_h === 72  ? 'selected' : '' ?>>每 3 天</option>
                                    <option value="168" <?= $_backup_interval_h === 168 ? 'selected' : '' ?>>每周</option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <label for="dbBackupKeepCount">本地保留份数</label>
                                <select id="dbBackupKeepCount" data-backup-input>
                                    <?php foreach ([3, 7, 14, 30, 60] as $_n): ?>
                                        <option value="<?= $_n ?>" <?= $_backup_keep === $_n ? 'selected' : '' ?>>
                                            最近 <?= $_n ?> 份（超出自动删旧的）
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex gap-2 items-center flex-wrap">
                            <button type="button" class="btn btn--primary" data-backup-create>
                                <i class="fa-light fa-shield-plus"></i>
                                <span>立即备份</span>
                            </button>
                            <span class="text-sm text-gray" data-backup-status>
                                上次运行：<?= htmlspecialchars($_last_backup_text) ?>
                            </span>
                        </div>

                        <details class="settings-queue-failed" <?= empty($_backups) ? '' : 'open' ?>>
                            <summary>
                                <span>本地备份文件</span>
                                <span class="text-sm text-gray">
                                    （<?= count($_backups) ?> 份）
                                </span>
                            </summary>
                            <?php if (empty($_backups)): ?>
                                <p class="text-sm text-gray m-0 py-3">还没有备份。点上面的「立即备份」按钮试一下。</p>
                            <?php else: ?>
                                <div class="overflow-auto border" data-backup-table>
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th class="text-left text-sm">文件名</th>
                                                <th class="text-left text-sm">大小</th>
                                                <th class="text-left text-sm">创建时间</th>
                                                <th class="text-right text-sm">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($_backups as $_b): ?>
                                                <tr data-backup-row="<?= htmlspecialchars($_b['name']) ?>">
                                                    <td><code><?= htmlspecialchars($_b['name']) ?></code></td>
                                                    <td class="text-sm"><?= htmlspecialchars($_b['size_text']) ?></td>
                                                    <td class="text-sm text-gray"><?= htmlspecialchars($_b['mtime_text']) ?></td>
                                                    <td class="text-right">
                                                        <div class="backup-actions">
                                                            <button type="button" class="btn btn--secondary backup-action-btn"
                                                                    data-backup-restore="<?= htmlspecialchars($_b['name']) ?>"
                                                                    title="恢复（覆盖当前数据库）"
                                                                    aria-label="恢复">
                                                                <i class="fa-light fa-rotate-left" aria-hidden="true"></i>
                                                            </button>
                                                            <button type="button" class="btn btn--danger backup-action-btn"
                                                                    data-backup-delete="<?= htmlspecialchars($_b['name']) ?>"
                                                                    title="删除此备份"
                                                                    aria-label="删除">
                                                                <i class="fa-light fa-xmark" aria-hidden="true"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </details>

                        <p class="m-0 text-xs text-gray">
                            备份文件存放在 <code>data/backups/</code>，每份是完整的 SQLite 数据库（数十 KB - 数 MB），可直接用 sqlite3 / DB Browser 打开查看。
                            R2 同步时上传到 <code>backups/</code> 前缀，跟图片对象隔离。
                            <strong>恢复操作会覆盖当前数据库</strong>，恢复前请先做一份当前状态的备份。
                        </p>

                        <script>
                            (function () {
                                const status = document.querySelector('[data-backup-status]');
                                const setStatus = (text, isError = false) => {
                                    if (!status) return;
                                    status.textContent = text;
                                    status.style.color = isError ? '#d73a49' : '';
                                };

                                const reloadTab = () => {
                                    if (window.Pjax && typeof Pjax.go === 'function') {
                                        Pjax.go(window.location.href);
                                    } else {
                                        window.location.reload();
                                    }
                                };

                                // 配置变更 — 任何 data-backup-input 改动 → 立即 POST /backup/config
                                document.querySelectorAll('[data-backup-input]').forEach((el) => {
                                    el.addEventListener('change', async () => {
                                        const payload = {
                                            enabled:        document.getElementById('dbBackupEnabled').checked,
                                            to_remote:      document.getElementById('dbBackupToRemote').checked,
                                            interval_hours: parseInt(document.getElementById('dbBackupIntervalHours').value, 10),
                                            keep_count:     parseInt(document.getElementById('dbBackupKeepCount').value, 10),
                                        };
                                        try {
                                            const resp = await fetch('/api/v1/backup/config', {
                                                method: 'POST',
                                                credentials: 'same-origin',
                                                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                body: JSON.stringify(payload),
                                            });
                                            const data = await resp.json();
                                            if (data.status !== 'success') throw new Error(data.message || '失败');
                                            setStatus('备份设置已保存');
                                        } catch (e) {
                                            setStatus('保存失败：' + e.message, true);
                                        }
                                    });
                                });

                                // 立即备份
                                const createBtn = document.querySelector('[data-backup-create]');
                                createBtn?.addEventListener('click', async () => {
                                    createBtn.disabled = true;
                                    createBtn.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i><span>备份中...</span>';
                                    setStatus('正在执行 VACUUM INTO...');
                                    try {
                                        const resp = await fetch('/api/v1/backup/create', {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                        });
                                        const data = await resp.json();
                                        if (data.status !== 'success') throw new Error(data.message || '失败');
                                        const remote = data.remote_key ? `（已同步到云端：${data.remote_key}）` : '';
                                        setStatus(`备份完成：${data.name}${remote}`);
                                        setTimeout(reloadTab, 800);   // 刷新看到新行
                                    } catch (e) {
                                        setStatus('备份失败：' + e.message, true);
                                    } finally {
                                        createBtn.disabled = false;
                                        createBtn.innerHTML = '<i class="fa-light fa-shield-plus"></i><span>立即备份</span>';
                                    }
                                });

                                // 单条恢复
                                document.querySelectorAll('[data-backup-restore]').forEach((btn) => {
                                    btn.addEventListener('click', async () => {
                                        const name = btn.getAttribute('data-backup-restore');
                                        const ok = window.ImgEt?.DialogManager?.confirm
                                            ? await window.ImgEt.DialogManager.confirm('恢复数据库备份', `确定从 ${name} 恢复数据库？这会覆盖当前的所有设置、图片元数据、Token 和 Passkey 等。恢复前建议先点「立即备份」保留当前状态。`)
                                            : confirm(`确定从 ${name} 恢复数据库？\n\n这会**覆盖**当前的所有设置 / 图片元数据 / Token / Passkey 等。\n\n恢复前建议先点「立即备份」保留当前状态。`);
                                        if (!ok) return;
                                        btn.disabled = true;
                                        try {
                                            const resp = await fetch('/api/v1/backup/restore?file=' + encodeURIComponent(name), {
                                                method: 'POST',
                                                credentials: 'same-origin',
                                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                            });
                                            const data = await resp.json();
                                            if (data.status !== 'success') throw new Error(data.message || '失败');
                                            if (window.ImgEt?.DialogManager?.alert) {
                                                await window.ImgEt.DialogManager.alert('恢复成功', '页面将刷新以使用新数据');
                                            } else {
                                                alert('恢复成功 — 页面将刷新以使用新数据');
                                            }
                                            window.location.reload();
                                        } catch (e) {
                                            setStatus('恢复失败：' + e.message, true);
                                            btn.disabled = false;
                                        }
                                    });
                                });

                                // 单条删除
                                document.querySelectorAll('[data-backup-delete]').forEach((btn) => {
                                    btn.addEventListener('click', async () => {
                                        const name = btn.getAttribute('data-backup-delete');
                                        const ok = window.ImgEt?.DialogManager?.confirm
                                            ? await window.ImgEt.DialogManager.confirm('删除数据库备份', `删除备份 ${name}？此操作不可撤销。`, { danger: true, confirmText: '删除' })
                                            : confirm(`删除备份 ${name}？此操作不可撤销。`);
                                        if (!ok) return;
                                        btn.disabled = true;
                                        try {
                                            const resp = await fetch('/api/v1/backup/delete?file=' + encodeURIComponent(name), {
                                                method: 'POST',
                                                credentials: 'same-origin',
                                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                            });
                                            const data = await resp.json();
                                            if (data.status !== 'success') throw new Error(data.message || '失败');
                                            btn.closest('tr')?.remove();
                                            setStatus(`已删除 ${name}`);
                                        } catch (e) {
                                            setStatus('删除失败：' + e.message, true);
                                            btn.disabled = false;
                                        }
                                    });
                                });
                            })();
                        </script>
                    </section>

                    <?php
                    /* ──────────────────────────────────────────────────────────
                     *  残留数据清理
                     *  保守策略 — 只删确定无引用的记录，绝不动磁盘文件 / 活动队列 /
                     *  设置 / Token / Passkey。详见 OrphanCleaner.php 类文档。
                     *  ───────────────────────────────────────────────────────── */
                    ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-broom" aria-hidden="true"></i>
                                <span>残留数据清理</span>
                            </h3>
                            <p>扫描 SQLite 里确定无引用的记录（孤儿图片行 / 已完成任务 / 过期挑战等），可选择性清除</p>
                        </div>

                        <div class="cleanup-block" data-cleanup-block>
                            <!-- 工具条：扫描 + 清理按钮 -->
                            <div class="cleanup-toolbar">
                                <button type="button" class="btn btn--secondary" data-cleanup-scan>
                                    <i class="fa-light fa-magnifying-glass" aria-hidden="true"></i>
                                    <span>扫描残留数据</span>
                                </button>
                                <button type="button" class="btn btn--primary" data-cleanup-run disabled>
                                    <i class="fa-light fa-broom" aria-hidden="true"></i>
                                    <span>清理选中类别</span>
                                </button>
                                <span class="cleanup-status" data-cleanup-status>未扫描</span>
                            </div>

                            <!-- 类别表格：扫描后填充 -->
                            <div class="cleanup-categories">
                                <table class="cleanup-table">
                                    <thead>
                                        <tr>
                                            <th class="cleanup-th-check"></th>
                                            <th>类别</th>
                                            <th>判定条件</th>
                                            <th class="cleanup-th-num">条数</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $cleanup_rows = [
                                            ['missing_files',      '孤儿图片记录',     '<code>images</code> 行的文件在磁盘已不存在'],
                                            ['done_queue',         '已完成的队列任务', '<code>import_queue</code> 状态 done 且超 7 天'],
                                            ['failed_queue',       '失败的队列任务',   '状态 failed 且 attempts ≥ 3 且超 30 天'],
                                            ['expired_attempts',   '过期登录尝试',     '<code>login_attempts</code> 超 24 小时且未在封禁'],
                                            ['expired_challenges', '过期 Passkey 挑战', '<code>webauthn_challenges</code> 已过期'],
                                        ];
                                        foreach ($cleanup_rows as [$key, $label, $cond]):
                                        ?>
                                        <tr data-cleanup-row data-cleanup-key="<?= htmlspecialchars($key) ?>">
                                            <td class="cleanup-td-check">
                                                <input type="checkbox" class="cleanup-checkbox" data-cleanup-checkbox value="<?= htmlspecialchars($key) ?>" checked>
                                            </td>
                                            <td><strong><?= htmlspecialchars($label) ?></strong></td>
                                            <td class="text-sm text-gray"><?= $cond ?></td>
                                            <td class="cleanup-td-num">
                                                <span class="cleanup-count" data-cleanup-count>—</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="cleanup-note">
                                <i class="fa-light fa-shield-check" aria-hidden="true"></i>
                                <span>不会删除磁盘上的图片文件、活动中的队列任务、用户设置、API Token、Passkey 凭据。<strong>清理动作不可撤销</strong>，建议先备份数据库。</span>
                            </p>
                        </div>

                        <script>
                        (function() {
                            const block = document.currentScript.previousElementSibling;
                            const scanBtn  = block.querySelector('[data-cleanup-scan]');
                            const runBtn   = block.querySelector('[data-cleanup-run]');
                            const statusEl = block.querySelector('[data-cleanup-status]');
                            const rows     = block.querySelectorAll('[data-cleanup-row]');
                            const counts   = {};
                            rows.forEach(r => counts[r.dataset.cleanupKey] = r.querySelector('[data-cleanup-count]'));

                            const csrf = window.CSRF_TOKEN || '';

                            function setStatus(text, kind) {
                                statusEl.textContent = text;
                                statusEl.classList.remove('is-on', 'is-off', 'is-warn');
                                if (kind) statusEl.classList.add(kind);
                            }

                            scanBtn.addEventListener('click', async () => {
                                scanBtn.disabled = true;
                                setStatus('扫描中…');
                                try {
                                    const res = await fetch('/api/v1/cleanup/scan', {
                                        method: 'POST',
                                        headers: { 'X-CSRF-Token': csrf },
                                    });
                                    const json = await res.json();
                                    if (json.status !== 'success') throw new Error(json.message || '扫描失败');
                                    // Response::success() flat-merges payload at top level, no `.data` wrapper
                                    const c = json.counts || {};
                                    Object.keys(counts).forEach(k => {
                                        counts[k].textContent = (c[k] ?? 0).toLocaleString();
                                        counts[k].classList.toggle('cleanup-count--has', (c[k] ?? 0) > 0);
                                    });
                                    const total = json.total || 0;
                                    if (total > 0) {
                                        setStatus(`共发现 ${total.toLocaleString()} 条残留`, 'is-warn');
                                        runBtn.disabled = false;
                                    } else {
                                        setStatus('数据库整洁，无需清理', 'is-on');
                                        runBtn.disabled = true;
                                    }
                                } catch (e) {
                                    setStatus('扫描失败：' + (e.message || '未知错误'), 'is-off');
                                } finally {
                                    scanBtn.disabled = false;
                                }
                            });

                            // 抽出实际清理逻辑 — 自定义对话框确认后调用
                            async function performCleanup(selected) {
                                runBtn.disabled = true;
                                setStatus('清理中…');
                                try {
                                    const res = await fetch('/api/v1/cleanup/run', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-Token': csrf,
                                        },
                                        body: JSON.stringify({ categories: selected }),
                                    });
                                    const json = await res.json();
                                    if (json.status !== 'success') throw new Error(json.message || '清理失败');
                                    Object.keys(counts).forEach(k => {
                                        counts[k].textContent = '0';
                                        counts[k].classList.remove('cleanup-count--has');
                                    });
                                    const errs = Object.keys(json.errors || {});
                                    if (errs.length > 0) {
                                        setStatus('部分类别失败：' + errs.join('、'), 'is-off');
                                    } else {
                                        setStatus(`已清理 ${(json.total || 0).toLocaleString()} 条`, 'is-on');
                                    }
                                } catch (e) {
                                    setStatus('清理失败：' + (e.message || '未知错误'), 'is-off');
                                } finally {
                                    runBtn.disabled = true; // 必须重新扫描才能再清
                                }
                            }

                            // 自定义清理确认对话框 — 用 LitePic 的 DialogManager.showCustomDialog
                            // 渲染一个带条目列表 + 安全提示 + 双按钮的卡片，替代原生 confirm()。
                            function openCleanupConfirm(selected) {
                                const escape = s => String(s).replace(/[&<>"']/g, c => ({
                                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                                })[c]);

                                const items = selected.map(k => {
                                    const row = block.querySelector(`[data-cleanup-key="${k}"]`);
                                    const label = row.querySelector('strong').textContent;
                                    const num = row.querySelector('[data-cleanup-count]').textContent;
                                    return `<li class="cleanup-confirm-item">
                                        <span class="cleanup-confirm-label">${escape(label)}</span>
                                        <strong class="cleanup-confirm-num">${escape(num)} 条</strong>
                                    </li>`;
                                }).join('');

                                const content = `
                                    <div class="cleanup-confirm">
                                        <p class="cleanup-confirm-lead">
                                            即将从数据库中永久删除以下记录，此操作 <strong>不可撤销</strong>：
                                        </p>
                                        <ul class="cleanup-confirm-list">
                                            ${items}
                                        </ul>
                                        <p class="cleanup-confirm-safe">
                                            <i class="fa-light fa-shield-check" aria-hidden="true"></i>
                                            <span>不会删除磁盘上的图片文件、活动队列、用户设置、API Token 或 Passkey 凭据。</span>
                                        </p>
                                        <div class="cleanup-confirm-actions">
                                            <button type="button" class="btn btn--secondary" data-cleanup-confirm-cancel>
                                                <span>取消</span>
                                            </button>
                                            <button type="button" class="btn btn--primary cleanup-confirm-submit" data-cleanup-confirm-ok>
                                                <i class="fa-light fa-broom" aria-hidden="true"></i>
                                                <span>确认清理</span>
                                            </button>
                                        </div>
                                    </div>
                                `;

                                window.ImgEt.DialogManager.showCustomDialog('确认清理残留数据', content);

                                // 绑定按钮事件 — 拿最新创建的 .custom-dialog
                                const dialog = document.querySelector('.custom-dialog:last-of-type') || document.querySelector('.custom-dialog');
                                if (!dialog) return;
                                const close = () => {
                                    if (typeof dialog.closeHandler === 'function') {
                                        dialog.closeHandler();
                                    } else {
                                        dialog.classList.remove('active');
                                        setTimeout(() => dialog.remove(), 300);
                                    }
                                };
                                dialog.querySelector('[data-cleanup-confirm-cancel]')
                                    ?.addEventListener('click', close);
                                dialog.querySelector('[data-cleanup-confirm-ok]')
                                    ?.addEventListener('click', () => {
                                        close();
                                        performCleanup(selected);
                                    });
                            }

                            runBtn.addEventListener('click', () => {
                                const selected = Array.from(block.querySelectorAll('[data-cleanup-checkbox]:checked'))
                                    .map(cb => cb.value);
                                if (selected.length === 0) {
                                    setStatus('请至少勾选一个类别', 'is-warn');
                                    return;
                                }
                                openCleanupConfirm(selected);
                            });
                        })();
                        </script>
                    </section>
<?php endif; // tab: system ?>

<?php if (in_array($active_settings_tab, ['tasks'], true)): ?>
                    <?php
                    // Image-processing queue monitor — depth, failures, last drain summary
                    $_queue = new \LitePic\Repository\ImportQueueRepository();
                    $_settings_repo = new \LitePic\Repository\SettingsRepository();
                    $_queue_pending = $_queue->pendingCount();
                    $_queue_failed = $_queue->failedCount();
                    $_last_run = $_settings_repo->getJson('worker_last_run', null);
                    $_last_run_text = '从未运行';
                    if (is_array($_last_run) && !empty($_last_run['finished_at'])) {
                        $_age = max(0, time() - (int)$_last_run['finished_at']);
                        $_age_text = $_age < 60
                            ? $_age . ' 秒前'
                            : ($_age < 3600 ? round($_age / 60) . ' 分钟前' : round($_age / 3600, 1) . ' 小时前');
                        $_src_label = ($_last_run['source'] ?? '') === 'cron' ? 'cron' : '手动 / 上传后';
                        $_last_run_text = sprintf(
                            '%s（处理 %d，失败 %d，跳过 %d，耗时 %d ms，来源 %s）',
                            $_age_text,
                            (int)($_last_run['processed'] ?? 0),
                            (int)($_last_run['failed'] ?? 0),
                            (int)($_last_run['skipped'] ?? 0),
                            (int)($_last_run['elapsed_ms'] ?? 0),
                            $_src_label
                        );
                    }
                    ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-list-check" aria-hidden="true"></i>
                                <span>图片处理队列</span>
                            </h3>
                            <p>上传后的缩略图 / 压缩 / WebP / AVIF / 水印 / 远程同步任务在这里排队，由后台 worker 异步处理</p>
                        </div>

                        <div class="queue-stats">
                            <article class="queue-stat<?= $_queue_pending > 0 ? ' queue-stat--active' : '' ?>">
                                <i class="fa-light fa-list-check queue-stat__icon" aria-hidden="true"></i>
                                <div class="queue-stat__copy">
                                    <span class="queue-stat__value" data-queue-pending><?= number_format($_queue_pending) ?></span>
                                    <span class="queue-stat__label">队列深度（待处理）</span>
                                </div>
                            </article>
                            <article class="queue-stat<?= $_queue_failed > 0 ? ' queue-stat--alert' : '' ?>">
                                <i class="fa-light fa-triangle-exclamation queue-stat__icon" aria-hidden="true"></i>
                                <div class="queue-stat__copy">
                                    <span class="queue-stat__value" data-queue-failed><?= number_format($_queue_failed) ?></span>
                                    <span class="queue-stat__label">失败任务（已重试 ≥ 1 次）</span>
                                </div>
                            </article>
                            <article class="queue-stat queue-stat--wide">
                                <i class="fa-light fa-clock-rotate-left queue-stat__icon" aria-hidden="true"></i>
                                <div class="queue-stat__copy">
                                    <span class="queue-stat__value queue-stat__value--text" data-queue-lastrun><?= htmlspecialchars($_last_run_text) ?></span>
                                    <span class="queue-stat__label">上次 worker 运行</span>
                                </div>
                            </article>
                        </div>

                        <div class="flex gap-2 items-center">
                            <button type="button" class="btn btn--primary" data-queue-drain-now>
                                <i class="fa-light fa-play"></i>
                                <span>立即处理队列</span>
                            </button>
                            <button type="button" class="btn btn--secondary" data-queue-refresh>
                                <i class="fa-light fa-rotate"></i>
                                <span>刷新状态</span>
                            </button>
                            <span class="text-sm text-gray" data-queue-drain-status></span>
                        </div>

                        <p class="m-0 text-xs text-gray">
                            正常情况下不需要点这个按钮 — 每次上传成功后 PHP 会在响应送达后自动 drain 一次。
                            想保险的话给服务器加一行 cron：
                            <code>* * * * * cd <?= htmlspecialchars(dirname(__DIR__, 2)) ?> &amp;&amp; php worker.php &gt;&gt; logs/worker.log 2&gt;&amp;1</code>
                        </p>

                        <?php
                        // 失败任务面板 — 仅在有失败任务时展开，没有就显示一行 "暂无失败任务"
                        $_failed_items = $_queue_failed > 0 ? $_queue->failedItems(50) : [];
                        ?>
                        <details class="settings-queue-failed" <?= $_queue_failed > 0 ? 'open' : '' ?>>
                            <summary>
                                <span>失败任务列表</span>
                                <span class="text-sm text-gray">
                                    （<?= number_format($_queue_failed) ?> 条）
                                </span>
                            </summary>
                            <?php if (empty($_failed_items)): ?>
                                <p class="text-sm text-gray m-0 py-3">暂无失败任务，所有上传都顺利处理完成。</p>
                            <?php else: ?>
                                <div class="flex gap-2 items-center py-2">
                                    <button type="button" class="btn btn--secondary" data-queue-retry-all>
                                        <i class="fa-light fa-rotate-right"></i>
                                        <span>全部重试</span>
                                    </button>
                                    <button type="button" class="btn btn--danger" data-queue-discard-all
                                            data-confirm="确认丢弃全部 <?= number_format(count($_failed_items)) ?> 条失败任务？此操作不可撤销。">
                                        <i class="fa-light fa-trash-can-list"></i>
                                        <span>全部丢弃</span>
                                    </button>
                                </div>

                                <div class="overflow-auto border" data-queue-failed-table>
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th class="text-left text-sm">图片</th>
                                                <th class="text-left text-sm">尝试次数</th>
                                                <th class="text-left text-sm">最近错误</th>
                                                <th class="text-left text-sm">更新时间</th>
                                                <th class="text-right text-sm">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($_failed_items as $item): ?>
                                                <?php $_short_err = mb_strimwidth((string)($item['last_error'] ?? ''), 0, 60, '…'); ?>
                                                <tr data-failed-row="<?= (int)$item['id'] ?>">
                                                    <td title="<?= htmlspecialchars((string)$item['filename']) ?>">
                                                        <code><?= htmlspecialchars(basename((string)$item['filename'])) ?></code>
                                                    </td>
                                                    <td><?= (int)$item['attempts'] ?></td>
                                                    <td title="<?= htmlspecialchars((string)($item['last_error'] ?? '')) ?>" class="text-sm">
                                                        <?= htmlspecialchars($_short_err ?: '—') ?>
                                                    </td>
                                                    <td class="text-sm text-gray">
                                                        <?= htmlspecialchars(date('m-d H:i', (int)$item['updated_at'])) ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <button type="button" class="btn btn--secondary" data-queue-retry-one="<?= (int)$item['id'] ?>" title="重试">
                                                            <i class="fa-light fa-rotate-right"></i>
                                                        </button>
                                                        <button type="button" class="btn btn--danger" data-queue-discard-one="<?= (int)$item['id'] ?>" title="丢弃">
                                                            <i class="fa-light fa-xmark"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </details>

                        <script>
                            /*
                             * 任务 tab 队列监控 — 全 AJAX，不刷新页面。
                             *
                             * 三个 fetch 路径：
                             *   POST /api/v1/queue/drain           立即处理
                             *   GET  /api/v1/queue/failed          刷新所有指标 + 失败列表
                             *   POST /api/v1/queue/{retry,discard,retry-all,discard-all-failed}
                             *
                             * 所有改变都通过 applyQueueState() 集中应用到 DOM：
                             *   • 三张 KPI 卡片（数字 + active/alert 颜色）
                             *   • 失败任务详情面板（数量 + 表格内容 + 全部按钮可见性）
                             *   • lastrun 文本
                             *
                             * 不再调 Pjax.go() 重新拉整个 tab — 所有更新原地完成。
                             */
                            (function () {
                                const root = document.querySelector('[data-pjax-container].settings-page');
                                if (!root) return;

                                const drainBtn = root.querySelector('[data-queue-drain-now]');
                                const refreshBtn = root.querySelector('[data-queue-refresh]');
                                if (!drainBtn || !refreshBtn) return;

                                const status = root.querySelector('[data-queue-drain-status]');
                                const pendingEl = root.querySelector('[data-queue-pending]');
                                const failedEl = root.querySelector('[data-queue-failed]');
                                const lastEl = root.querySelector('[data-queue-lastrun]');

                                const setStatus = (text, isError = false) => {
                                    if (!status) return;
                                    status.textContent = text;
                                    status.style.color = isError ? '#d73a49' : '';
                                };

                                const escapeHtml = (s) => String(s ?? '')
                                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                                /**
                                 * Pretty "N 秒前 / N 分钟前 / N 小时前" relative time.
                                 */
                                const ageText = (ts) => {
                                    if (!ts) return '从未运行';
                                    const age = Math.max(0, Math.floor(Date.now() / 1000) - ts);
                                    if (age < 60) return age + ' 秒前';
                                    if (age < 3600) return Math.round(age / 60) + ' 分钟前';
                                    return (age / 3600).toFixed(1) + ' 小时前';
                                };

                                /**
                                 * Apply a snapshot of queue state to the DOM in place.
                                 * Called after every action that may have changed the queue.
                                 */
                                const applyQueueState = (snapshot) => {
                                    const pending = Number(snapshot.pending || 0);
                                    const failed  = Number(snapshot.failed  || 0);
                                    const items   = Array.isArray(snapshot.items) ? snapshot.items : [];
                                    const lastRun = snapshot.last_run || null;

                                    // KPI cards: numbers + active/alert highlighting
                                    if (pendingEl) {
                                        pendingEl.textContent = pending.toLocaleString();
                                        pendingEl.closest('.queue-stat')?.classList.toggle('queue-stat--active', pending > 0);
                                    }
                                    if (failedEl) {
                                        failedEl.textContent = failed.toLocaleString();
                                        failedEl.closest('.queue-stat')?.classList.toggle('queue-stat--alert', failed > 0);
                                    }
                                    if (lastEl && lastRun) {
                                        const src = (lastRun.source === 'cron') ? 'cron' : '手动 / 上传后';
                                        lastEl.textContent = `${ageText(lastRun.finished_at)}（处理 ${lastRun.processed || 0}，失败 ${lastRun.failed || 0}，跳过 ${lastRun.skipped || 0}，耗时 ${lastRun.elapsed_ms || 0} ms，来源 ${src}）`;
                                    }

                                    // Failed-tasks panel — re-render summary count + table
                                    const summaryCount = root.querySelector('.settings-queue-failed > summary > .text-gray');
                                    if (summaryCount) summaryCount.textContent = `（${failed.toLocaleString()} 条）`;

                                    const details = root.querySelector('.settings-queue-failed');
                                    if (!details) return;

                                    // Drop and rebuild everything below <summary>
                                    Array.from(details.children).forEach((c) => {
                                        if (c.tagName !== 'SUMMARY') c.remove();
                                    });

                                    if (items.length === 0) {
                                        const empty = document.createElement('p');
                                        empty.className = 'text-sm text-gray m-0 py-3';
                                        empty.textContent = '暂无失败任务，所有上传都顺利处理完成。';
                                        details.appendChild(empty);
                                        return;
                                    }

                                    // Bulk actions row + table
                                    details.insertAdjacentHTML('beforeend', `
                                        <div class="flex gap-2 items-center py-2">
                                            <button type="button" class="btn btn--secondary" data-queue-retry-all>
                                                <i class="fa-light fa-rotate-right"></i><span>全部重试</span>
                                            </button>
                                            <button type="button" class="btn btn--danger" data-queue-discard-all
                                                    data-confirm="确认丢弃全部 ${items.length} 条失败任务？此操作不可撤销。">
                                                <i class="fa-light fa-trash-can-list"></i><span>全部丢弃</span>
                                            </button>
                                        </div>
                                        <div class="overflow-auto border" data-queue-failed-table>
                                            <table class="w-full">
                                                <thead><tr>
                                                    <th class="text-left text-sm">图片</th>
                                                    <th class="text-left text-sm">尝试次数</th>
                                                    <th class="text-left text-sm">最近错误</th>
                                                    <th class="text-left text-sm">更新时间</th>
                                                    <th class="text-right text-sm">操作</th>
                                                </tr></thead>
                                                <tbody>${items.map((it) => {
                                                    const shortErr = (it.last_error || '').slice(0, 60) + ((it.last_error || '').length > 60 ? '…' : '');
                                                    const mtime = new Date((it.updated_at || 0) * 1000);
                                                    const mtimeText = isNaN(mtime) ? '—' : mtime.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
                                                    return `<tr data-failed-row="${it.id}">
                                                        <td title="${escapeHtml(it.filename)}"><code>${escapeHtml(it.filename.split('/').pop())}</code></td>
                                                        <td>${it.attempts || 0}</td>
                                                        <td title="${escapeHtml(it.last_error || '')}" class="text-sm">${escapeHtml(shortErr || '—')}</td>
                                                        <td class="text-sm text-gray">${escapeHtml(mtimeText)}</td>
                                                        <td class="text-right">
                                                            <button type="button" class="btn btn--secondary" data-queue-retry-one="${it.id}" title="重试"><i class="fa-light fa-rotate-right"></i></button>
                                                            <button type="button" class="btn btn--danger" data-queue-discard-one="${it.id}" title="丢弃"><i class="fa-light fa-xmark"></i></button>
                                                        </td>
                                                    </tr>`;
                                                }).join('')}</tbody>
                                            </table>
                                        </div>
                                    `);

                                    // Open the details panel if it had been collapsed but now there's content
                                    if (failed > 0) details.open = true;
                                };

                                /**
                                 * Pull the latest queue snapshot and apply it to the UI.
                                 * Used by 「刷新状态」 and after every retry/discard.
                                 */
                                const refreshQueueState = async () => {
                                    const resp = await fetch('/api/v1/queue/failed', {
                                        credentials: 'same-origin',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                                    });
                                    const data = await resp.json();
                                    if (data.status !== 'success') throw new Error(data.message || '刷新失败');
                                    applyQueueState(data);
                                };

                                /** Generic POST helper for queue actions — returns parsed JSON. */
                                const postQueue = async (path) => {
                                    const resp = await fetch(path, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                    });
                                    const data = await resp.json().catch(() => ({}));
                                    if (data.status !== 'success') throw new Error(data.message || '请求失败');
                                    return data;
                                };

                                // 立即处理队列
                                drainBtn.addEventListener('click', async () => {
                                    drainBtn.disabled = true;
                                    drainBtn.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i><span>处理中...</span>';
                                    setStatus('正在调用 /api/v1/queue/drain...');
                                    try {
                                        const data = await postQueue('/api/v1/queue/drain');
                                        const d = data.drain || {};
                                        setStatus(`完成 — 处理 ${d.processed || 0}，失败 ${d.failed || 0}，跳过 ${d.skipped || 0}，耗时 ${d.elapsed_ms || 0} ms（队列 ${data.pending_before} → ${data.pending_after}）`);
                                        // drain 完成后拉一次失败列表，把所有 KPI + 表格刷新
                                        await refreshQueueState();
                                    } catch (e) {
                                        setStatus('出错：' + (e.message || e), true);
                                    } finally {
                                        drainBtn.disabled = false;
                                        drainBtn.innerHTML = '<i class="fa-light fa-play"></i><span>立即处理队列</span>';
                                    }
                                });

                                // 刷新状态 — 仅拉数据，不动整个 tab DOM
                                refreshBtn.addEventListener('click', async () => {
                                    refreshBtn.disabled = true;
                                    refreshBtn.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i><span>刷新中...</span>';
                                    setStatus('正在刷新...');
                                    try {
                                        await refreshQueueState();
                                        setStatus('已刷新');
                                    } catch (e) {
                                        setStatus('刷新失败：' + e.message, true);
                                    } finally {
                                        refreshBtn.disabled = false;
                                        refreshBtn.innerHTML = '<i class="fa-light fa-rotate"></i><span>刷新状态</span>';
                                    }
                                });

                                /*
                                 * 失败任务表的按钮 — 用事件委托绑在容器上而不是逐个 listener，
                                 * 这样 refreshQueueState() 重建表格后新行的按钮也会自动响应。
                                 */
                                const detailsRoot = root.querySelector('.settings-queue-failed');
                                detailsRoot?.addEventListener('click', async (e) => {
                                    const retryOne = e.target.closest('[data-queue-retry-one]');
                                    const discardOne = e.target.closest('[data-queue-discard-one]');
                                    const retryAll = e.target.closest('[data-queue-retry-all]');
                                    const discardAll = e.target.closest('[data-queue-discard-all]');

                                    if (retryOne) {
                                        const id = retryOne.getAttribute('data-queue-retry-one');
                                        retryOne.disabled = true;
                                        try {
                                            await postQueue('/api/v1/queue/retry?id=' + encodeURIComponent(id));
                                            setStatus('已加入待处理队列，下次 drain 会重试');
                                            await refreshQueueState();
                                        } catch (err) {
                                            setStatus('重试失败：' + err.message, true);
                                            retryOne.disabled = false;
                                        }
                                    } else if (discardOne) {
                                        const ok = window.ImgEt?.DialogManager?.confirm
                                            ? await window.ImgEt.DialogManager.confirm('丢弃失败任务', '确认丢弃此失败任务？', { danger: true, confirmText: '丢弃' })
                                            : confirm('确认丢弃此失败任务？');
                                        if (!ok) return;
                                        const id = discardOne.getAttribute('data-queue-discard-one');
                                        discardOne.disabled = true;
                                        try {
                                            await postQueue('/api/v1/queue/discard?id=' + encodeURIComponent(id));
                                            setStatus('已丢弃');
                                            await refreshQueueState();
                                        } catch (err) {
                                            setStatus('丢弃失败：' + err.message, true);
                                            discardOne.disabled = false;
                                        }
                                    } else if (retryAll) {
                                        retryAll.disabled = true;
                                        try {
                                            const data = await postQueue('/api/v1/queue/retry-all');
                                            setStatus(`已重置 ${data.retried || 0} 个失败任务`);
                                            await refreshQueueState();
                                        } catch (err) {
                                            setStatus('重试失败：' + err.message, true);
                                            retryAll.disabled = false;
                                        }
                                    } else if (discardAll) {
                                        const msg = discardAll.getAttribute('data-confirm') || '确认丢弃？';
                                        const ok = window.ImgEt?.DialogManager?.confirm
                                            ? await window.ImgEt.DialogManager.confirm('丢弃失败任务', msg, { danger: true, confirmText: '丢弃' })
                                            : confirm(msg);
                                        if (!ok) return;
                                        discardAll.disabled = true;
                                        try {
                                            const data = await postQueue('/api/v1/queue/discard-all-failed');
                                            setStatus(`已丢弃 ${data.discarded || 0} 个失败任务`);
                                            await refreshQueueState();
                                        } catch (err) {
                                            setStatus('丢弃失败：' + err.message, true);
                                            discardAll.disabled = false;
                                        }
                                    }
                                });
                            })();
                        </script>
                    </section>
<?php endif; // tab: tasks (queue monitor + failed tasks) ?>

<?php if (in_array($active_settings_tab, ['telegram'], true)): ?>
                    <?php
                    // ---- Telegram 相关设置加载 ----
                    // 都从 Config 读取(同时落 settings 表 + .env)。webhook secret
                    // 永远不直接展示明文 — 只展示「已配置 / 未配置」状态。
                    $tg_enabled  = (bool)\LitePic\Core\Config::bool('TELEGRAM_ENABLED', false);
                    $tg_token    = (string)\LitePic\Core\Config::get('TELEGRAM_BOT_TOKEN', '');
                    $tg_users    = (string)\LitePic\Core\Config::get('TELEGRAM_ALLOWED_USER_IDS', '');
                    $tg_default  = (string)\LitePic\Core\Config::get('TELEGRAM_DEFAULT_ALBUM_KEY', '');
                    $tg_secret   = (string)\LitePic\Core\Config::get('TELEGRAM_WEBHOOK_SECRET', '');
                    $tg_has_secret = $tg_secret !== '';
                    // 站点 base — 给 webhook URL 预览用
                    $tg_site_url = trim((string)\LitePic\Core\Config::get('SITE_URL', ''));
                    if ($tg_site_url === '' || !preg_match('#^https?://#i', $tg_site_url)) {
                        $tg_scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $tg_site_url = $tg_scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                    }
                    $tg_webhook_url = $tg_has_secret
                        ? rtrim($tg_site_url, '/') . '/api/v1/telegram/webhook/' . $tg_secret
                        : '(尚未注册)';
                    $tg_albums_for_picker = (new \LitePic\Repository\AlbumRepository())->all();
                    ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-paper-plane" aria-hidden="true"></i>
                                <span>Telegram 机器人</span>
                            </h3>
                            <p>把 LitePic 接到 Telegram 机器人 — 直接发图上传,聊天里管理相册和拿链接。<a href="https://core.telegram.org/bots#how-do-i-create-a-bot" target="_blank" rel="noopener">如何创建 Bot Token →</a></p>
                        </div>

                        <div class="settings-grid">
                            <!-- 启用开关 -->
                            <div class="grid gap-2 col-span-2">
                                <label class="flex items-center gap-2" for="telegram_enabled">
                                    <input type="checkbox" id="telegram_enabled" name="telegram_enabled" value="1" <?= $tg_enabled ? 'checked' : '' ?>>
                                    <span><strong>启用 Telegram 机器人</strong></span>
                                </label>
                                <p class="settings-field-hint">关闭时,即使 webhook 还在 Telegram 那边,LitePic 也不会处理任何消息。</p>
                            </div>

                            <!-- Bot Token -->
                            <div class="grid gap-2 col-span-2">
                                <label for="telegram_bot_token">Bot Token <span style="color:#d73a49;">*</span></label>
                                <input id="telegram_bot_token" name="telegram_bot_token" type="text" autocomplete="off"
                                       placeholder="例:1234567890:AABBccddEEffGGhhIIjjKKllMMnnOOpp"
                                       value="<?= htmlspecialchars($tg_token) ?>">
                                <p class="settings-field-hint">在 Telegram 里跟 <code>@BotFather</code> 对话,<code>/newbot</code> 拿到一个形如 <code>&lt;bot_id&gt;:&lt;token&gt;</code> 的字符串。</p>
                            </div>

                            <!-- 允许的用户 ID -->
                            <div class="grid gap-2 col-span-2">
                                <label for="telegram_allowed_user_ids">允许访问的 Telegram 用户 ID</label>
                                <input id="telegram_allowed_user_ids" name="telegram_allowed_user_ids" type="text" autocomplete="off"
                                       placeholder="例:123456789,987654321"
                                       value="<?= htmlspecialchars($tg_users) ?>">
                                <p class="settings-field-hint">逗号分隔的纯数字。<strong>白名单是唯一的认证</strong> — 留空 = 没人能用。第一次跟机器人对话用 <code>/start</code>,被拒时机器人会回复你的 user_id,把它填到这里再保存。</p>
                            </div>

                            <!-- 默认上传相册 -->
                            <div class="grid gap-2 col-span-2">
                                <label for="telegram_default_album_key">默认上传相册</label>
                                <select id="telegram_default_album_key" name="telegram_default_album_key">
                                    <option value="">— 不指定(只入主图库) —</option>
                                    <?php foreach ($tg_albums_for_picker as $_a): ?>
                                        <?php $_aKey = \LitePic\Service\Album\AlbumService::urlKey($_a); ?>
                                        <option value="<?= htmlspecialchars($_aKey) ?>" <?= $_aKey === $tg_default ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$_a['name']) ?> (<?= (int)$_a['image_count'] ?> 张) — /a/<?= htmlspecialchars($_aKey) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="settings-field-hint">机器人收到的图片会自动加入此相册。每个 Telegram 用户也能用 <code>/use &lt;key&gt;</code> 设置自己的覆盖值,优先级更高。</p>
                            </div>

                            <!-- Webhook 状态 + 操作 -->
                            <div class="grid gap-2 col-span-2" style="border:1px solid var(--border-color);padding:14px;background:color-mix(in srgb, var(--light) 60%, var(--surface) 40%);">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <div>
                                        <strong>Webhook 状态:</strong>
                                        <?php if ($tg_has_secret): ?>
                                            <span class="status-pill is-on" style="margin-left:6px;">已注册</span>
                                        <?php else: ?>
                                            <span class="status-pill is-off" style="margin-left:6px;">未注册</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn--secondary btn--sm" data-tg-action="test">
                                            <i class="fa-light fa-stethoscope" aria-hidden="true"></i>
                                            <span>测试连接</span>
                                        </button>
                                        <button type="button" class="btn btn--primary btn--sm" data-tg-action="register">
                                            <i class="fa-light fa-link" aria-hidden="true"></i>
                                            <span>注册 / 重新注册 Webhook</span>
                                        </button>
                                        <?php if ($tg_has_secret): ?>
                                            <button type="button" class="btn btn--danger btn--sm" data-tg-action="delete">
                                                <i class="fa-light fa-link-slash" aria-hidden="true"></i>
                                                <span>注销 Webhook</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="settings-field-hint" style="margin-top:8px;">
                                    Webhook URL: <code style="word-break:break-all;font-size:0.78rem;"><?= htmlspecialchars($tg_webhook_url) ?></code>
                                </p>
                                <p class="settings-field-hint">
                                    点「注册 Webhook」会随机生成新 secret 并通知 Telegram。Telegram 要求 HTTPS,本地开发或 IP 直连无法注册。
                                </p>
                            </div>

                            <!-- 指令速查 -->
                            <div class="grid gap-2 col-span-2">
                                <h4 style="margin:0 0 4px;font-size:0.92rem;">机器人支持的指令</h4>
                                <ul style="margin:0;padding-left:1.2em;font-size:0.86rem;line-height:1.7;color:var(--gray);">
                                    <li>📸 直接发图片 — 自动上传到 LitePic,机器人回复公开链接</li>
                                    <li><code>/list [N]</code> — 最近 N 张图(默认 5,最多 20)</li>
                                    <li><code>/albums</code> — 列出所有相册</li>
                                    <li><code>/album &lt;key&gt;</code> — 查看某个相册</li>
                                    <li><code>/newalbum &lt;名称&gt;</code> — 新建相册</li>
                                    <li><code>/use &lt;key&gt;</code> / <code>/use none</code> — 设置 / 清除该用户的默认上传相册</li>
                                    <li><code>/me</code> — 查看自己的 user_id 和当前默认相册</li>
                                    <li><code>/help</code> — 显示帮助</li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <script>
                    /*
                     * Telegram 设置 tab — 三个独立的 AJAX 操作:
                     *   注册 webhook / 注销 webhook / 测试连接
                     *
                     * 所有按钮都通过 form_action 切换走 SettingsController dispatch。
                     * 复用 settings.php 的 isAjaxRequest 路径,后端给 JSON 回包,
                     * 前端跑 toast。注册成功后页面 reload — webhook URL 里包含
                     * 新 secret,需要重新渲染。
                     */
                    (function () {
                        const root = document.querySelector('[data-active-settings-tab="telegram"]');
                        if (!root || root._tgInited) return;
                        root._tgInited = true;

                        const csrf = root.querySelector('input[name="csrf_token"]')?.value
                                  || window.CSRF_TOKEN || '';

                        const callAction = async (action, btn, confirmText) => {
                            if (confirmText) {
                                const ok = window.ImgEt?.DialogManager?.showConfirmDialog
                                    ? await new Promise((resolve) => {
                                          ImgEt.DialogManager.showConfirmDialog(
                                              '确认操作',
                                              confirmText,
                                              () => resolve(true),
                                              { danger: action === 'telegram_delete_webhook',
                                                onCancel: () => resolve(false) }
                                          );
                                      })
                                    : confirm(confirmText);
                                if (!ok) return;
                            }
                            btn.disabled = true;
                            const origHtml = btn.innerHTML;
                            btn.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i><span>请稍候...</span>';
                            try {
                                const fd = new FormData();
                                fd.set('csrf_token', csrf);
                                fd.set('form_action', action);
                                fd.set('active_tab', 'telegram');
                                const res = await fetch('/settings/telegram', {
                                    method: 'POST', body: fd, credentials: 'same-origin',
                                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
                                });
                                const data = await res.json().catch(() => ({}));
                                const ok = data?.status === 'success' || data?.type === 'success';
                                window.ImgEt?.Utils?.showNotification?.(
                                    data?.message || (ok ? '操作成功' : '操作失败'),
                                    ok ? 'success' : 'error'
                                );
                                if (ok && (action === 'telegram_register_webhook'
                                        || action === 'telegram_delete_webhook')) {
                                    // Webhook URL 包含 secret,重新渲染才能看到最新值
                                    setTimeout(() => window.location.reload(), 800);
                                }
                            } catch (err) {
                                console.error('TG action error:', err);
                                window.ImgEt?.Utils?.showNotification?.(
                                    err.message || '请求失败', 'error'
                                );
                            } finally {
                                btn.disabled = false;
                                btn.innerHTML = origHtml;
                            }
                        };

                        root.addEventListener('click', (e) => {
                            const btn = e.target.closest('[data-tg-action]');
                            if (!btn) return;
                            e.preventDefault();
                            const a = btn.dataset.tgAction;
                            if (a === 'register') {
                                callAction('telegram_register_webhook', btn,
                                    '注册会生成新的 URL secret,旧 URL 立即失效。继续吗?');
                            } else if (a === 'delete') {
                                callAction('telegram_delete_webhook', btn,
                                    '注销后 Telegram 不会再推消息给本站,直到重新注册。继续吗?');
                            } else if (a === 'test') {
                                callAction('telegram_test', btn);
                            }
                        });
                    })();
                    </script>
<?php endif; // tab: telegram ?>

<?php if (in_array($active_settings_tab, ['basic'], true)): ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-sliders" aria-hidden="true"></i>
                                <span>站点信息</span>
                            </h3>
                            <p>站点名称、描述</p>
                        </div>

                        <div class="settings-grid">
                            <div class="grid gap-2">
                                <label for="siteName">站点名称</label>
                                <input id="siteName" type="text" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="siteDescription">站点描述</label>
                                <input id="siteDescription" type="text" name="site_description" value="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
                            </div>
                        </div>
                    </section>
<?php endif; // tab: basic (站点信息) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // 自动压缩 + 自动转换 + 保留原图 (从原 general tab 拆出来) ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-bolt" aria-hidden="true"></i>
                                <span>自动压缩与格式转换</span>
                            </h3>
                            <p>上传后由 LitePic 在服务端自动处理图片，可选 ImageMagick / GD / TinyPNG 三种引擎</p>
                        </div>

                        <div class="settings-grid">
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label for="compressionMode">压缩方式</label>
                                    <a class="inline-flex items-center gap-1 text-sm text-primary no-underline hover:underline" href="https://litepic.io/docs#compression-modes" target="_blank" rel="noopener noreferrer">
                                        <span>了解更多</span>
                                        <i class="fa-light fa-arrow-up-right-from-square"></i>
                                    </a>
                                </div>
                                <select id="compressionMode" name="compression_mode">
                                    <option value="tinypng" <?= $current_compression_mode === 'tinypng' ? 'selected' : '' ?>>TinyPNG（在线，需 API Key — 见下方）</option>
                                    <option value="gd" <?= $current_compression_mode === 'gd' ? 'selected' : '' ?>>GD（PHP 内置）</option>
                                    <option value="imagemagick" <?= $current_compression_mode === 'imagemagick' ? 'selected' : '' ?>>ImageMagick（推荐）</option>
                                </select>
                            </div>
                            <?php $_engine = defined('CONVERSION_ENGINE') ? CONVERSION_ENGINE : 'auto'; ?>
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between gap-2">
                                    <label for="conversionEngine">转换引擎</label>
                                    <span class="text-xs text-gray">用于 WebP / AVIF / JPG / PNG 转换，HEIC 需 Imagick 支持</span>
                                </div>
                                <select id="conversionEngine" name="conversion_engine">
                                    <option value="auto"    <?= $_engine === 'auto'    ? 'selected' : '' ?>>自动（推荐 — Imagick 优先，回退 GD）</option>
                                    <option value="imagick" <?= $_engine === 'imagick' ? 'selected' : '' ?>>Imagick（处理大图省内存，10000×3000 以上必选）</option>
                                    <option value="gd"      <?= $_engine === 'gd'      ? 'selected' : '' ?>>GD（兼容性好，30MP 以上易爆内存）</option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-toggle-list">
                            <label class="settings-toggle-row" for="autoCompressOnUpload">
                                <span class="settings-toggle-copy">上传后自动压缩（支持 JPG/JPEG/PNG）</span>
                                <input id="autoCompressOnUpload" class="settings-switch-input" type="checkbox" name="auto_compress_on_upload" value="1" <?= AUTO_COMPRESS_ON_UPLOAD ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <div class="settings-toggle-row settings-toggle-row-control">
                                <span class="settings-toggle-copy">上传后自动转换（支持 JPG/JPEG/PNG/GIF/HEIC/HEIF）</span>
                                <div class="settings-toggle-controls">
                                    <div class="settings-radio-group" role="radiogroup" aria-label="上传转换格式">
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="webp" <?= CONVERT_PREFERRED_FORMAT === 'webp' ? 'checked' : '' ?>>
                                            <span>WebP</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="avif" <?= CONVERT_PREFERRED_FORMAT === 'avif' ? 'checked' : '' ?>>
                                            <span>AVIF</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="jpg" <?= CONVERT_PREFERRED_FORMAT === 'jpg' ? 'checked' : '' ?>>
                                            <span>JPG</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="convert_preferred_format" value="png" <?= CONVERT_PREFERRED_FORMAT === 'png' ? 'checked' : '' ?>>
                                            <span>PNG</span>
                                        </label>
                                    </div>
                                    <label class="settings-switch-label" for="autoConvertOnUpload">
                                        <input id="autoConvertOnUpload" class="settings-switch-input" type="checkbox" name="auto_convert_on_upload" value="1" <?= (defined('AUTO_CONVERT_ON_UPLOAD') && AUTO_CONVERT_ON_UPLOAD) ? 'checked' : '' ?>>
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                </div>
                            </div>
                            <label class="settings-toggle-row" for="keepOriginalAfterProcess">
                                <span class="settings-toggle-copy">转换或压缩后保留原图</span>
                                <input id="keepOriginalAfterProcess" class="settings-switch-input" type="checkbox" name="keep_original_after_process" value="1" <?= KEEP_ORIGINAL_AFTER_PROCESS ? 'checked' : '' ?>>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                        </div>
                    </section>
<?php endif; // tab: image (自动压缩与格式转换 section) ?>

<?php if (in_array($active_settings_tab, ['storage'], true)): ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
                                <span>远程存储（R2 / S3）</span>
                            </h3>
                            <p>可作为远程备份，也可作为云端图片访问源</p>
                        </div>

                        <div class="settings-grid">
                            <div class="grid gap-2 col-span-2">
                                <label>远程用途</label>
                                <div class="settings-radio-group settings-remote-usage-group" role="radiogroup" aria-label="远程存储用途">
                                    <label class="settings-radio-option settings-radio-option-block">
                                        <input type="radio" name="remote_storage_usage" value="backup" <?= REMOTE_STORAGE_USAGE === 'backup' ? 'checked' : '' ?>>
                                        <span>
                                            <strong>远程备份</strong>
                                            <small>本地作为主存储，R2/S3 只保存备份副本，图片链接仍使用本站地址。</small>
                                        </span>
                                    </label>
                                    <label class="settings-radio-option settings-radio-option-block">
                                        <input type="radio" name="remote_storage_usage" value="storage" <?= REMOTE_STORAGE_USAGE === 'storage' ? 'checked' : '' ?>>
                                        <span>
                                            <strong>云端存储</strong>
                                            <small>LitePic 负责上传、压缩和转换，复制链接/API/图库优先使用 R2/S3 公网地址。</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Endpoint">Endpoint</label>
                                <input id="s3Endpoint" type="text" name="s3_endpoint" value="<?= htmlspecialchars(S3_ENDPOINT) ?>" placeholder="https://<accountid>.r2.cloudflarestorage.com">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Bucket">Bucket</label>
                                <input id="s3Bucket" type="text" name="s3_bucket" value="<?= htmlspecialchars(S3_BUCKET) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Key">Access Key</label>
                                <input id="s3Key" type="text" name="s3_key" value="<?= htmlspecialchars(S3_KEY) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Secret">Secret Key</label>
                                <input id="s3Secret" type="password" name="s3_secret" value="<?= htmlspecialchars(S3_SECRET) ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3Region">Region</label>
                                <input id="s3Region" type="text" name="s3_region" value="<?= htmlspecialchars(S3_REGION) ?>" placeholder="R2 建议 auto">
                            </div>
                            <div class="grid gap-2">
                                <label for="s3PathPrefix">对象路径前缀</label>
                                <input id="s3PathPrefix" type="text" name="s3_path_prefix" value="<?= htmlspecialchars(S3_PATH_PREFIX) ?>" placeholder="uploads">
                            </div>
                            <div class="grid gap-2 col-span-2" data-remote-public-url-field <?= REMOTE_STORAGE_USAGE === 'storage' ? '' : 'hidden' ?>>
                                <label for="s3PublicBaseUrl">公网访问域名<span data-remote-public-required><?= REMOTE_STORAGE_USAGE === 'storage' ? '（云端存储必填）' : '（云端存储时使用）' ?></span></label>
                                <input id="s3PublicBaseUrl" type="text" name="s3_public_base_url" value="<?= htmlspecialchars(S3_PUBLIC_BASE_URL) ?>" placeholder="https://cdn.example.com">
                            </div>
                        </div>

                        <p class="m-0 text-xs text-gray" data-remote-storage-note>说明：远程备份模式下，本地仍是主存储，R2/S3 只保存副本；云端存储模式下，公网访问域名必填，复制链接、API 返回和图库图片地址会优先使用云端地址。本地删除后，远程对象会进入 24 小时延迟删除队列。</p>

                        <div class="flex justify-start gap-2.5">
                            <button
                                type="submit"
                                class="btn btn--primary"
                                name="form_action"
                                value="save_remote_storage"
                                data-busy-text="正在保存设置...">
                                <i class="fa-light fa-floppy-disk"></i>
                                保存设置
                            </button>
                            <button type="submit" class="btn btn--secondary" name="form_action" value="test_remote_storage">
                                <i class="fa-light fa-plug-circle-check"></i>
                                测试连接
                            </button>
                            <button
                                type="submit"
                                class="btn btn--secondary js-remote-sync-all-btn"
                                name="form_action"
                                value="sync_remote_storage_all"
                                data-confirm="确定要将所有本地图片同步到远程存储吗？此操作可能需要较长时间。"
                                data-confirm-title="全量同步确认"
                                data-busy-text="正在同步全部图片到远程存储，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-up"></i>
                                一键同步
                            </button>
                            <button
                                type="submit"
                                class="btn btn--secondary js-remote-restore-all-btn"
                                name="form_action"
                                value="restore_remote_storage_all"
                                data-confirm="确定要从远程存储恢复到本地吗？这会覆盖本地同名文件。"
                                data-confirm-title="全量恢复确认"
                                data-busy-text="正在从远程恢复到本地，请勿关闭页面...">
                                <i class="fa-light fa-cloud-arrow-down"></i>
                                一键恢复
                            </button>
                            <button
                                type="submit"
                                data-busy-text="正在清空云端对象，请勿关闭页面..."
                                class="btn btn--danger js-remote-purge-btn"
                                name="form_action"
                                value="purge_remote_storage"
                                data-confirm="确认清空云端存储对象吗？将删除当前配置前缀下的所有对象，无法恢复。"
                                data-confirm-title="清空云端确认">
                                <i class="fa-light fa-trash-can-list"></i>
                                清空云端
                            </button>
                        </div>
                    </section>
<?php endif; // tab: storage ?>

<?php if (in_array($active_settings_tab, ['storage'], true)): ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-folder-open" aria-hidden="true"></i>
                                <span>扫描导入</span>
                            </h3>
                            <p>扫描指定目录并导入图库，可选生成缩略图、压缩或转换</p>
                        </div>
                        <div class="grid gap-2">
                            <?php $_storage_dir_for_scan = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads'; ?>
                            <label for="scanSourcePath">扫描路径（留空默认 upload / <?= htmlspecialchars($_storage_dir_for_scan) ?>）</label>
                            <input
                                id="scanSourcePath"

                                type="text"
                                name="scan_source_path"
                                value="<?= htmlspecialchars(trim((string)($_POST['scan_source_path'] ?? ''))) ?>"
                                placeholder="upload 或 /www/wwwroot/site/upload，多个路径用英文逗号分隔">
                            <p class="m-0 text-sm text-gray">会递归导入所选目录及所有子目录，导入到 <?= htmlspecialchars($_storage_dir_for_scan) ?> 时保留源目录内的相对路径。</p>
                        </div>
                        <div class="settings-toggle-list">
                            <label class="settings-toggle-row scan-option" for="scanCreateThumbnail">
                                <span class="settings-toggle-copy">导入时生成缩略图</span>
                                <input id="scanCreateThumbnail" class="settings-switch-input" type="checkbox" name="scan_create_thumbnail" value="1" checked>
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <label class="settings-toggle-row scan-option" for="scanAutoCompress">
                                <span class="settings-toggle-copy">导入时自动压缩（JPG/JPEG/PNG）</span>
                                <input id="scanAutoCompress" class="settings-switch-input" type="checkbox" name="scan_auto_compress" value="1">
                                <span class="settings-switch" aria-hidden="true"><span></span></span>
                            </label>
                            <div class="settings-toggle-row settings-toggle-row-control scan-option">
                                <span class="settings-toggle-copy">导入时自动转换（JPG/JPEG/PNG/GIF/HEIC/HEIF）</span>
                                <div class="settings-toggle-controls">
                                    <div class="settings-radio-group" role="radiogroup" aria-label="导入转换格式">
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="webp" checked>
                                            <span>WebP</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="avif">
                                            <span>AVIF</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="jpg">
                                            <span>JPG</span>
                                        </label>
                                        <label class="settings-radio-option">
                                            <input type="radio" name="scan_convert_format" value="png">
                                            <span>PNG</span>
                                        </label>
                                    </div>
                                    <label class="settings-switch-label" for="scanAutoConvert">
                                        <input id="scanAutoConvert" class="settings-switch-input" type="checkbox" name="scan_auto_convert" value="1" checked>
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="settings-callout text-xs text-gray leading-relaxed">
                            导入后处理采用任务队列执行：扫描只负责导入，缩略图、压缩、转换、水印和远程同步会排队分批处理。当前待处理
                            <strong class="text-dark" data-import-task-pending><?= (int)($import_task_status['pending'] ?? 0) ?></strong>
                            个任务，曾处理失败
                            <strong class="text-danger" data-import-task-failed><?= (int)($import_task_status['failed'] ?? 0) ?></strong>
                            个；每次最多处理 8 个，避免一次请求占满服务器。
                        </div>
                        <div class="flex justify-start gap-2.5">
                            <button type="submit" class="btn btn--secondary" name="form_action" value="scan_import_uploads">
                                <i class="fa-light fa-folder-open"></i>
                                扫描导入目录
                            </button>
                            <button type="submit" class="btn btn--secondary" name="form_action" value="process_import_tasks">
                                <i class="fa-light fa-gears"></i>
                                处理导入任务
                            </button>
                            <button type="submit" class="btn btn--secondary" name="form_action" value="generate_all_thumbnails">
                                <i class="fa-light fa-images"></i>
                                一键生成全部缩略图
                            </button>
                        </div>
                    </section>
<?php endif; // tab: storage (扫描导入 section) ?>

<?php if (in_array($active_settings_tab, ['account'], true)): ?>
                    <section>
                        <div class="settings-section-header">
                            <h3 class="settings-card-title">
                                <i class="fa-light fa-shield-halved" aria-hidden="true"></i>
                                <span>管理员密码</span>
                            </h3>
                            <p>当前密码用于后台登录与 API 调用（Cookie Secure 自动按 HTTPS 生效）</p>
                        </div>

                        <div class="settings-toggle-list">
                            <div class="settings-toggle-row">
                                <span class="settings-toggle-copy">
                                    <?php if (defined('DEFAULT_ADMIN_API_KEY') && hash_equals(DEFAULT_ADMIN_API_KEY, (string)ADMIN_API_KEY)): ?>
                                        <strong style="color:#d73a49;">当前仍在使用初始默认密码 12345678，请立即修改</strong>
                                    <?php else: ?>
                                        密码已设置（出于安全考虑不显示明文）
                                    <?php endif; ?>
                                </span>
                                <button type="button" class="btn btn--primary" data-open-change-password>
                                    <i class="fa-light fa-key"></i>
                                    <span>修改密码</span>
                                </button>
                            </div>
                        </div>

                        <p class="m-0 text-xs text-gray">说明：管理员密码只能通过此弹窗修改，不再以明文形式出现在表单中。修改后会自动续签登录 Cookie，无需重新登录。</p>

                        <script>
                            (function () {
                                var btn = document.querySelector('[data-open-change-password]');
                                if (!btn) return;
                                btn.addEventListener('click', function () {
                                    if (window.ApiManager && typeof window.ApiManager.openChangePasswordModal === 'function') {
                                        window.ApiManager.openChangePasswordModal({ forced: false });
                                    }
                                });
                            })();
                        </script>
                    </section>
<?php endif; // tab: account (in-form section) ?>

                </form>

<?php if (in_array($active_settings_tab, ['account'], true)): ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-key" aria-hidden="true"></i>
                            <span>API Token 管理（第三方上传）</span>
                        </h3>
                        <p>可创建、复制和撤销上传 Token</p>
                    </div>

                    <form method="post" class="settings-inline-form settings-token-form">
                        <?= \LitePic\Core\Csrf::inputField() ?>
                        <input type="hidden" name="form_action" value="create_token">
                        <input type="hidden" name="active_tab" value="account">
                        <input type="text" name="token_name" placeholder="Token 名称（如：wordpress-prod）">
                        <button type="submit" class="btn btn--primary">
                            <i class="fa-light fa-key"></i>
                            创建 Token
                        </button>
                    </form>

                    <?php if ($created_token !== ''): ?>
                        <div class="settings-callout">
                            <strong>新 Token（仅显示一次）</strong>
                            <div class="settings-inline-form settings-token-form">
                                <input type="text" readonly value="<?= htmlspecialchars($created_token) ?>">
                                <button type="button" class="btn btn--secondary copy-token-btn" data-copy="<?= htmlspecialchars($created_token) ?>">复制</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="m-0 text-sm text-gray">当前启用 Token：<?= count($active_tokens) ?> 个。出于安全原因，已创建 Token 不再长期明文展示。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse">
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
                                            <form method="post" data-confirm="确定要撤销此 API Token 吗？使用此 Token 的应用将立即失效。" data-confirm-title="撤销 Token 确认">
                                                <?= \LitePic\Core\Csrf::inputField() ?>
                                                <input type="hidden" name="form_action" value="revoke_token">
                                                <input type="hidden" name="active_tab" value="account">
                                                <input type="hidden" name="token_id" value="<?= htmlspecialchars((string)$token['id']) ?>">
                                                <button type="submit" class="btn btn--danger">撤销</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($active_tokens)): ?>
                                    <tr class="settings-empty-row">
                                        <td colspan="5">
                                            <span class="settings-empty-state">
                                                <i class="fa-light fa-key" aria-hidden="true"></i>
                                                <span>暂无可用 Token，请创建新的 Token</span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-fingerprint" aria-hidden="true"></i>
                            <span>Passkey 管理</span>
                        </h3>
                        <p>无密码登录（生物识别 / 设备 PIN）</p>
                    </div>

                    <button type="button" class="btn btn--primary btn--block" id="passkeyRegisterBtn">
                        <i class="fa-light fa-fingerprint"></i>
                        注册新 Passkey
                    </button>

                    <p class="m-0 text-sm text-gray">已注册 <span id="passkeyCount">0</span> 个 Passkey。支持系统 PIN、指纹、面容等生物识别方式登录。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse" id="passkeyTable">
                            <thead>
                                <tr>
                                    <th>凭证 ID</th>
                                    <th>注册时间</th>
                                    <th>最近使用</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="passkeyTableBody">
                                <tr>
                                    <td colspan="4">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
<?php endif; // tab: account (out-of-form sections) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // TinyPNG keys 现在归图片处理 ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-image" aria-hidden="true"></i>
                            <span>图片压缩 API 管理（<a class="settings-title-link" href="https://tinypng.com/" target="_blank" rel="noopener noreferrer"><span>TinyPNG</span><i class="fa-sharp fa-light fa-square-arrow-up-right" aria-hidden="true"></i></a>）</span>
                        </h3>
                        <p>多 Key 轮询与调用监控</p>
                    </div>

                    <form method="post" class="settings-inline-form settings-compression-form">
                        <?= \LitePic\Core\Csrf::inputField() ?>
                        <input type="hidden" name="form_action" value="add_compression_api">
                        <input type="hidden" name="active_tab" value="image">
                        <input type="text" name="compression_api_key" placeholder="输入 TinyPNG API Key">
                        <button type="submit" class="btn btn--primary">
                            <i class="fa-light fa-plus"></i>
                            添加 Key
                        </button>
                    </form>

                    <p class="m-0 text-sm text-gray" data-compression-stats>已配置 <span data-compression-total><?= count($compression_api_keys) ?></span> 个，启用中 <span data-compression-active><?= $compression_api_active_count ?></span> 个。系统优先使用调用次数较少的 Key，并记录每个 Key 的调用统计。</p>

                    <div class="overflow-auto border border-border">
                        <table class="w-full border-collapse compression-keys-table">
                            <thead>
                                <tr>
                                    <th>API Key</th>
                                    <th>状态</th>
                                    <th>总调用</th>
                                    <th>成功</th>
                                    <th>失败</th>
                                    <th>最近使用</th>
                                    <th>最近结果</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody data-compression-keys-tbody>
                                <?php foreach ($compression_api_keys as $row): ?>
                                    <?php
                                    $id = (string)($row['id'] ?? '');
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
                                        <td><?= htmlspecialchars($masked) ?></td>
                                        <td><?= $enabled ? '启用中' : '已禁用' ?></td>
                                        <td><?= number_format($used_count) ?></td>
                                        <td><?= number_format($success_count) ?></td>
                                        <td><?= number_format($failed_count) ?></td>
                                        <td><?= htmlspecialchars($last_used_at) ?></td>
                                        <td><?= htmlspecialchars($last_result) ?></td>
                                        <td>
                                            <div class="flex gap-2 flex-wrap">
                                                <form method="post">
                                                    <?= \LitePic\Core\Csrf::inputField() ?>
                                                    <input type="hidden" name="form_action" value="toggle_compression_api">
                                                    <input type="hidden" name="active_tab" value="image">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
                                                    <button type="submit" class="btn btn--secondary"><?= $enabled ? '禁用' : '启用' ?></button>
                                                </form>
                                                <form method="post" data-confirm="确定要删除此压缩 API Key 吗？" data-confirm-title="删除 TinyPNG Key 确认">
                                                    <?= \LitePic\Core\Csrf::inputField() ?>
                                                    <input type="hidden" name="form_action" value="delete_compression_api">
                                                    <input type="hidden" name="active_tab" value="image">
                                                    <input type="hidden" name="compression_api_id" value="<?= htmlspecialchars($id) ?>">
                                                    <button type="submit" class="btn btn--danger">删除</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($compression_api_keys)): ?>
                                    <tr class="settings-empty-row">
                                        <td colspan="8">
                                            <span class="settings-empty-state">
                                                <i class="fa-light fa-image" aria-hidden="true"></i>
                                                <span>暂无压缩 API Key，请先添加</span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
<?php endif; // tab: image (TinyPNG / 压缩 API Keys section) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // 水印 + 防盗链 现在归图片处理 ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-stamp" aria-hidden="true"></i>
                            <span>水印与防盗链</span>
                        </h3>
                        <p>上传后写入文字或图片水印，防盗链规则由后台同步</p>
                    </div>

                    <div class="settings-toggle-list">
                        <label class="settings-toggle-row" for="watermarkEnabled">
                            <span class="settings-toggle-copy">启用水印</span>
                            <input id="watermarkEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_enabled" value="1" <?= WATERMARK_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <label class="settings-toggle-row" for="hotlinkProtectionEnabled">
                            <span class="settings-toggle-copy">启用防盗链（开启后图片链接强制走 <code>/i/&lt;文件&gt;</code> 由 PHP 校验 Referer）</span>
                            <input id="hotlinkProtectionEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="hotlink_protection_enabled" value="1" <?= $hotlink_enabled ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <label class="settings-toggle-row" for="hotlinkAllowEmptyReferer" data-hotlink-config <?= $hotlink_enabled ? '' : 'hidden' ?>>
                            <span class="settings-toggle-copy">
                                允许无来源请求（直接打开图片 / 隐私浏览器不拦截）
                                <a class="settings-help-link" href="https://litepic.io/docs#hotlink-empty-referer" target="_blank" rel="noopener noreferrer">说明</a>
                            </span>
                            <input id="hotlinkAllowEmptyReferer" form="settingsForm" class="settings-switch-input" type="checkbox" name="hotlink_allow_empty_referer" value="1" <?= HOTLINK_ALLOW_EMPTY_REFERER ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                    </div>

                    <div class="grid gap-3.5" data-watermark-config <?= WATERMARK_ENABLED ? '' : 'hidden' ?>>
                        <div class="settings-toggle-row settings-toggle-row-control">
                            <span class="settings-toggle-copy">水印类型</span>
                            <div class="settings-toggle-controls">
                                <div class="settings-radio-group" role="radiogroup" aria-label="水印类型">
                                    <label class="settings-radio-option">
                                        <input form="settingsForm" type="radio" name="watermark_type" value="text" <?= WATERMARK_TYPE === 'text' ? 'checked' : '' ?>>
                                        <span>文字水印</span>
                                    </label>
                                    <label class="settings-radio-option">
                                        <input form="settingsForm" type="radio" name="watermark_type" value="image" <?= WATERMARK_TYPE === 'image' ? 'checked' : '' ?>>
                                        <span>图片水印</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3.5">
                            <div class="grid gap-2">
                                <label for="watermarkPosition">水印位置</label>
                                <select id="watermarkPosition" form="settingsForm" name="watermark_position">
                                    <option value="bottom-right" <?= WATERMARK_POSITION === 'bottom-right' ? 'selected' : '' ?>>右下角</option>
                                    <option value="bottom-left" <?= WATERMARK_POSITION === 'bottom-left' ? 'selected' : '' ?>>左下角</option>
                                    <option value="top-right" <?= WATERMARK_POSITION === 'top-right' ? 'selected' : '' ?>>右上角</option>
                                    <option value="top-left" <?= WATERMARK_POSITION === 'top-left' ? 'selected' : '' ?>>左上角</option>
                                    <option value="center" <?= WATERMARK_POSITION === 'center' ? 'selected' : '' ?>>居中</option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkOpacity">水印透明度（1-100）</label>
                                <input id="watermarkOpacity" form="settingsForm" type="number" min="1" max="100" name="watermark_opacity" value="<?= (int)WATERMARK_OPACITY ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkMargin">水印边距</label>
                                <input id="watermarkMargin" form="settingsForm" type="number" min="0" max="240" name="watermark_margin" value="<?= (int)WATERMARK_MARGIN ?>">
                            </div>
                        </div>

                        <div class="grid gap-3.5" data-watermark-mode="text" <?= WATERMARK_TYPE === 'text' ? '' : 'hidden' ?>>
                            <strong class="text-dark text-sm">文字水印设置</strong>
                            <div class="settings-grid">
                                <div class="grid gap-2">
                                    <label for="watermarkText">水印文字</label>
                                    <input id="watermarkText" form="settingsForm" type="text" name="watermark_text" value="<?= htmlspecialchars(WATERMARK_TEXT) ?>" placeholder="LitePic">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkColor">水印颜色</label>
                                    <input id="watermarkColor" form="settingsForm" type="text" name="watermark_color" value="<?= htmlspecialchars(WATERMARK_COLOR) ?>" placeholder="#ffffff">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkFontSize">水印字号</label>
                                    <input id="watermarkFontSize" form="settingsForm" type="number" min="8" max="72" name="watermark_font_size" value="<?= (int)WATERMARK_FONT_SIZE ?>">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkFontUpload">上传字体（TTF / OTF）</label>
                                    <input id="watermarkFontUpload" form="settingsForm" class="settings-file-input" type="file" name="watermark_font_upload" accept=".ttf,.otf,font/ttf,font/otf">
                                </div>
                                <div class="grid gap-2 col-span-2">
                                    <label for="watermarkFontPath">字体文件路径（默认尝试 Ubuntu）</label>
                                    <input id="watermarkFontPath" form="settingsForm" type="text" name="watermark_font_path" value="<?= htmlspecialchars(WATERMARK_FONT_PATH) ?>" placeholder="/path/to/font.ttf">
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3.5" data-watermark-mode="image" <?= WATERMARK_TYPE === 'image' ? '' : 'hidden' ?>>
                            <strong class="text-dark text-sm">图片水印设置</strong>
                            <div class="settings-grid">
                                <div class="grid gap-2">
                                    <label for="watermarkImageUpload">上传 PNG 图片水印</label>
                                    <input id="watermarkImageUpload" form="settingsForm" class="settings-file-input" type="file" name="watermark_image_upload" accept="image/png,.png">
                                </div>
                                <div class="grid gap-2">
                                    <label for="watermarkImageWidth">PNG 水印最大宽度</label>
                                    <input id="watermarkImageWidth" form="settingsForm" type="number" min="24" max="800" name="watermark_image_width" value="<?= (int)WATERMARK_IMAGE_WIDTH ?>">
                                </div>
                                <div class="grid gap-2 col-span-2">
                                    <label for="watermarkImagePath">PNG 水印路径</label>
                                    <input id="watermarkImagePath" form="settingsForm" type="text" name="watermark_image_path" value="<?= htmlspecialchars(WATERMARK_IMAGE_PATH) ?>" placeholder="/path/to/watermark.png">
                                </div>
                                <?php if (WATERMARK_IMAGE_PATH !== ''): ?>
                                    <label class="settings-toggle-row col-span-2" for="watermarkImageClear">
                                        <span class="settings-toggle-copy">清空当前 PNG 图片水印</span>
                                        <input id="watermarkImageClear" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_image_clear" value="1">
                                        <span class="settings-switch" aria-hidden="true"><span></span></span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <label class="settings-toggle-row" for="watermarkPanelEnabled">
                            <span class="settings-toggle-copy">启用水印磨砂底层</span>
                            <input id="watermarkPanelEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="watermark_panel_enabled" value="1" <?= WATERMARK_PANEL_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                        <div class="grid grid-cols-3 gap-3.5" data-watermark-panel-settings <?= WATERMARK_PANEL_ENABLED ? '' : 'hidden' ?>>
                            <div class="grid gap-2">
                                <label for="watermarkPanelOpacity">磨砂层透明度（1-100）</label>
                                <input id="watermarkPanelOpacity" form="settingsForm" type="number" min="1" max="100" name="watermark_panel_opacity" value="<?= (int)WATERMARK_PANEL_OPACITY ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkPanelPadding">磨砂层内边距</label>
                                <input id="watermarkPanelPadding" form="settingsForm" type="number" min="0" max="80" name="watermark_panel_padding" value="<?= (int)WATERMARK_PANEL_PADDING ?>">
                            </div>
                            <div class="grid gap-2">
                                <label for="watermarkPanelRadius">磨砂层圆角</label>
                                <input id="watermarkPanelRadius" form="settingsForm" type="number" min="0" max="80" name="watermark_panel_radius" value="<?= (int)WATERMARK_PANEL_RADIUS ?>">
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3.5" data-hotlink-config <?= $hotlink_enabled ? '' : 'hidden' ?>>
                        <div class="grid gap-2 col-span-2">
                            <label for="hotlinkAllowedDomains">防盗链允许域名</label>
                            <input id="hotlinkAllowedDomains" form="settingsForm" type="text" name="hotlink_allowed_domains" value="<?= htmlspecialchars(implode(',', HOTLINK_ALLOWED_DOMAINS)) ?>" placeholder="example.com,cdn.example.com">
                            <p class="settings-field-hint">逗号分隔。当前请求 host（<code><?= htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? '')) ?></code>）和 SITE_URL 自动加入白名单，无需重复填。</p>
                        </div>
                    </div>

                    <p class="m-0 text-xs text-gray">说明：开启后图片公网链接强制走 <code>/i/&lt;文件&gt;</code> 由 PHP 校验 Referer，跨服务器（Apache / Nginx / Caddy）通用，无需写 .htaccess 或 vhost。允许无来源请求表示直接打开图片、隐私浏览器或预览类应用访问时不拦截；关闭后这类请求也会被拒绝。</p>
                </section>
<?php endif; // tab: image (水印 + 防盗链 section) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // 图片请求统计 现在归图片处理 ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-chart-line" aria-hidden="true"></i>
                            <span>图片请求统计</span>
                        </h3>
                        <p>由 PHP 直接累加每张图片的访问次数，不依赖 Web 服务器 access.log</p>
                    </div>

                    <div class="settings-toggle-list">
                        <label class="settings-toggle-row" for="imageViewCounterEnabled">
                            <span class="settings-toggle-copy">启用图片请求计数</span>
                            <input id="imageViewCounterEnabled" form="settingsForm" class="settings-switch-input" type="checkbox" name="image_view_counter_enabled" value="1" <?= IMAGE_VIEW_COUNTER_ENABLED ? 'checked' : '' ?>>
                            <span class="settings-switch" aria-hidden="true"><span></span></span>
                        </label>
                    </div>

                    <?php $_storage_dir_for_hint = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads'; ?>
                    <p class="m-0 text-sm text-gray">说明：开启后图片公网链接将统一走 <code>/i/&lt;文件名&gt;</code> 路由，由 PHP 流式提供并把命中数累加到数据库（仅完整 200 响应计数，HEAD / 304 / Range 不计）。文件本身仍存放在 <code><?= htmlspecialchars($_storage_dir_for_hint) ?>/</code> 目录。关闭后链接回退为下方「图片链接格式」配置的样式，统计也随之停止。</p>
                </section>
<?php endif; // tab: image (图片请求统计 section) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // 物理存储目录（STORAGE_DIR）?>
                <?php
                $_storage_dir = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads';
                $_storage_path = (defined('UPLOAD_PATH_LOCAL') ? UPLOAD_PATH_LOCAL : APP_ROOT . '/' . $_storage_dir . '/');
                $_storage_exists = is_dir($_storage_path);
                $_storage_writable = $_storage_exists && is_writable($_storage_path);
                ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-folder" aria-hidden="true"></i>
                            <span>物理存储目录</span>
                        </h3>
                        <p>图片源文件实际落盘的目录（项目根下的子目录）。默认 <code>uploads</code>。</p>
                    </div>

                    <div class="settings-grid">
                        <div class="grid gap-2 col-span-2">
                            <label for="storageDir">目录名</label>
                            <input
                                id="storageDir"
                                form="settingsForm"
                                type="text"
                                name="storage_dir"
                                value="<?= htmlspecialchars($_storage_dir) ?>"
                                placeholder="uploads"
                                autocomplete="off"
                                pattern="^[a-z][a-z0-9_-]{0,29}$"
                                maxlength="30">
                            <p class="settings-field-hint">
                                小写字母开头，1–30 字符，可用 <code>a-z 0-9 _ -</code>。
                                避开保留名：<code>api</code> / <code>app</code> / <code>assets</code> / <code>static</code> / <code>data</code> / <code>logs</code> / <code>i</code>。
                            </p>
                        </div>
                        <div class="grid gap-2 col-span-2">
                            <label>当前状态</label>
                            <div class="settings-toggle-row settings-toggle-row-control" style="cursor:default;">
                                <span class="settings-toggle-copy">
                                    <strong>路径：</strong> <code><?= htmlspecialchars($_storage_path) ?></code>
                                    <br>
                                    <small class="text-gray">
                                        目录
                                        <?php if ($_storage_exists): ?>
                                            <strong style="color:#22c55e;">存在</strong>
                                        <?php else: ?>
                                            <strong style="color:#d73a49;">不存在</strong>
                                        <?php endif; ?>
                                        ·
                                        <?php if ($_storage_writable): ?>
                                            <strong style="color:#22c55e;">可写</strong>
                                        <?php elseif ($_storage_exists): ?>
                                            <strong style="color:#d73a49;">不可写</strong>
                                        <?php else: ?>
                                            <span class="text-gray">—</span>
                                        <?php endif; ?>
                                    </small>
                                </span>
                            </div>
                        </div>
                    </div>

                    <p class="m-0 text-xs text-gray">
                        <strong>改名注意：</strong>
                        在输入框改完目录名按「保存设置」，配置会更新但磁盘上的目录不会自动 rename。
                        如果磁盘上目录还叫旧名，可以点下面的「重命名磁盘目录」一键完成（必须当前目录存在且新名不存在）。
                        改名后已发布的 <code>/uploads/...</code> 链接会自动 301 跳到新前缀，老链接不会失效。
                    </p>

                    <form method="post" class="settings-inline-form" style="margin-top:8px;">
                        <?= \LitePic\Core\Csrf::inputField() ?>
                        <input type="hidden" name="form_action" value="rename_storage_dir">
                        <input type="hidden" name="active_tab" value="image">
                        <input type="hidden" name="from" value="<?= htmlspecialchars($_storage_dir) ?>">
                        <input type="text"
                               name="to"
                               placeholder="新目录名（如 files / images / storage）"
                               pattern="^[a-z][a-z0-9_-]{0,29}$"
                               maxlength="30"
                               required>
                        <button type="submit" class="btn btn--secondary"
                                data-confirm="确定把磁盘上的目录从 「<?= htmlspecialchars($_storage_dir) ?>」 重命名吗？操作期间建议没有正在进行的上传。"
                                data-confirm-title="重命名物理存储目录">
                            <i class="fa-light fa-folder-tree"></i>
                            重命名磁盘目录
                        </button>
                    </form>
                </section>
<?php endif; // tab: image (物理存储目录 section) ?>

<?php if (in_array($active_settings_tab, ['image'], true)): // 图片公网链接前缀（自定义）?>
                <?php
                $_url_prefix = defined('URL_PREFIX') ? URL_PREFIX : '/uploads/';
                $_storage_dir = defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads';
                $_storage_url_prefix = '/' . $_storage_dir . '/';
                $_force_proxy = (defined('IMAGE_VIEW_COUNTER_ENABLED') && IMAGE_VIEW_COUNTER_ENABLED)
                    || (defined('HOTLINK_PROTECTION_ENABLED') && HOTLINK_PROTECTION_ENABLED);
                // Storage prefix 永远在第一位，其余预设保持稳定列表（去重）
                $_preset_prefixes = array_values(array_unique(array_merge([$_storage_url_prefix], ['/', '/i/', '/img/', '/photo/', '/p/'])));
                ?>
                <section>
                    <div class="settings-section-header">
                        <h3 class="settings-card-title">
                            <i class="fa-light fa-link" aria-hidden="true"></i>
                            <span>图片链接前缀</span>
                        </h3>
                        <p>自定义图片公网 URL 的前缀。物理文件实际在 <code><?= htmlspecialchars($_storage_dir) ?>/yyyy/mm/</code>，URL 前缀只改链接形态，不动磁盘。任何非 <code><?= htmlspecialchars($_storage_url_prefix) ?></code> 的前缀都会走 PHP 路由。</p>
                    </div>

                    <?php if ($_force_proxy): ?>
                        <div class="settings-callout settings-callout-compact">
                            <strong>当前由「图片请求统计」或「防盗链」强制走 <code>/i/&lt;文件&gt;</code> PHP 路由</strong>
                            <p class="m-0 text-xs text-gray">下方设置仍会保存，但只有在关闭「图片请求统计」+「防盗链」之后才会生效。</p>
                        </div>
                    <?php endif; ?>

                    <div class="settings-grid">
                        <div class="grid gap-2 col-span-2">
                            <label for="urlPrefix">URL 前缀</label>
                            <input
                                id="urlPrefix"
                                form="settingsForm"
                                type="text"
                                name="url_prefix"
                                value="<?= htmlspecialchars($_url_prefix) ?>"
                                placeholder="<?= htmlspecialchars($_storage_url_prefix) ?>"
                                autocomplete="off"
                                pattern="^/([a-z0-9][a-z0-9_-]*/)?$"
                                maxlength="32"
                                data-storage-url-prefix="<?= htmlspecialchars($_storage_url_prefix) ?>"
                                data-url-prefix-input>
                            <p class="settings-field-hint">
                                必须以 <code>/</code> 开头和结尾，中间可填任意小写字母数字 <code>_</code> <code>-</code>。
                                禁用前缀：<code>api</code> / <code>static</code> / <code>assets</code> / <code>data</code> / <code>logs</code> / <code>settings</code> / <code>gallery</code> 等系统路径。
                            </p>
                        </div>
                        <div class="grid gap-2 col-span-2">
                            <label>预设</label>
                            <div class="settings-format-tags__presets" data-url-prefix-presets>
                                <?php foreach ($_preset_prefixes as $_p): ?>
                                    <button type="button" class="settings-format-tags__preset" data-url-prefix-preset value="<?= htmlspecialchars($_p) ?>">
                                        <?= htmlspecialchars($_p) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid gap-2 col-span-2">
                            <label>预览</label>
                            <div class="settings-toggle-row settings-toggle-row-control" style="cursor:default;">
                                <span class="settings-toggle-copy">
                                    <strong>当前 URL 形态：</strong>
                                    <code data-url-prefix-preview>
                                        <?= htmlspecialchars(rtrim((string)SITE_URL, '/') . $_url_prefix) ?>2026/05/abc.webp
                                    </code>
                                    <small class="text-gray" data-url-prefix-stats-hint>
                                        <?php if ($_url_prefix === $_storage_url_prefix): ?>
                                            由 Web 服务器直接 serve（最快）— <strong>无访问统计</strong>
                                        <?php else: ?>
                                            由 PHP 路由 serve — 配合「启用图片请求计数」可统计 view_count
                                        <?php endif; ?>
                                    </small>
                                </span>
                            </div>
                        </div>
                    </div>

                    <p class="m-0 text-xs text-gray">
                        <strong>统计说明：</strong>
                        除了 <code><?= htmlspecialchars($_storage_url_prefix) ?></code>（物理目录直连，Web 服务器直接 serve，PHP 不参与），<strong>其他所有前缀（包括 <code>/i/</code>、<code>/img/</code>、<code>/photo/</code>、<code>/anyword/</code>、<code>/</code>）都会经 PHP 路由</strong>，是否累加访问数由「启用图片请求计数」开关控制。
                        <br><br>
                        <strong>简单结论：</strong>
                        想要统计 → 任选 <code><?= htmlspecialchars($_storage_url_prefix) ?></code> 之外的前缀；
                        追求最快 → 用 <code><?= htmlspecialchars($_storage_url_prefix) ?></code>。
                        <br>
                        <strong>切换前缀是安全的</strong>：老的 <code><?= htmlspecialchars($_storage_url_prefix) ?>...</code> 链接和任何 <code>/&lt;新前缀&gt;/...</code> 都能 serve 同一张图，不会死链。
                    </p>

                    <script>
                        (function () {
                            const root = document.querySelector('[data-pjax-container].settings-page');
                            if (!root) return;

                            const input = root.querySelector('[data-url-prefix-input]');
                            const presetsBox = root.querySelector('[data-url-prefix-presets]');
                            const preview = root.querySelector('[data-url-prefix-preview]');
                            const statsHint = root.querySelector('[data-url-prefix-stats-hint]');
                            if (!input || !preview) return;
                            // 物理目录对应的 URL 前缀（来自 STORAGE_DIR），落在它上 = 直连快路径无统计。
                            const storagePrefix = input.dataset.storageUrlPrefix || '/uploads/';

                            // 简易 normalise — 跟后端 PHP 的 normalizeUrlPrefix 同语义
                            const sanitize = (raw) => {
                                let s = String(raw || '').trim().toLowerCase();
                                if (s === '' || s === '/') return '/';
                                s = s.replace(/[^a-z0-9_/\-]/g, '');
                                if (!s.startsWith('/')) s = '/' + s;
                                if (!s.endsWith('/')) s = s + '/';
                                return /^\/([a-z0-9][a-z0-9_-]*\/)?$/.test(s) ? s : '';
                            };

                            const updatePreview = () => {
                                const prefix = sanitize(input.value);
                                const base = window.location.origin;
                                if (prefix === '') {
                                    preview.textContent = '⚠ 格式不合法 — 必须以 / 开头和结尾，仅允许 a-z 0-9 _ -';
                                    preview.style.color = '#d73a49';
                                    return;
                                }
                                preview.style.color = '';
                                preview.textContent = base + prefix + '2026/05/abc.webp';
                                if (statsHint) {
                                    if (prefix === storagePrefix) {
                                        statsHint.innerHTML = '由 Web 服务器直接 serve（最快）— <strong>无访问统计</strong>';
                                    } else {
                                        statsHint.textContent = '由 PHP 路由 serve — 配合「启用图片请求计数」可统计 view_count';
                                    }
                                }
                            };

                            input.addEventListener('input', updatePreview);
                            input.addEventListener('blur', () => {
                                const cleaned = sanitize(input.value);
                                if (cleaned !== '') input.value = cleaned;
                            });

                            presetsBox?.addEventListener('click', (e) => {
                                const btn = e.target.closest('[data-url-prefix-preset]');
                                if (!btn) return;
                                input.value = btn.value;
                                updatePreview();
                                input.dispatchEvent(new Event('change', { bubbles: true })); // 触发 form change → autosave
                            });
                        })();
                    </script>
                </section>
<?php endif; // tab: image (图片链接前缀 section) ?>

<?php
// 哪些 tab 用主 settings 表单（含底部"保存设置"按钮）。新结构下：
//   basic / image / storage / account 都有可保存字段
//   system (数据库) 只有备份管理 — 配置走自己的 /api/v1/backup/config，不需要主表单
$tab_uses_main_form = in_array($active_settings_tab, ['basic', 'image', 'storage', 'account'], true);
?>
<?php if ($tab_uses_main_form): ?>
                <div class="settings-save-actions">
                    <button type="submit" form="settingsForm" class="btn btn--primary btn--lg">
                        <i class="fa-light fa-floppy-disk"></i>
                        保存设置
                    </button>
                </div>
<?php endif; ?>
</div><!-- /.settings-shell -->

<script>
/*
 * 关键：这个 <script> 必须在 </main> 之前。
 *
 * Pjax.executeScripts() 只 walk 新 main 容器内的 <script> 标签来重新
 * 执行（这样 PJAX 切 tab 后所有 toggle / picker / 弹窗的 listener
 * 才会重新绑到新 DOM 上）。如果脚本写在 </main> 后面，初次访问
 * 该 tab 没问题（普通 DOMContentLoaded 跑），但通过 PJAX 切到这个
 * tab 时 listener 就会全丢 — 表现为「点开关没反应」「按钮不响应」等。
 */
(function () {
    const flashMessage = <?= json_encode($message, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const flashType = <?= json_encode($message_type === 'success' ? 'success' : 'error', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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

    /*
     * Idempotent + PJAX-aware initialiser.
     *
     * On the FIRST page load we wait for DOMContentLoaded then run init().
     * On every PJAX swap we re-run init() immediately (the new container
     * is already in the DOM by the time this script executes). The
     * `_settingsInited` marker on the container prevents double-binding
     * if the user clicks the same tab twice or another script triggers
     * a re-init.
     */
    const init = () => {
        const container = document.querySelector('[data-pjax-container].settings-page');
        if (!container) return;
        if (container._settingsInited) return;
        container._settingsInited = true;
        if (!flashShown) {
            showFlash();
            if (!flashShown) {
                setTimeout(showFlash, 80);
            }
        }

        const toggles = document.querySelectorAll('.secret-toggle-btn[data-target], button[type="button"][data-target]');
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

        const copyTokenValue = async (value) => {
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                notifySettings('Token 已复制', 'success');
            } catch (e) {
                notifySettings('复制失败，请手动复制', 'error');
            }
        };
        const bindCopyTokenButton = (btn) => {
            btn.addEventListener('click', async () => {
                const value = btn.getAttribute('data-copy') || '';
                await copyTokenValue(value);
            });
        };
        document.querySelectorAll('.copy-token-btn').forEach(bindCopyTokenButton);

        const notifySettings = (message, type = 'warning') => {
            if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                window.ImgEt.Utils.showNotification(message, type);
            }
        };

        const escapeAttr = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const updateImportTaskStatus = (status) => {
            if (!status || typeof status !== 'object') return;
            const pendingEl = document.querySelector('[data-import-task-pending]');
            const failedEl = document.querySelector('[data-import-task-failed]');
            if (pendingEl) pendingEl.textContent = String(Number(status.pending || 0));
            if (failedEl) failedEl.textContent = String(Number(status.failed || 0));
        };

        const showCreatedTokenPanel = (token) => {
            if (!token) return;
            const createForm = document.querySelector('input[name="form_action"][value="create_token"]')?.closest('form');
            if (!createForm) return;
            let panel = document.querySelector('[data-created-token-panel]');
            if (!panel) {
                panel = document.createElement('div');
                panel.className = 'settings-callout';
                panel.setAttribute('data-created-token-panel', '1');
                createForm.insertAdjacentElement('afterend', panel);
            }
            panel.innerHTML = `
                <strong>新 Token（仅显示一次）</strong>
                <div class="settings-inline-form settings-token-form">
                    <input type="text" readonly value="${escapeAttr(token)}">
                    <button type="button" class="btn btn--secondary copy-token-btn" data-copy="${escapeAttr(token)}">复制</button>
                </div>
            `;
            const copyBtn = panel.querySelector('.copy-token-btn');
            if (copyBtn) bindCopyTokenButton(copyBtn);
        };

        const setButtonBusy = (button, busy) => {
            if (!button) return;
            if (busy) {
                button.dataset.originalDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
                button.classList.add('is-loading');
            } else {
                button.disabled = button.dataset.originalDisabled === '1';
                button.classList.remove('is-loading');
                delete button.dataset.originalDisabled;
            }
        };

        // ---------------------------------------------------------------------
        // 允许上传格式 — 标签编辑器
        //
        // 之前是固定 checkbox 网格（限制于 SUPPORTED_IMAGE_TYPES）。改成 chip
        // 输入：用户可以填任意扩展名（heic / jxl / raw 等），后端只对内容做
        // image/* MIME 校验。
        //
        // 行为：
        //   • 输入框输入字符 + 回车 / 逗号 / 空格 / 失焦 → 添加 chip
        //   • 点 chip 上的 × → 移除（同时移除对应的 hidden input）
        //   • 输入框为空时按 Backspace → 移除最后一个 chip（常见交互）
        //   • 点击预设按钮 → 添加 chip + 自身从预设区消失
        //   • 同名 chip 不重复添加
        //   • 添加 / 移除都触发 form 'change' 事件，让 auto-save 接管
        // ---------------------------------------------------------------------
        const bindFormatTagsEditor = () => {
            const root = document.querySelector('[data-format-tags]');
            if (!root) return;
            const chipsBox = root.querySelector('[data-format-tags-chips]');
            const input = root.querySelector('[data-format-tags-input]');
            const presetsBox = root.querySelector('[data-format-tags-presets]');
            if (!chipsBox || !input) return;

            const sanitize = (raw) =>
                String(raw || '').toLowerCase().replace(/^[.\s]+/, '').replace(/[^a-z0-9]/g, '').slice(0, 10);

            const collectExisting = () => Array.from(
                chipsBox.querySelectorAll('input[name="upload_allowed_types[]"]')
            ).map((el) => el.value);

            const announceChange = () => {
                // 触发 form 上的 change 事件，让 auto-save / dirty 检查跟进
                const form = document.getElementById('settingsForm');
                form?.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const refreshPresetButton = (type, action) => {
                if (!presetsBox) return;
                const btn = presetsBox.querySelector(`button[data-format-tag-preset][value="${type}"]`);
                if (action === 'remove' && btn) {
                    btn.remove();
                } else if (action === 'add' && !btn) {
                    // 简单做法：移除时不把 preset 加回来（用户主动删通常是不想要了）
                    // 留给"快速添加"区只展示从未被加过的预设
                }
            };

            const addType = (raw) => {
                const type = sanitize(raw);
                if (!type) return false;
                const existing = collectExisting();
                if (existing.includes(type)) return false;

                const chip = document.createElement('span');
                chip.className = 'settings-format-tags__chip';
                chip.setAttribute('data-format-tag-chip', '');
                chip.innerHTML = `
                    <span>.${type}</span>
                    <input type="hidden" name="upload_allowed_types[]" value="${type}">
                    <button type="button" class="settings-format-tags__remove" data-format-tag-remove aria-label="移除 ${type}">
                        <i class="fa-light fa-xmark" aria-hidden="true"></i>
                    </button>
                `;
                chipsBox.insertBefore(chip, input);
                refreshPresetButton(type, 'remove');
                announceChange();
                return true;
            };

            const removeChip = (chip) => {
                if (!chip) return;
                chip.remove();
                announceChange();
            };

            const flushInput = () => {
                const value = input.value.trim();
                if (value === '') return;
                addType(value);
                input.value = '';
            };

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
                    e.preventDefault();
                    flushInput();
                } else if (e.key === 'Backspace' && input.value === '') {
                    const last = chipsBox.querySelector('span[data-format-tag-chip]:last-of-type');
                    if (last) removeChip(last);
                }
            });
            input.addEventListener('blur', flushInput);
            // 防止粘贴 "jpg, png, webp" 这种逗号串：拦截 paste 拆分
            input.addEventListener('paste', (e) => {
                const text = (e.clipboardData?.getData('text') || '').trim();
                if (!text) return;
                if (/[,;\s]/.test(text)) {
                    e.preventDefault();
                    text.split(/[,;\s]+/).forEach(addType);
                    input.value = '';
                }
            });

            chipsBox.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('[data-format-tag-remove]');
                if (removeBtn) {
                    removeChip(removeBtn.closest('[data-format-tag-chip]'));
                }
            });

            presetsBox?.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-format-tag-preset]');
                if (btn) {
                    addType(btn.value);
                }
            });
        };

        bindFormatTagsEditor();

        function syncRemoteStorageUsage() {
            const usage = document.querySelector('input[name="remote_storage_usage"]:checked')?.value || 'backup';
            const publicField = document.querySelector('[data-remote-public-url-field]');
            const requiredLabel = document.querySelector('[data-remote-public-required]');
            const note = document.querySelector('[data-remote-storage-note]');
            if (publicField) {
                publicField.hidden = usage !== 'storage';
            }
            if (requiredLabel) {
                requiredLabel.textContent = usage === 'storage' ? '（云端存储必填）' : '（云端存储时使用）';
            }
            if (note) {
                note.textContent = usage === 'storage'
                    ? '说明：云端存储模式下，LitePic 本地保留处理缓存和图库索引，图片展示、复制链接和 API 返回会优先使用 R2/S3 公网地址。公网访问域名必填，远程上传失败时需要先处理失败原因。'
                    : '说明：远程备份模式下，本地仍是主存储，R2/S3 只保存副本；图片展示、复制链接和 API 返回仍使用本站 /<?= defined('STORAGE_DIR') ? STORAGE_DIR : 'uploads' ?> 地址。本地删除后，远程对象会进入 24 小时延迟删除队列。';
            }
        }

        document.querySelectorAll('input[name="remote_storage_usage"]').forEach((input) => {
            input.addEventListener('change', syncRemoteStorageUsage);
        });
        syncRemoteStorageUsage();

        const applySavedSettingsToDom = (settings) => {
            if (!settings || typeof settings !== 'object') {
                return;
            }

            const checkboxMap = {
                auto_compress_on_upload: 'autoCompressOnUpload',
                auto_convert_on_upload: 'autoConvertOnUpload',
                keep_original_after_process: 'keepOriginalAfterProcess',
                watermark_enabled: 'watermarkEnabled',
                hotlink_protection_enabled: 'hotlinkProtectionEnabled',
                hotlink_allow_empty_referer: 'hotlinkAllowEmptyReferer',
                watermark_panel_enabled: 'watermarkPanelEnabled',
                image_view_counter_enabled: 'imageViewCounterEnabled',
            };
            Object.entries(checkboxMap).forEach(([key, id]) => {
                if (!Object.prototype.hasOwnProperty.call(settings, key)) {
                    return;
                }
                const input = document.getElementById(id);
                if (input instanceof HTMLInputElement && input.type === 'checkbox') {
                    input.checked = !!settings[key];
                }
            });

            const setRadioValue = (name, value) => {
                const input = document.querySelector(`input[type="radio"][name="${name}"][value="${String(value)}"]`);
                if (input instanceof HTMLInputElement) {
                    input.checked = true;
                }
            };
            if (Object.prototype.hasOwnProperty.call(settings, 'convert_preferred_format')) {
                setRadioValue('convert_preferred_format', settings.convert_preferred_format);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'max_file_size_mb')) {
                const input = document.getElementById('maxFileSizeMb');
                if (input instanceof HTMLInputElement) input.value = String(settings.max_file_size_mb);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'upload_max_files')) {
                const input = document.getElementById('uploadMaxFiles');
                if (input instanceof HTMLInputElement) input.value = String(settings.upload_max_files);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'upload_max_concurrent')) {
                const input = document.getElementById('uploadMaxConcurrent');
                if (input instanceof HTMLInputElement) input.value = String(settings.upload_max_concurrent);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'watermark_type')) {
                setRadioValue('watermark_type', settings.watermark_type);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'remote_storage_usage')) {
                setRadioValue('remote_storage_usage', settings.remote_storage_usage);
            }
            if (Object.prototype.hasOwnProperty.call(settings, 'upload_allowed_types') && Array.isArray(settings.upload_allowed_types)) {
                // 标签编辑器同步 — 用 server 返回的扩展名列表重建 chips。
                // 之前是 checkbox 网格，本来是逐个 .checked = true/false；
                // 现在 chip 模式下改为重新构建 hidden input 列表。
                const chipsBox = document.querySelector('[data-format-tags-chips]');
                const inputBox = document.querySelector('[data-format-tags-input]');
                if (chipsBox && inputBox) {
                    chipsBox.querySelectorAll('span[data-format-tag-chip]').forEach((c) => c.remove());
                    settings.upload_allowed_types.forEach((raw) => {
                        const type = String(raw || '').toLowerCase().replace(/^[.\s]+/, '').replace(/[^a-z0-9]/g, '').slice(0, 10);
                        if (!type) return;
                        const chip = document.createElement('span');
                        chip.className = 'settings-format-tags__chip';
                        chip.setAttribute('data-format-tag-chip', '');
                        chip.innerHTML = `
                            <span>.${type}</span>
                            <input type="hidden" name="upload_allowed_types[]" value="${type}">
                            <button type="button" class="settings-format-tags__remove" data-format-tag-remove aria-label="移除 ${type}">
                                <i class="fa-light fa-xmark" aria-hidden="true"></i>
                            </button>
                        `;
                        chipsBox.insertBefore(chip, inputBox);
                    });
                }
            }

            window.requestAnimationFrame(() => {
                if (typeof syncProcessToggles === 'function') {
                    syncProcessToggles();
                }
                if (typeof syncWatermarkConfig === 'function') {
                    syncWatermarkConfig();
                }
                if (typeof syncRemoteStorageUsage === 'function') {
                    syncRemoteStorageUsage();
                }
            });
        };

        const applyAjaxResultToDom = (form, submitter, data, action) => {
            updateImportTaskStatus(data.import_task_status);

            if (data.created_token) {
                showCreatedTokenPanel(data.created_token);
            }

            if (data.saved_settings) {
                applySavedSettingsToDom(data.saved_settings);
            }

            if (data.status !== 'success') {
                return;
            }

            if (action === 'create_token' || action === 'add_compression_api') {
                form.reset();
            }

            if (action === 'add_compression_api' && data.compression_key_added) {
                appendCompressionKeyRow(data.compression_key_added);
            }

            if (action === 'delete_compression_api') {
                // Capture enabled-ness BEFORE removing the row so we can adjust
                // the active counter when an enabled key is dropped.
                const row = submitter?.closest('tr');
                const wasEnabled = row?.cells?.[1]?.textContent?.trim() === '启用中';
                row?.remove();
                bumpCompressionStats({ totalDelta: -1, activeDelta: wasEnabled ? -1 : 0 });
            } else if (action === 'revoke_token') {
                submitter?.closest('tr')?.remove();
            }

            if (action === 'toggle_compression_api') {
                const enableInput = form.querySelector('input[name="enable"]');
                const row = submitter?.closest('tr');
                // The hidden `enable` input carries the NEXT state (what
                // the next click should do), not the current one. After a
                // successful toggle, the new actual state == that "next".
                const nowEnabled = enableInput?.value === '1';
                if (enableInput) {
                    enableInput.value = nowEnabled ? '0' : '1';
                }
                if (submitter) {
                    submitter.textContent = nowEnabled ? '禁用' : '启用';
                }
                // 状态 列在 index 1 (API Key=0, 状态=1, 总调用=2…)。
                // 之前写的是 cells[2]，状态文字会被塞进「总调用」列 — 修正。
                if (row && row.cells && row.cells[1]) {
                    row.cells[1].textContent = nowEnabled ? '启用中' : '已禁用';
                }
                bumpCompressionStats({ activeDelta: nowEnabled ? +1 : -1 });
            }
        };

        // ---- 压缩 API Key 表格的 DOM 维护 helpers ---------------------
        const appendCompressionKeyRow = (added) => {
            const tbody = document.querySelector('[data-compression-keys-tbody]');
            if (!tbody) return;
            // 移除空状态占位行（首次添加时存在）
            tbody.querySelector('.settings-empty-row')?.remove();

            const csrfToken = (window.CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value || '');
            const id = String(added.id || '');
            const masked = String(added.masked || '');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(masked)}</td>
                <td>启用中</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
                <td>-</td>
                <td>-</td>
                <td>
                    <div class="flex gap-2 flex-wrap">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="form_action" value="toggle_compression_api">
                            <input type="hidden" name="active_tab" value="image">
                            <input type="hidden" name="compression_api_id" value="${escapeHtml(id)}">
                            <input type="hidden" name="enable" value="0">
                            <button type="submit" class="btn btn--secondary">禁用</button>
                        </form>
                        <form method="post" data-confirm="确定要删除此压缩 API Key 吗？" data-confirm-title="删除 TinyPNG Key 确认">
                            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="form_action" value="delete_compression_api">
                            <input type="hidden" name="active_tab" value="image">
                            <input type="hidden" name="compression_api_id" value="${escapeHtml(id)}">
                            <button type="submit" class="btn btn--danger">删除</button>
                        </form>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
            bumpCompressionStats({ totalDelta: +1, activeDelta: +1 });
        };

        const bumpCompressionStats = ({ totalDelta = 0, activeDelta = 0 } = {}) => {
            const totalEl = document.querySelector('[data-compression-total]');
            const activeEl = document.querySelector('[data-compression-active]');
            if (totalEl) {
                const next = Math.max(0, (parseInt(totalEl.textContent, 10) || 0) + totalDelta);
                totalEl.textContent = String(next);
            }
            if (activeEl) {
                const next = Math.max(0, (parseInt(activeEl.textContent, 10) || 0) + activeDelta);
                activeEl.textContent = String(next);
            }
        };

        const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));

        const submitSettingsAjax = async (form, submitter, options = {}) => {
            if (!form || form.dataset.ajaxPending === '1') return null;

            const formData = new FormData(form);
            if (Array.isArray(options.omitNames)) {
                options.omitNames.forEach((name) => formData.delete(name));
            }
            if (submitter?.name) {
                formData.set(submitter.name, submitter.value || '');
            }
            formData.set('ajax', '1');
            if (options.autosave) {
                formData.set('autosave', '1');
            }
            const action = String(formData.get('form_action') || 'save_settings');
            const busyText = submitter?.getAttribute('data-busy-text') || '';
            if (busyText && !options.silentSuccess) {
                notifySettings(busyText, 'info');
            }

            form.dataset.ajaxPending = '1';
            setButtonBusy(submitter, true);

            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (parseError) {
                    console.error('settings ajax returned non-json', {
                        status: response.status,
                        url: response.url,
                        body: raw.slice(0, 600),
                    });
                }
                if (!data || typeof data !== 'object') {
                    throw new Error(response.status === 419
                        ? '安全令牌无效或已过期，请刷新页面后重试'
                        : '服务器返回格式异常，请查看 PHP 错误日志');
                }
                if (!options.silentSuccess || data.status !== 'success') {
                    notifySettings(data.message || (data.status === 'success' ? '操作完成' : '操作失败'), data.status === 'success' ? 'success' : 'error');
                }
                applyAjaxResultToDom(form, submitter, data, action);
                return data;
            } catch (error) {
                notifySettings(error && error.message ? error.message : '请求失败，请稍后重试', 'error');
                return null;
            } finally {
                setButtonBusy(submitter, false);
                delete form.dataset.ajaxPending;
            }
        };

        let lastSubmitter = null;
        document.addEventListener('click', (event) => {
            const button = event.target.closest('button[type="submit"], input[type="submit"]');
            if (button && document.querySelector('.settings-page')?.contains(button)) {
                lastSubmitter = button;
            }
        }, true);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !document.querySelector('.settings-page')?.contains(form)) {
                return;
            }
            event.preventDefault();

            const submitter = event.submitter || lastSubmitter || form.querySelector('button[type="submit"], input[type="submit"]');
            const confirmMessage = submitter?.getAttribute('data-confirm') || form.getAttribute('data-confirm') || '';
            const confirmTitle = submitter?.getAttribute('data-confirm-title') || form.getAttribute('data-confirm-title') || '操作确认';
            const run = () => submitSettingsAjax(form, submitter);

            if (confirmMessage) {
                if (window.ImgEt && window.ImgEt.DialogManager && typeof window.ImgEt.DialogManager.showConfirmDialog === 'function') {
                    window.ImgEt.DialogManager.showConfirmDialog(confirmTitle, confirmMessage, run);
                } else if (window.confirm(confirmMessage)) {
                    run();
                }
                return;
            }

            run();
        });

        const runtimeCapability = {
            webp: <?= !empty($compression_capability['webp']) ? 'true' : 'false' ?>,
            avif: <?= !empty($compression_capability['avif']) ? 'true' : 'false' ?>,
            heic: <?= !empty($compression_capability['heic']) ? 'true' : 'false' ?>,
            jpg: <?= (!empty($compression_capability['gd']) || !empty($compression_capability['imagick'])) ? 'true' : 'false' ?>,
            png: <?= (!empty($compression_capability['gd']) || !empty($compression_capability['imagick'])) ? 'true' : 'false' ?>,
        };
        const capabilityInputs = Array.from(document.querySelectorAll('[data-requires-capability]'));
        const validateCapabilityToggle = (input, silent = false) => {
            if (!input || !input.checked) return true;
            const capability = input.getAttribute('data-requires-capability') || '';
            if (!capability || runtimeCapability[capability]) return true;

            input.checked = false;
            if (!silent) {
                const name = input.getAttribute('data-capability-name') || capability.toUpperCase() + ' 支持';
                notifySettings(name + '未启用，无法开启该自动转换选项', 'error');
            }
            return false;
        };
        capabilityInputs.forEach((input) => {
            validateCapabilityToggle(input, true);
            input.addEventListener('change', () => {
                validateCapabilityToggle(input);
                syncProcessToggles();
                syncScanProcessToggles();
            });
        });

        // 自动处理策略互斥：自动转换开启时禁用自动压缩
        const autoCompressInput = document.getElementById('autoCompressOnUpload');
        const autoConvertInput = document.getElementById('autoConvertOnUpload');
        const convertPreferredFormatInputs = Array.from(document.querySelectorAll('input[name="convert_preferred_format"]'));
        const getConvertPreferredFormat = () => {
            const checked = convertPreferredFormatInputs.find((input) => input.checked);
            return ['webp', 'avif', 'jpg', 'png'].includes(checked?.value) ? checked.value : 'webp';
        };
        const validateAutoConvertToggle = (silent = false) => {
            if (!autoConvertInput || !autoConvertInput.checked) return true;
            const format = getConvertPreferredFormat();
            if (runtimeCapability[format]) return true;

            autoConvertInput.checked = false;
            if (!silent) {
                notifySettings(format.toUpperCase() + ' 支持未启用，无法开启上传后自动转换', 'error');
            }
            return false;
        };
        const syncProcessToggles = () => {
            if (!autoCompressInput) return;
            const hasAutoConvert = !!autoConvertInput?.checked;
            if (hasAutoConvert) {
                autoCompressInput.checked = false;
                autoCompressInput.disabled = true;
            } else {
                autoCompressInput.disabled = false;
            }
        };
        if (autoCompressInput) {
            validateAutoConvertToggle(true);
            syncProcessToggles();
            autoConvertInput?.addEventListener('change', () => {
                validateAutoConvertToggle();
                syncProcessToggles();
            });
            convertPreferredFormatInputs.forEach((input) => input.addEventListener('change', () => {
                validateAutoConvertToggle();
                syncProcessToggles();
            }));
        }

        // 扫描导入后处理进入队列，压缩与转换可以同时入队
        const scanCompressInput = document.getElementById('scanAutoCompress');
        const scanConvertInput = document.getElementById('scanAutoConvert');
        const scanConvertFormatInputs = Array.from(document.querySelectorAll('input[name="scan_convert_format"]'));
        const getScanConvertFormat = () => {
            const checked = scanConvertFormatInputs.find((input) => input.checked);
            return ['webp', 'avif', 'jpg', 'png'].includes(checked?.value) ? checked.value : 'webp';
        };
        const validateScanConvertToggle = (silent = false) => {
            if (!scanConvertInput || !scanConvertInput.checked) return true;
            const format = getScanConvertFormat();
            if (runtimeCapability[format]) return true;

            scanConvertInput.checked = false;
            if (!silent) {
                notifySettings(format.toUpperCase() + ' 支持未启用，无法开启导入时自动转换', 'error');
            }
            return false;
        };
        const syncScanProcessToggles = () => {
            if (scanCompressInput) {
                scanCompressInput.disabled = false;
            }
        };
        if (scanCompressInput) {
            validateScanConvertToggle(true);
            syncScanProcessToggles();
            scanConvertInput?.addEventListener('change', () => {
                validateScanConvertToggle();
                syncScanProcessToggles();
            });
            scanConvertFormatInputs.forEach((input) => {
                input.addEventListener('change', () => {
                    validateScanConvertToggle();
                    syncScanProcessToggles();
                });
            });
        }

        // ----------------------------------------------------------------
        // 水印 — 主开关 + 类型切换 + 磨砂层开关 控制下面整组表单的显示
        // ----------------------------------------------------------------
        const watermarkEnabledInput = document.getElementById('watermarkEnabled');
        const watermarkConfig = document.querySelector('[data-watermark-config]');
        const watermarkTypeInputs = Array.from(document.querySelectorAll('input[name="watermark_type"]'));
        const watermarkModeSections = Array.from(document.querySelectorAll('[data-watermark-mode]'));
        const watermarkPanelInput = document.getElementById('watermarkPanelEnabled');
        const watermarkPanelSettings = document.querySelector('[data-watermark-panel-settings]');
        const getWatermarkType = () => {
            const checked = watermarkTypeInputs.find((input) => input.checked);
            return checked?.value === 'image' ? 'image' : 'text';
        };
        const syncWatermarkConfig = () => {
            const enabled = !!watermarkEnabledInput?.checked;
            if (watermarkConfig) {
                watermarkConfig.hidden = !enabled;
            }

            const type = getWatermarkType();
            watermarkModeSections.forEach((section) => {
                section.hidden = !enabled || section.getAttribute('data-watermark-mode') !== type;
            });

            if (watermarkPanelSettings) {
                watermarkPanelSettings.hidden = !enabled || !watermarkPanelInput?.checked;
            }
        };
        if (watermarkEnabledInput) {
            syncWatermarkConfig();
            watermarkEnabledInput.addEventListener('change', syncWatermarkConfig);
            watermarkTypeInputs.forEach((input) => input.addEventListener('change', syncWatermarkConfig));
            watermarkPanelInput?.addEventListener('change', syncWatermarkConfig);
        }

        // ----------------------------------------------------------------
        // 防盗链 — 主开关控制下面所有相关表单（允许无来源请求 / 允许域名
        // / 服务器规则提示）的显示。关闭时这些选项无意义，全部隐藏避免
        // 用户填了之后困惑「为什么不生效」。
        // ----------------------------------------------------------------
        const hotlinkEnabledInput = document.getElementById('hotlinkProtectionEnabled');
        const hotlinkConfigBlocks = Array.from(document.querySelectorAll('[data-hotlink-config]'));
        const syncHotlinkConfig = () => {
            const enabled = !!hotlinkEnabledInput?.checked;
            hotlinkConfigBlocks.forEach((block) => { block.hidden = !enabled; });
        };
        if (hotlinkEnabledInput && hotlinkConfigBlocks.length) {
            syncHotlinkConfig();
            hotlinkEnabledInput.addEventListener('change', syncHotlinkConfig);
        }

        const settingsForm = document.getElementById('settingsForm');
        const autoSaveSettingNames = new Set([
            'auto_compress_on_upload',
            'auto_convert_on_upload',
            'convert_preferred_format',
            'upload_allowed_types[]',
            'keep_original_after_process',
            'watermark_enabled',
            'watermark_type',
            'hotlink_protection_enabled',
            'hotlink_allow_empty_referer',
            'watermark_image_clear',
            'watermark_panel_enabled',
            'image_view_counter_enabled',
        ]);
        const autoSaveOmitNames = [
            'watermark_font_upload',
            'watermark_image_upload',
        ];
        const localToggleNoticeNames = new Set([
            'scan_create_thumbnail',
            'scan_auto_compress',
            'scan_auto_convert',
        ]);
        let autoSaveTimer = 0;
        let autoSaveSource = null;
        const getAutoSaveRow = () => autoSaveSource?.closest('.settings-toggle-row') || null;
        const getAutoSaveLabel = (source) => {
            const row = source?.closest?.('.settings-toggle-row');
            const copy = row?.querySelector?.('.settings-toggle-copy');
            const text = (copy?.textContent || source?.closest?.('label')?.textContent || source?.name || '设置')
                .replace(/\s+/g, ' ')
                .trim();
            return text || '设置';
        };
        const getAutoSaveSuccessMessage = (source) => {
            const label = getAutoSaveLabel(source);
            if (source instanceof HTMLInputElement && source.name === 'watermark_image_clear') {
                return source.checked ? '当前 PNG 图片水印已清空' : '清空图片水印已取消';
            }
            if (source instanceof HTMLInputElement && source.name === 'upload_allowed_types[]') {
                return '允许上传格式已保存';
            }
            if (source instanceof HTMLInputElement && source.type === 'checkbox') {
                return `${label}已${source.checked ? '开启' : '关闭'}`;
            }
            if (source instanceof HTMLInputElement && source.type === 'radio') {
                return `${label}已保存`;
            }
            return '设置已保存';
        };
        const runSettingsAutoSave = async () => {
            if (!settingsForm) return;
            if (settingsForm.dataset.ajaxPending === '1') {
                window.setTimeout(runSettingsAutoSave, 180);
                return;
            }

            const row = getAutoSaveRow();
            row?.classList.add('is-autosaving');
            const data = await submitSettingsAjax(settingsForm, null, {
                autosave: true,
                silentSuccess: true,
                omitNames: autoSaveOmitNames,
            });
            row?.classList.remove('is-autosaving');
            if (data && data.status === 'success') {
                row?.classList.add('is-autosaved');
                window.setTimeout(() => row?.classList.remove('is-autosaved'), 650);
                notifySettings(getAutoSaveSuccessMessage(autoSaveSource), 'success');
                if (autoSaveSource instanceof HTMLInputElement && autoSaveSource.name === 'watermark_image_clear') {
                    autoSaveSource.checked = false;
                    const imagePathInput = document.getElementById('watermarkImagePath');
                    if (imagePathInput instanceof HTMLInputElement) {
                        imagePathInput.value = '';
                    }
                }
            }
        };
        const scheduleSettingsAutoSave = (source) => {
            autoSaveSource = source;
            window.clearTimeout(autoSaveTimer);
            autoSaveTimer = window.setTimeout(runSettingsAutoSave, 220);
        };
        document.addEventListener('change', (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || !settingsForm || input.form !== settingsForm) {
                return;
            }
            if (localToggleNoticeNames.has(input.name)) {
                notifySettings(getAutoSaveSuccessMessage(input), 'success');
                return;
            }
            if (!autoSaveSettingNames.has(input.name)) {
                return;
            }
            if (input.type === 'radio' && !input.checked) {
                return;
            }
            scheduleSettingsAutoSave(input);
        }, true);

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
                if (bar.tagName === 'circle' || bar.classList.contains('runtime-gauge-fill')) {
                    const r = parseFloat(bar.getAttribute('r') || '52');
                    const circumference = 2 * Math.PI * r;
                    bar.style.strokeDashoffset = String(circumference * (1 - normalized / 100));
                } else {
                    bar.style.width = normalized.toFixed(2) + '%';
                }
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
                const resp = await fetch('/api/v1/system/status', {
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
                (() => {
                    const el = document.getElementById('metricOs');
                    if (!el) return;
                    const id = String((s.distro && s.distro.id) || '').toLowerCase();
                    el.dataset.distroId = id;
                    const osText = String((s.distro && s.distro.pretty) || s.os || '-');
                    el.textContent = osText;
                })();
                setText('metricServerIp', String(s.server_ip ?? '-'));
                updateUptimeDisplay(String(s.uptime_text ?? '-'));
                const availability24h = Number(s.availability_24h_percent ?? NaN);
                setText('metricAvailability24h', Number.isFinite(availability24h) ? availability24h.toFixed(1) + '%' : '-');
                const memText = String((s.memory && s.memory.text) ? s.memory.text : '-');
                const cpuLoadText = String((s.cpu_load && s.cpu_load.text) ? s.cpu_load.text : '不可用');
                const cpuCoresVal = String((s.cpu_cores ?? '不可用'));
                const diskText = String((s.disk && s.disk.text) ? s.disk.text : '-');
                const diskFreeText = String((s.disk && s.disk.free_text) ? s.disk.free_text : '-');

                const memDetail = document.getElementById('metricMemoryDetail');
                if (memDetail) memDetail.textContent = memText;
                const cpuDetail = document.getElementById('metricCpuLoadDetail');
                if (cpuDetail) cpuDetail.textContent = cpuLoadText + ' · ' + cpuCoresVal + ' 核';
                const diskDetail = document.getElementById('metricDiskDetail');
                if (diskDetail) diskDetail.textContent = '剩余 ' + diskFreeText;

                setMetricProgress('metricMemoryCircle', 'metricMemoryPercent', s.memory && s.memory.usage_percent ? s.memory.usage_percent : 0);
                const cpuCores = Number(s.cpu_cores ?? 0);
                const load1 = Number((s.cpu_load && s.cpu_load.load_1) ?? NaN);
                const cpuLoadPercent = Number.isFinite(load1) && cpuCores > 0 ? (load1 / cpuCores) * 100 : 0;
                setMetricProgress('metricCpuLoadCircle', 'metricCpuLoadPercent', cpuLoadPercent);
                setMetricProgress('metricDiskCircle', 'metricDiskPercent', s.disk && s.disk.usage_percent ? s.disk.usage_percent : 0);

                // 上传上限卡片：直接展示 PHP / Web 服务器允许的实际值（如 "20 MB"），
                // 不再做「实际 vs 配置」的对比 — LitePic 不再让用户在后台改上限，
                // 卡片只反映服务器当前限制。统一用 is-on 绿色徽章 — 只要服务器
                // 报得出一个上限值，对图床场景就是「能用」状态。
                const uploadStatusEl = document.getElementById('metricUploadStatus');
                if (uploadStatusEl) {
                    const runtimeLimitText = String((s.php_upload_limit_text ?? '') || '');
                    if (runtimeLimitText) {
                        uploadStatusEl.textContent = runtimeLimitText;
                    }
                    uploadStatusEl.classList.remove('is-off', 'is-warn');
                    uploadStatusEl.classList.add('is-on');
                }

                const cap = s.capability || {};
                runtimeCapability.webp = !!cap.webp;
                runtimeCapability.avif = !!cap.avif;
                runtimeCapability.heic = !!cap.heic;
                runtimeCapability.jpg = !!cap.gd || !!cap.imagick;
                runtimeCapability.png = !!cap.gd || !!cap.imagick;
                setCapability('metricCapGd', !!cap.gd);
                setCapability('metricCapImagick', !!cap.imagick);
                setCapability('metricCapAvif', !!cap.avif);
                setCapability('metricCapWebp', !!cap.webp);
                setCapability('metricCapHeic', !!cap.heic);
                capabilityInputs.forEach((input) => validateCapabilityToggle(input, true));
                validateAutoConvertToggle(true);
                validateScanConvertToggle(true);
                syncProcessToggles();
                syncScanProcessToggles();
            } catch (err) {
                if (window.ImgEt && window.ImgEt.Utils && typeof window.ImgEt.Utils.showNotification === 'function') {
                    window.ImgEt.Utils.showNotification('服务器状态刷新失败', 'error');
                }
            }
        };
        setInterval(updateSystemStatus, 3000);

        // ==================== 整行 UPTIME 条 ====================
        // 来自 /api/v1/uptime?range=1h|1d|30d|90d。每次切换 range 拉一次,
        // 当前 range 的数据每 60s 自动刷新一次(1h 比较细 — 60s 一次让最右
        // 一根接近实时;其它 range 这频率也够,反正分段大)。
        (function () {
            const strip = document.querySelector('[data-uptime-strip]');
            if (!strip || strip._uptimeInited) return;
            strip._uptimeInited = true;

            const bar = strip.querySelector('[data-uptime-bar]');
            const percentEl = strip.querySelector('[data-uptime-percent]');
            const startEl = strip.querySelector('[data-uptime-start]');
            const endEl = strip.querySelector('[data-uptime-end]');
            const rangeBtns = strip.querySelectorAll('[data-uptime-range]');
            const validRanges = new Set(['1h', '1d', '30d', '90d']);
            const normalizeRange = (range) => {
                const value = String(range || '').trim().toLowerCase();
                return validRanges.has(value) ? value : '1d';
            };

            let currentRange = normalizeRange(strip.dataset.uptimeDefault || '1d');
            let refreshTimer = null;

            // 时间格式化: 同一年只显示 MM-DD HH:MM,跨年显示完整 YYYY-MM-DD
            const fmtStart = (ts) => {
                const d = new Date(ts * 1000);
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                const hh = String(d.getHours()).padStart(2, '0');
                const mi = String(d.getMinutes()).padStart(2, '0');
                return (yyyy === new Date().getFullYear())
                    ? `${yyyy}-${mm}-${dd} ${hh}:${mi}`
                    : `${yyyy}-${mm}-${dd}`;
            };

            const fmtTooltip = (seg, range) => {
                const start = new Date(seg.at * 1000);
                const isHourGrain = range === '1h' || range === '1d';
                const stamp = isHourGrain
                    ? `${String(start.getMonth()+1).padStart(2,'0')}-${String(start.getDate()).padStart(2,'0')} ${String(start.getHours()).padStart(2,'0')}:${String(start.getMinutes()).padStart(2,'0')}`
                    : `${start.getFullYear()}-${String(start.getMonth()+1).padStart(2,'0')}-${String(start.getDate()).padStart(2,'0')}`;
                const statusText = ({
                    up: '在线 100%', partial: `部分在线 ${seg.percent}%`,
                    down: '离线', future: '未到时间', no_data: '无数据',
                })[seg.status] || seg.status;
                return `${stamp} · ${statusText}`;
            };

            const renderSegments = (data) => {
                bar.innerHTML = '';
                if (!data || !Array.isArray(data.segments)) {
                    bar.innerHTML = '<div class="runtime-uptime-loading">无数据</div>';
                    return;
                }
                const frag = document.createDocumentFragment();
                for (const seg of data.segments) {
                    const div = document.createElement('div');
                    div.className = `runtime-uptime-seg is-${seg.status}`;
                    div.dataset.tooltip = fmtTooltip(seg, data.range);
                    frag.appendChild(div);
                }
                bar.appendChild(frag);
                percentEl.textContent = (typeof data.overall_percent === 'number')
                    ? `${data.overall_percent.toFixed(2)}%`
                    : '—';
                startEl.textContent = data.start_at ? fmtStart(data.start_at) : '—';
                if (endEl) endEl.textContent = 'Now';
            };

            const fetchUptime = async (range) => {
                const nextRange = normalizeRange(range);
                try {
                    const res = await fetch(`/api/v1/uptime?range=${encodeURIComponent(nextRange)}&_=${Date.now()}`, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const json = await res.json();
                    if (!res.ok || json.status !== 'success') {
                        throw new Error(json?.message || `HTTP ${res.status}`);
                    }
                    renderSegments(json.data || json);
                } catch (err) {
                    console.error('uptime fetch failed', err);
                    bar.innerHTML = '<div class="runtime-uptime-loading">加载失败</div>';
                    percentEl.textContent = '—';
                }
            };

            const setRange = (range) => {
                const nextRange = normalizeRange(range);
                currentRange = nextRange;
                rangeBtns.forEach((b) => {
                    const active = normalizeRange(b.dataset.uptimeRange) === nextRange;
                    b.classList.toggle('is-active', active);
                    b.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                fetchUptime(nextRange);
            };

            strip.addEventListener('click', (event) => {
                const button = event.target.closest('[data-uptime-range]');
                if (!button || !strip.contains(button)) return;
                event.preventDefault();
                setRange(button.dataset.uptimeRange);
            });

            setRange(currentRange);
            // 自动刷新 — 60s 拉一次,保持最右一段接近实时
            refreshTimer = setInterval(() => fetchUptime(currentRange), 60000);
        })();

        // CPU 核数现在全自动 — 无需 UI。CLI bootstrap 会探测并缓存到
        // settings.CPU_CORES_OVERRIDE,HTTP 端读缓存即可。受限主机首次
        // 运行 `php worker.php`(或任何 CLI 入口)就会填充缓存。

        // ==================== Passkey 管理 ====================
        const loadPasskeys = async () => {
            try {
                const res = await fetch('/api/passkey.php?action=list');
                const data = await res.json();
                const tbody = document.getElementById('passkeyTableBody');
                const countEl = document.getElementById('passkeyCount');
                if (!tbody || !countEl) return;

                if (data.status === 'success' && data.credentials && data.credentials.length > 0) {
                    countEl.textContent = data.credentials.length;
                    tbody.innerHTML = data.credentials.map(cred => `
                        <tr>
                            <td><code>${cred.credentialId.substring(0, 16)}...</code></td>
                            <td>${cred.createdAt}</td>
                            <td>${cred.lastUsedAt || '-'}</td>
                            <td>
                                <button type="button" class="btn btn--danger passkey-delete-btn" data-id="${escapeHtml(cred.credentialId)}">
                                    删除
                                </button>
                            </td>
                        </tr>
                    `).join('');

                    tbody.querySelectorAll('.passkey-delete-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const id = btn.getAttribute('data-id');
                            if (!id) return;
                            const ok = window.ImgEt?.DialogManager?.confirm
                                ? await window.ImgEt.DialogManager.confirm('删除 Passkey', '确定要删除此 Passkey 吗？', { danger: true, confirmText: '删除' })
                                : confirm('确定要删除此 Passkey 吗？');
                            if (!ok) return;
                            try {
                                const delRes = await fetch('/api/passkey.php?action=delete', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({ credentialId: id, csrf_token: window.CSRF_TOKEN || '' })
                                });
                                const delData = await delRes.json();
                                if (delData.status === 'success') {
                                    if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('Passkey 已删除', 'success');
                                    loadPasskeys();
                                } else {
                                    throw new Error(delData.message || '删除失败');
                                }
                            } catch (err) {
                                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification(err.message || '删除失败', 'error');
                            }
                        });
                    });
                } else {
                    countEl.textContent = '0';
                    tbody.innerHTML = '<tr class="settings-empty-row"><td colspan="4"><span class="settings-empty-state"><i class="fa-light fa-fingerprint" aria-hidden="true"></i><span>尚未注册 Passkey</span></span></td></tr>';
                }
            } catch (err) {
                console.error('Load passkeys error:', err);
            }
        };

        const registerPasskey = async () => {
            if (!window.PublicKeyCredential) {
                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('您的浏览器不支持 Passkey', 'error');
                return;
            }
            try {
                const res = await fetch('/api/passkey.php?action=register_options');
                const data = await res.json();
                if (data.status !== 'success') {
                    throw new Error(data.message || '获取注册选项失败');
                }
                const options = data;

                options.challenge = base64UrlToBuffer(options.challenge);
                options.user.id = base64UrlToBuffer(options.user.id);

                const credential = await navigator.credentials.create({ publicKey: options });
                if (!credential) {
                    throw new Error('用户取消了注册');
                }

                const verifyRes = await fetch('/api/passkey.php?action=register_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64Url(credential.response.attestationObject),
                        credentialId: bufferToBase64Url(credential.rawId)
                    })
                });

                const verifyData = await verifyRes.json();
                if (verifyData.status === 'success') {
                    if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification('Passkey 注册成功', 'success');
                    loadPasskeys();
                } else {
                    throw new Error(verifyData.message || '注册验证失败');
                }
            } catch (error) {
                console.error('Passkey register error:', error);
                if (window.ImgEt && window.ImgEt.Utils) window.ImgEt.Utils.showNotification(error.message || 'Passkey 注册失败', 'error');
            }
        };

        const base64UrlToBuffer = (base64url) => {
            const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            const pad = 4 - (base64.length % 4);
            const padded = pad !== 4 ? base64 + '='.repeat(pad) : base64;
            const binary = atob(padded);
            const buffer = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                buffer[i] = binary.charCodeAt(i);
            }
            return buffer.buffer;
        };

        const bufferToBase64Url = (buffer) => {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        };

        const passkeyRegisterBtn = document.getElementById('passkeyRegisterBtn');
        if (passkeyRegisterBtn) {
            passkeyRegisterBtn.addEventListener('click', registerPasskey);
        }
        loadPasskeys();
    };

    // First load: wait for DOMContentLoaded.
    // PJAX swap: this script is re-executed inside the new <main> by
    // Pjax.executeScripts(); document is already loaded so init() runs
    // synchronously below.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('load', () => {
        if (!flashShown) {
            showFlash();
        }
    }, { once: true });
})();
</script>

</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

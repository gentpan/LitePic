<?php
declare(strict_types=1);

session_init_safe();

$is_logged_in = ADMIN_API_KEY !== '' &&
    isset($_COOKIE[API_KEY_COOKIE]) &&
    hash_equals(hash('sha256', ADMIN_API_KEY), (string)$_COOKIE[API_KEY_COOKIE]);
$document_title = isset($html_title) && trim((string)$html_title) !== ''
    ? trim((string)$html_title)
    : ((isset($page_title) && trim((string)$page_title) !== '' ? trim((string)$page_title) . ' - ' : '') . SITE_NAME);
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($document_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/favicon/favicon-16x16.png">
    <link rel="manifest" href="/static/favicon/site.webmanifest">
    <link rel="shortcut icon" href="/favicon.ico" />

    <?php
    $css_bundle = (defined('DEBUG') && DEBUG) ? 'assets/css/main.css' : 'assets/css/main.min.css';
    if (!is_file(__DIR__ . '/' . $css_bundle)) {
        $css_bundle = 'assets/css/main.css';
    }
    $css_file = __DIR__ . '/' . $css_bundle;
    $css_ver = is_file($css_file) ? (string)filemtime($css_file) : '1';
    $force_dark_theme = isset($body_class) && is_string($body_class) && preg_match('/(^|\s)home-guest(\s|$)/', $body_class) === 1;
    $home_background_url = HOME_BACKGROUND_IMAGE;
    $home_background_path = parse_url($home_background_url, PHP_URL_PATH);
    $home_background_file = is_string($home_background_path) && str_starts_with($home_background_path, '/') ? __DIR__ . $home_background_path : '';
    if ($home_background_file === '' || !is_file($home_background_file)) {
        $home_background_url = '/static/images/background.jpg';
        $home_background_file = __DIR__ . '/static/images/background.jpg';
    }
    $home_background_ver = is_file($home_background_file) ? (string)filemtime($home_background_file) : '1';
    $home_background_url .= (str_contains($home_background_url, '?') ? '&' : '?') . 'v=' . rawurlencode($home_background_ver);
    ?>
    <script>
        (function () {
            var forceDark = <?= $force_dark_theme ? 'true' : 'false' ?>;
            var mode = 'system';
            var prefersDark = false;
            var applied = 'light';
            try {
                mode = localStorage.getItem('siteTheme') || 'system';
            } catch (e) {}
            try {
                prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            } catch (e) {}
            applied = forceDark || mode === 'dark' || (mode === 'system' && prefersDark) ? 'dark' : 'light';
            if (applied === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.backgroundColor = '#0c0c0c';
                document.documentElement.style.colorScheme = 'dark';
            } else {
                document.documentElement.removeAttribute('data-theme');
                document.documentElement.style.backgroundColor = '#f8f9fa';
                document.documentElement.style.colorScheme = 'light';
            }
        })();
    </script>
    <link rel="stylesheet" href="https://static.utterlog.com/fonts/noto-sans-sc/result.css">
    <link rel="stylesheet" href="https://static.utterlog.com/libs/fontawesome/7.2.0/css/all.min.css">
    <?php if ($force_dark_theme): ?>
        <style>
            :root {
                --home-background-image: url('<?= htmlspecialchars($home_background_url, ENT_QUOTES, 'UTF-8') ?>');
            }
        </style>
    <?php endif; ?>
    <link rel="stylesheet" href="/<?= htmlspecialchars(ltrim($css_bundle, '/'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8') ?>">
    <script>
        // 全局 CSRF Token（用于前端 AJAX 请求）
        window.CSRF_TOKEN = <?= json_encode(csrf_token_get(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>

<body<?= isset($body_class) && is_string($body_class) && $body_class !== '' ? ' class="' . htmlspecialchars($body_class) . '"' : '' ?>>
    <!-- 通知容器放在最外层 -->
    <div id="notification" class="notification-container"></div>

    <!-- 顶部导航 -->
    <header class="site-header fixed top-0 left-0 right-0 z-50 flex justify-center pt-4 px-4 transition-transform duration-300 ease-out">
        <div class="header-pill flex items-center gap-2 rounded-full">
            <!-- 左侧品牌 -->
            <div class="header-pill-brand flex items-center shrink-0">
                <a href="/" title="返回首页" class="logo-link inline-flex items-center gap-2.5 no-underline text-inherit" aria-label="返回首页">
                    <span class="logo-icon w-7 h-7 inline-flex items-center justify-center leading-none" aria-hidden="true">
                        <img src="/static/logo.png" alt="" class="logo-img-light w-full h-full" />
                        <img src="/static/logo-dark.png" alt="" class="logo-img-dark w-full h-full" />
                    </span>
                    <span class="logo-divider w-px h-5 opacity-20"></span>
                    <span class="logo-text font-logo font-bold text-lg"><?= htmlspecialchars(SITE_NAME) ?></span>
                </a>
            </div>

            <!-- 中间导航 -->
            <nav class="main-nav flex items-center gap-1">
                <span class="nav-indicator" aria-hidden="true"></span>
                <?php
                // 定义导航项（使用无后缀路由）
                $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
                $current_path = is_string($uriPath) && $uriPath !== '' ? rtrim($uriPath, '/') : '/';
                if ($current_path === '') {
                    $current_path = '/';
                }

                $nav_items = [
                    '/' => ['首页', 'fa-home'],
                    '/api' => ['API', 'fa-code'],
                    '/gallery' => ['图库', 'fa-images'],
                    '/stats' => ['统计', 'fa-chart-line'],
                    '/settings' => ['设置', 'fa-gear'],
                ];

                // 输出导航项
                foreach ($nav_items as $route => $info): ?>
                    <?php
                    $active = $current_path === $route
                        || ($route === '/' && $current_path === '/index.php')
                        || ($route !== '/' && $current_path === $route . '.php');
                    ?>
                    <a href="<?= htmlspecialchars($route) ?>"
                        class="nav-link <?= $active ? 'active' : '' ?> flex items-center gap-2 px-3 py-1.5 bg-transparent border-0 cursor-pointer text-sm font-medium no-underline transition-colors duration-200 rounded-full"
                        title="<?= $info[0] ?>">
                        <i class="fa-light <?= $info[1] ?>"></i>
                        <span><?= $info[0] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- 右侧 CTA -->
            <div class="header-pill-cta relative inline-flex items-center shrink-0">
                <?php $upload_active = $current_path === '/upload'; ?>
                <a href="/upload" class="nav-cta-btn <?= $upload_active ? 'active' : '' ?> inline-flex items-center gap-2 px-5 py-2 text-sm font-medium no-underline transition-colors duration-200 rounded-full">
                    <i class="fa-light fa-cloud-arrow-up"></i>
                    <span>上传</span>
                </a>
            </div>
        </div>
    </header>

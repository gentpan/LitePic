<?php
declare(strict_types=1);

$is_logged_in = ADMIN_API_KEY !== '' &&
    isset($_COOKIE[API_KEY_COOKIE]) &&
    hash_equals(hash('sha256', ADMIN_API_KEY), (string)$_COOKIE[API_KEY_COOKIE]);
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <title><?= isset($page_title) && trim((string)$page_title) !== '' ? trim((string)$page_title) . ' - ' : '' ?><?= htmlspecialchars(SITE_NAME) ?></title>
    <meta name="description" content="<?= htmlspecialchars(SITE_DESCRIPTION) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="/favicon.ico" />

    <?php
    $css_bundle = (defined('DEBUG') && DEBUG) ? 'assets/css/main.css' : 'assets/css/main.min.css';
    if (!is_file(__DIR__ . '/' . $css_bundle)) {
        $css_bundle = 'assets/css/main.css';
    }
    $css_file = __DIR__ . '/' . $css_bundle;
    $css_ver = is_file($css_file) ? (string)filemtime($css_file) : '1';
    ?>
    <link rel="stylesheet" href="/<?= htmlspecialchars(ltrim($css_bundle, '/'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8') ?>"> <!-- 全量样式包 -->
    <link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css"> <!-- Font Awesome Pro CDN -->
</head>

<body<?= isset($body_class) && is_string($body_class) && $body_class !== '' ? ' class="' . htmlspecialchars($body_class) . '"' : '' ?>>
    <!-- 通知容器放在最外层 -->
    <div id="notification" class="notification-container"></div>

    <!-- 顶部导航 -->
    <header class="site-header">
        <div class="header-container">
            <div class="logo">
                <a href="/" title="返回首页" class="logo-link" aria-label="返回首页">
                    <span class="logo-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512">
                            <path fill="#1777ff" d="M448 32l-448 0 0 448 448 0 0-448zM128 112a48 48 0 1 1 0 96 48 48 0 1 1 0-96zm16 160l46.1 69.1 81.9-133.1 128 208-352 0 96-144z"/>
                        </svg>
                    </span>
                    <span class="logo-text"><?= htmlspecialchars(SITE_NAME) ?></span>
                </a>
            </div>
            <nav class="main-nav">
                <?php
                // 定义导航项（使用无后缀路由）
                $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/upload'), PHP_URL_PATH);
                $current_path = is_string($uriPath) && $uriPath !== '' ? rtrim($uriPath, '/') : '/upload';
                if ($current_path === '') {
                    $current_path = '/upload';
                }

                $nav_items = [
                    '/upload' => ['首页', 'fa-home'],
                    '/docs' => ['文档', 'fa-book'],
                    '/stats' => ['统计', 'fa-chart-line']
                ];

                // 登录后添加额外导航项
                if ($is_logged_in) {
                    $nav_items = [
                        '/upload' => ['首页', 'fa-home'],
                        '/gallery' => ['图库', 'fa-images'],
                        '/docs' => ['文档', 'fa-book'],
                        '/settings' => ['设置', 'fa-gear'],
                        '/stats' => ['统计', 'fa-chart-line']
                    ];
                }

                // 输出导航项
                foreach ($nav_items as $route => $info): ?>
                    <?php
                    $active = $current_path === $route
                        || ($route === '/upload' && ($current_path === '/' || $current_path === '/index.php'));
                    ?>
                    <a href="<?= htmlspecialchars($route) ?>"
                        class="nav-link <?= $active ? 'active' : '' ?>"
                        title="<?= $info[0] ?>">
                        <i class="fa-light <?= $info[1] ?>"></i>
                        <span><?= $info[0] ?></span>
                    </a>
                <?php endforeach; ?>

                <!-- 登录/退出按钮 -->
                <div class="nav-auth">
                    <?php if ($is_logged_in): ?>
                        <button class="nav-btn logout-btn"
                                title="退出登录"
                                type="button"
                                data-cookie-name="<?= htmlspecialchars(API_KEY_COOKIE) ?>">
                            <i class="fa-light fa-right-from-bracket"></i>
                            <span>退出</span>
                        </button>
                    <?php else: ?>
                        <button
                            class="nav-btn login-btn"
                            type="button"
                            title="登录">
                            <i class="fa-light fa-right-to-bracket"></i>
                            <span>登录</span>
                        </button>
                        <div id="loginPanel" class="login-panel" aria-hidden="true">
                            <div class="login-panel-header">
                                <i class="fa-light fa-key"></i>
                                <span>管理员登录</span>
                            </div>
                            <div class="login-form">
                                <div class="input-group">
                                    <i class="fa-light fa-lock"></i>
                                    <input type="password"
                                        id="apiKey"
                                        placeholder="请输入API Key"
                                        autocomplete="off">
                                </div>
                                <button type="button" class="login-submit">
                                    <i class="fa-light fa-arrow-right-to-bracket"></i>
                                    <span>登录</span>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>


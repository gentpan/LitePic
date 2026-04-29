<?php
declare(strict_types=1);
?>

<!-- 页脚 -->
<footer class="site-footer fixed left-0 right-0 bottom-0 z-[200] w-full py-3.5 border-t border-border flex items-center transition-colors duration-200 rounded-none">
    <!-- 最左侧外侧：GitHub -->
    <div class="footer-outside-left absolute left-6 flex items-center gap-4 text-sm text-gray">
        <a href="https://github.com/gentpan/LitePic" class="inline-flex items-center gap-1.5 text-gray hover:text-dark no-underline transition-colors duration-200" title="GitHub" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-github"></i>
            <span>GitHub</span>
        </a>
    </div>

    <div class="footer-content w-full max-w-[1280px] px-6 mx-auto flex gap-6 items-center justify-center box-border">
        <!-- 版权信息 -->
        <div class="footer-copyright text-gray text-sm">
            <span class="inline-flex items-center gap-1.5">
                <span>&copy; <?= date('Y') ?></span>
                <a href="https://litepic.io" class="text-gray hover:text-dark no-underline transition-colors duration-200" target="_blank" rel="noopener noreferrer">LitePic</a>
                <span>- All rights reserved.</span>
            </span>
        </div>

        <!-- 统计信息 -->
        <div class="footer-stats flex gap-6 items-center">
            <div class="stat-item flex items-center gap-2 text-gray text-sm">
                <i class="fa-light fa-images text-base text-primary"></i>
                <span class="stat-value font-medium text-dark"><?= number_format(get_image_count()) ?></span>
                <span class="stat-label text-gray">图片数</span>
            </div>
            <div class="stat-item flex items-center gap-2 text-gray text-sm">
                <i class="fa-light fa-hard-drive text-base text-primary"></i>
                <span class="stat-value font-medium text-dark"><?= format_filesize(get_total_size()) ?></span>
                <span class="stat-label text-gray">空间</span>
            </div>
        </div>

        <!-- 文档链接 -->
        <a href="/docs" class="footer-link flex items-center gap-1.5 text-sm text-gray hover:text-dark no-underline transition-colors duration-200" title="使用说明">
            <i class="fa-light fa-book"></i>
            <span>使用说明</span>
        </a>
        <a href="/api" class="footer-link flex items-center gap-1.5 text-sm text-gray hover:text-dark no-underline transition-colors duration-200" title="API 文档">
            <i class="fa-light fa-code"></i>
            <span>API</span>
        </a>

        <?php if ($is_logged_in): ?>
            <!-- 退出按钮 -->
            <button type="button" class="logout-btn flex items-center gap-2 px-4 py-2 bg-transparent border-0 cursor-pointer text-sm font-medium text-gray hover:text-dark transition-colors duration-200 rounded-md" title="退出登录" data-cookie-name="<?= htmlspecialchars(API_KEY_COOKIE) ?>">
                <i class="fa-light fa-right-from-bracket"></i>
                <span>退出</span>
            </button>
        <?php else: ?>
            <!-- 登录按钮 -->
            <div class="footer-login relative inline-flex items-center">
                <button type="button" class="login-btn flex items-center gap-2 px-4 py-2 bg-transparent border-0 cursor-pointer text-sm font-medium text-gray hover:text-dark transition-colors duration-200 rounded-md" title="登录">
                    <i class="fa-light fa-right-to-bracket"></i>
                    <span>登录</span>
                </button>
                <div id="loginPanel" class="login-panel absolute right-0 w-[280px] p-3 border border-border bg-surface shadow-lg z-[1200] rounded-md" aria-hidden="true">
                    <div class="login-panel-header flex items-center gap-2 mb-2.5 text-dark text-sm">
                        <i class="fa-light fa-key"></i>
                        <span>管理员登录</span>
                    </div>
                    <div class="login-form">
                        <div class="input-group relative mt-4">
                            <i class="fa-light fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray pointer-events-none"></i>
                            <input type="password"
                                id="apiKey"
                                placeholder="请输入API Key"
                                autocomplete="off"
                                class="w-full py-3 pr-4 pl-10 border border-border rounded-sm text-sm transition-colors duration-200 bg-transparent focus:outline-none focus:border-primary">
                        </div>
                        <button type="button" class="login-submit w-full mt-2.5 py-3 flex items-center justify-center gap-2 bg-primary text-white border-0 rounded-sm text-sm font-medium cursor-pointer transition-colors duration-200">
                            <i class="fa-light fa-arrow-right-to-bracket"></i>
                            <span>登录</span>
                        </button>
                        <button type="button" class="login-submit login-passkey-btn w-full mt-2.5 py-3 flex items-center justify-center gap-2 border rounded-sm text-sm font-medium cursor-pointer transition-colors duration-200" style="margin-top:8px;background:transparent;border:1px solid var(--border-color);color:var(--text);">
                            <i class="fa-light fa-fingerprint"></i>
                            <span>使用 Passkey 登录</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 主题切换 -->
        <div class="theme-toggle-footer inline-flex items-center">
            <div class="theme-mode-toggle flex border border-border" role="radiogroup" aria-label="Theme selection">
                <button type="button" role="radio" aria-checked="false" data-theme-mode="dark" class="flex items-center gap-1.5 px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
                    </svg>
                    <span class="font-mono text-[10px] tracking-widest">DARK</span>
                </button>
                <button type="button" role="radio" aria-checked="false" data-theme-mode="light" class="flex items-center gap-1.5 px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2"></path>
                        <path d="M12 20v2"></path>
                        <path d="m4.93 4.93 1.41 1.41"></path>
                        <path d="m17.66 17.66 1.41 1.41"></path>
                        <path d="M2 12h2"></path>
                        <path d="M20 12h2"></path>
                        <path d="m6.34 17.66-1.41 1.41"></path>
                        <path d="m19.07 4.93-1.41 1.41"></path>
                    </svg>
                    <span class="font-mono text-[10px] tracking-widest">LIGHT</span>
                </button>
                <button type="button" role="radio" aria-checked="true" data-theme-mode="system" class="flex items-center gap-1.5 px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true">
                        <rect width="20" height="14" x="2" y="3" rx="2"></rect>
                        <line x1="8" x2="16" y1="21" y2="21"></line>
                        <line x1="12" x2="12" y1="17" y2="21"></line>
                    </svg>
                    <span class="font-mono text-[10px] tracking-widest">SYSTEM</span>
                </button>
            </div>
        </div>
    </div>
</footer>

<?php
$js_bundle = (defined('DEBUG') && DEBUG) ? 'assets/js/main.js' : 'assets/js/main.min.js';
if (!is_file(__DIR__ . '/' . $js_bundle)) {
    $js_bundle = 'assets/js/main.js';
}
$is_stats_page = isset($current_path) && is_string($current_path) && ($current_path === '/stats' || $current_path === '/stats.php');
$js_file = __DIR__ . '/' . $js_bundle;
$chart_file = __DIR__ . '/assets/js/chart.js';
$chart_ver = is_file($chart_file) ? (string)filemtime($chart_file) : '1';
$js_ver = is_file($js_file) ? (string)filemtime($js_file) : '1';
?>
<?php if ($is_stats_page): ?>
<script src="/assets/js/chart.js?v=<?= htmlspecialchars($chart_ver, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="/<?= htmlspecialchars(ltrim($js_bundle, '/'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>

</html>

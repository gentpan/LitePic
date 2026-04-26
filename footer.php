<?php
declare(strict_types=1);
?>

<!-- 页脚 -->
<footer class="site-footer">
    <div class="footer-content">
        <!-- 左侧版权信息 -->
        <div class="footer-copyright">
            <p>
                <span>&copy; <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME) ?> - All rights reserved.</span>
            </p>
        </div>

        <!-- 中间统计信息 -->
        <div class="footer-stats">
            <div class="stat-item">
                <i class="fa-light fa-eye"></i>
                <span class="stat-value"><?= number_format(get_visit_count()) ?></span>
                <span class="stat-label">访问量</span>
            </div>
            <div class="stat-item">
                <i class="fa-light fa-images"></i>
                <span class="stat-value"><?= number_format(get_image_count()) ?></span>
                <span class="stat-label">图片数</span>
            </div>
            <div class="stat-item">
                <i class="fa-light fa-hard-drive"></i>
                <span class="stat-value"><?= format_filesize(get_total_size()) ?></span>
                <span class="stat-label">空间</span>
            </div>
        </div>

        <!-- 右侧社交媒体图标 -->
        <div class="footer-links">
            <a href="https://xifeng.net" class="footer-icon" title="西风" target="_blank" rel="noopener noreferrer">
                <i class="fa-brands fa-ello"></i>
            </a>
            <a href="https://x.com/gentpan" class="footer-icon" title="X" target="_blank" rel="noopener noreferrer">
            <i class="fa-brands fa-twitter"></i>
            </a>
            <a href="https://github.com/gentpan" class="footer-icon" title="GitHub" target="_blank" rel="noopener noreferrer">
                <i class="fab fa-github"></i>
            </a>
        </div>

        <div class="footer-right">
            <div class="theme-toggle-footer">
                <div class="theme-mode-toggle flex border border-border" role="radiogroup" aria-label="Theme selection">
                    <button type="button" role="radio" aria-checked="false" data-theme-mode="dark" class="flex items-center gap-1.5 px-3 py-1.5 transition-colors duration-150 focus:outline-none focus-visible:ring-1 focus-visible:ring-secondary text-muted-foreground hover:text-foreground hover:bg-muted/50">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true">
                            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
                        </svg>
                        <span class="font-mono text-[10px] tracking-widest">DARK</span>
                    </button>
                    <button type="button" role="radio" aria-checked="false" data-theme-mode="light" class="flex items-center gap-1.5 px-3 py-1.5 transition-colors duration-150 focus:outline-none focus-visible:ring-1 focus-visible:ring-secondary text-muted-foreground hover:text-foreground hover:bg-muted/50">
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
                    <button type="button" role="radio" aria-checked="true" data-theme-mode="system" class="flex items-center gap-1.5 px-3 py-1.5 transition-colors duration-150 focus:outline-none focus-visible:ring-1 focus-visible:ring-secondary bg-secondary text-secondary-foreground">
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
    </div>
</footer>

<?php
$js_bundle = (defined('DEBUG') && DEBUG) ? 'assets/js/main.js' : 'assets/js/main.min.js';
if (!is_file(__DIR__ . '/' . $js_bundle)) {
    $js_bundle = 'assets/js/main.js';
}
$is_stats_page = isset($current_path) && is_string($current_path) && ($current_path === '/stats' || $current_path === '/stats.php');
$js_file = __DIR__ . '/' . $js_bundle;
$js_ver = is_file($js_file) ? (string)filemtime($js_file) : '1';
$chart_file = __DIR__ . '/assets/js/chart.js';
$chart_ver = is_file($chart_file) ? (string)filemtime($chart_file) : '1';
?>
<?php if ($is_stats_page): ?>
<script src="/assets/js/chart.js?v=<?= htmlspecialchars($chart_ver, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="/<?= htmlspecialchars(ltrim($js_bundle, '/'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>

</html>

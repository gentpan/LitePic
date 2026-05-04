<?php
declare(strict_types=1);

$footer_is_home_guest = isset($body_class)
    && is_string($body_class)
    && preg_match('/(^|\s)home-guest(\s|$)/', $body_class) === 1;
?>

<!-- 页脚 -->
<footer class="site-footer">
    <div class="footer-content w-full max-w-[1280px] px-4 mx-auto flex gap-6 items-center justify-between box-border">
        <!-- 左侧：版权 + 站点名称 + Powered by LitePic 徽章 -->
        <div class="footer-copyright text-gray text-sm inline-flex items-center gap-2 flex-wrap">
            <span>&copy; <?= date('Y') ?></span>
            <span><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="opacity-60">&middot;</span>
            <a href="https://litepic.io" target="_blank" rel="noopener noreferrer" class="powered-shield powered-shield--sharp" title="Powered by LitePic">
                <span class="powered-shield__label">LitePic</span>
                <span class="powered-shield__value">v<?= htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>

        <div class="footer-right">
            <nav class="footer-links" aria-label="页脚链接">
                <a href="https://github.com/gentpan/LitePic" class="footer-link footer-icon-link" title="GitHub" aria-label="GitHub" target="_blank" rel="noopener noreferrer">
                    <i class="fab fa-github" aria-hidden="true"></i>
                </a>
                <a href="https://litepic.io/docs" class="footer-link footer-icon-link" title="使用说明" aria-label="使用说明" target="_blank" rel="noopener noreferrer">
                    <i class="fa-light fa-book" aria-hidden="true"></i>
                </a>
                <a href="https://litepic.io/api" class="footer-link footer-icon-link" title="API 文档" aria-label="API 文档" target="_blank" rel="noopener noreferrer">
                    <i class="fa-light fa-code" aria-hidden="true"></i>
                </a>
                <button type="button" class="footer-link footer-icon-link" title="版权说明" aria-label="版权说明" data-license-dialog>
                    <i class="fa-light fa-copyright" aria-hidden="true"></i>
                </button>
            </nav>

            <?php if (!$footer_is_home_guest): ?>
                <!-- 主题切换 -->
                <div class="theme-toggle-footer">
                    <button type="button" class="footer-link footer-icon-link theme-menu-trigger" title="主题切换" aria-label="主题切换" aria-haspopup="true" aria-expanded="false" data-theme-menu-toggle>
                        <i class="fa-light fa-circle-half-stroke" aria-hidden="true" data-theme-trigger-icon></i>
                    </button>
                    <div class="theme-mode-toggle theme-menu-panel" role="radiogroup" aria-label="Theme selection" data-theme-menu>
                        <button type="button" role="radio" aria-checked="false" data-theme-mode="dark" class="theme-menu-option">
                            <i class="fa-light fa-moon" aria-hidden="true"></i>
                            <span>DARK</span>
                        </button>
                        <button type="button" role="radio" aria-checked="false" data-theme-mode="light" class="theme-menu-option">
                            <i class="fa-light fa-sun-bright" aria-hidden="true"></i>
                            <span>LIGHT</span>
                        </button>
                        <button type="button" role="radio" aria-checked="true" data-theme-mode="system" class="theme-menu-option">
                            <i class="fa-light fa-display" aria-hidden="true"></i>
                            <span>SYSTEM</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
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

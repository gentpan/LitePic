<?php
declare(strict_types=1);

require_once APP_ROOT . '/lib/ImageCard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * 画廊管理类
 */
class GalleryManager {
    private const SESSION_PAGE = 'gallery_page';

    private $is_admin;
    private $images;               // 当前页的图片
    private $all_images_count;     // 所有已上传的图片总数
    private $total_images;         // 当前筛选/搜索条件下的总数

    // 新增分页相关属性
    private int $per_page = ITEMS_PER_PAGE;
    private int $current_page = 1;
    private int $total_pages = 1;
    private array $paged_images = [];

    public function __construct() {
        $this->checkAuth();
        $this->loadImages();
        $this->initPagination(); // 初始化分页
    }

    private function checkAuth(): void {
        // 使用全局函数进行验证
        $this->is_admin = is_admin();
                         
        if (!$this->is_admin) {
            error_log("Unauthorized access attempt to gallery.php from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            header('Location: /upload');
            exit;
        }
    }

    private function loadImages(): void {
        // 先获取所有图片总数（使用完整的 get_uploaded_images 结果）
        $all_images = get_uploaded_images();
        $this->all_images_count = count($all_images);
        
        // 存储全部图片用于分页
        $this->images = $all_images;
        $this->total_images = $this->all_images_count;
    }

    // 初始化分页（使用 POST/SESSION，避免 URL 参数污染）
    private function initPagination(): void {
        // 兼容旧链接：仅将 page 写入 session 后跳转到无参数地址
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['page'])) {
            if (isset($_GET['page'])) {
                $legacyPage = max(1, (int)$_GET['page']);
                $_SESSION[self::SESSION_PAGE] = $legacyPage;
            }
            header('Location: /gallery');
            exit;
        }

        // 固定每页 18 张
        $this->per_page = ITEMS_PER_PAGE;

        // 处理 POST 分页请求，设置 session 后重定向（避免刷新时重复提交提示）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page'])) {
            $postPage = max(1, (int)$_POST['page']);
            $_SESSION[self::SESSION_PAGE] = $postPage;
            header('Location: /gallery');
            exit;
        }

        // page: SESSION > 1
        $page = isset($_SESSION[self::SESSION_PAGE]) ? (int)$_SESSION[self::SESSION_PAGE] : 1;
        $this->total_pages = max(1, (int)ceil($this->total_images / $this->per_page));
        $this->current_page = min(max(1, $page), $this->total_pages);
        $_SESSION[self::SESSION_PAGE] = $this->current_page;

        // 切片当前页数据
        $offset = ($this->current_page - 1) * $this->per_page;
        $this->paged_images = array_slice($this->images, $offset, $this->per_page);
    }

    private function normalizePage(int $page): int {
        return max(1, min($page, $this->total_pages));
    }

    public function render(): void {
        // 页面配置
        $page_title = '图片库';

        require_once APP_ROOT . '/header.php';
        ?>

        <main class="container page-main">
            <div class="gallery-card page-shell">
                <?= $this->renderHeader() ?>
                <?= $this->renderBody() ?>
            </div>
        </main>

        <?php
        require_once APP_ROOT . '/footer.php';
    }

    private function renderHeader(): string {
        ob_start();
        ?>
        <div class="gallery-card-header page-shell-header">
            <div class="header-left">
                <h2 class="page-shell-title">
                    <i class="fa-light fa-images"></i>
                    <span>图片库</span>
                    <small class="total-count" data-total="<?= $this->all_images_count ?>">
                        (共 <?= number_format($this->all_images_count) ?> 张图片)
                    </small>
                </h2>
            </div>
            <div class="header-right">
                <?= $this->renderFilters() ?>
                <?= $this->renderSearch() ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderFilters(): string {
        ob_start();
        ?>
        <div class="gallery-filters">
            <select class="filter-type" id="filterType" name="filter_type">
                <option value="all">所有类型</option>
                <?php foreach (ALLOWED_TYPES as $type): ?>
                    <option value="<?= $type ?>">.<?= strtoupper($type) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-sort" id="filterSort" name="filter_sort">
                <option value="date-desc">最新上传</option>
                <option value="date-asc">最早上传</option>
                <option value="size-desc">大小递减</option>
                <option value="size-asc">大小递增</option>
            </select>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderSearch(): string {
        ob_start();
        ?>
        <div class="gallery-search">
            <input type="text" placeholder="搜索图片..." class="search-input" id="searchInput" name="search_query">
            <i class="fa-light fa-search"></i>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderBody(): string {
        ob_start();
        ?>
        <div class="gallery-card-body page-shell-body">
            <?= $this->renderBatchControls() ?>
            <?= $this->renderGallery() ?>
            <?= $this->renderPagination() ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderBatchControls(): string {
        ob_start();
        ?>
        <div class="batch-controls">
            <div class="batch-left">
                <label class="select-all">
                    <input type="checkbox" id="selectAll">
                    <span>全选</span>
                </label>
                <span class="selected-count">已选择 0 张图片</span>
            </div>
            <div class="batch-right">
                <button type="button" class="batch-btn" data-action="compress" disabled>
                    <i class="fa-light fa-compress"></i>
                    <span>批量压缩</span>
                </button>
                <button type="button" class="batch-btn" data-action="<?= CONVERT_PREFERRED_FORMAT === 'avif' ? 'avif' : 'webp' ?>" disabled>
                    <i class="fa-light fa-image"></i>
                    <span>批量转<?= CONVERT_PREFERRED_FORMAT === 'avif' ? 'AVIF' : 'WebP' ?></span>
                </button>
                <button type="button" class="batch-btn delete" data-action="delete" disabled>
                    <i class="fa-light fa-trash"></i>
                    <span>批量删除</span>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function renderGallery(): string {
        ob_start();
        ?>
        <div class="gallery">
            <?php
            foreach ($this->paged_images as $img) {
                $info = get_image_info($img);
                if (!$info) {
                    error_log("Failed to get image info for: " . htmlspecialchars($img));
                    continue;
                }

                $card = new ImageCard($info, true, true, true);
                echo $card->render();
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // 新增：分页条
    private function renderPagination(): string {
        if ($this->total_pages <= 1) return '';

        $current = $this->current_page;
        $total = $this->total_pages;

        // 简单页码窗口：当前页前后各显示2页
        $start = max(1, $current - 2);
        $end = min($total, $current + 2);

        ob_start();
        ?>
        <nav class="pagination" aria-label="分页导航">
            <form method="post" class="pagination-form">
                <ul class="pagination-list">
                    <li>
                        <button type="submit" name="page" value="<?= $this->normalizePage(1) ?>" class="page-link" aria-label="第一页" <?= $current === 1 ? 'disabled aria-disabled="true"' : '' ?>>«</button>
                    </li>
                    <li>
                        <button type="submit" name="page" value="<?= $this->normalizePage($current - 1) ?>" class="page-link" aria-label="上一页" <?= $current === 1 ? 'disabled aria-disabled="true"' : '' ?>>‹</button>
                    </li>

                    <?php if ($start > 1): ?>
                        <li><button type="submit" name="page" value="1" class="page-link">1</button></li>
                        <?php if ($start > 2): ?><li><span class="page-ellipsis">…</span></li><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li>
                            <?php if ($i === $current): ?>
                                <span class="page-link active" aria-current="page"><?= $i ?></span>
                            <?php else: ?>
                                <button type="submit" name="page" value="<?= $i ?>" class="page-link"><?= $i ?></button>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>

                    <?php if ($end < $total): ?>
                        <?php if ($end < $total - 1): ?><li><span class="page-ellipsis">…</span></li><?php endif; ?>
                        <li><button type="submit" name="page" value="<?= $total ?>" class="page-link"><?= $total ?></button></li>
                    <?php endif; ?>

                    <li>
                        <button type="submit" name="page" value="<?= $this->normalizePage($current + 1) ?>" class="page-link" aria-label="下一页" <?= $current === $total ? 'disabled aria-disabled="true"' : '' ?>>›</button>
                    </li>
                    <li>
                        <button type="submit" name="page" value="<?= $this->normalizePage($total) ?>" class="page-link" aria-label="最后一页" <?= $current === $total ? 'disabled aria-disabled="true"' : '' ?>>»</button>
                    </li>
                </ul>
            </form>
        </nav>
        <?php
        return ob_get_clean();
    }
}

// 初始化并渲染页面
try {
    $gallery = new GalleryManager();
    $gallery->render();
} catch (Exception $e) {
    error_log($e->getMessage());
    header('Location: error.php');
    exit;
}

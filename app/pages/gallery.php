<?php
declare(strict_types=1);

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
        $this->is_admin = (new \LitePic\Service\Auth\AuthService())->isAdmin();
                         
        if (!$this->is_admin) {
            error_log("Unauthorized access attempt to gallery.php from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            header('Location: /upload');
            exit;
        }
    }

    private function loadImages(): void {
        // 只统计可渲染的图片。数据库可能残留文件已丢失的孤儿记录，
        // 这类记录不能参与图库总数和分页，否则会出现“有总数但无卡片”。
        $repo = new \LitePic\Repository\ImageRepository();
        $info = new \LitePic\Service\Image\ImageInfo($repo);
        $all_images = [];
        foreach ($repo->listIdentifiersSafe() as $image) {
            if ($info->getSafe((string)$image)) {
                $all_images[] = (string)$image;
            }
        }
        $this->all_images_count = count($all_images);

        // ?album=<key> 筛选 — <key> 既可以是 slug 也可以是数字 ID
        // (slug 是可选的,无 slug 的相册按数字 ID 寻址)。
        // 'none' 是特殊值,显示「未加入任何相册」的图片(零相册成员)。
        $albumKey = isset($_GET['album']) ? trim((string)$_GET['album']) : '';
        if ($albumKey !== '') {
            $albumImagesRepo = new \LitePic\Repository\AlbumImageRepository();
            if ($albumKey === 'none') {
                $albumed = [];
                foreach ($all_images as $f) {
                    if ($albumImagesRepo->albumsForFilename($f) !== []) $albumed[$f] = true;
                }
                $all_images = array_values(array_filter($all_images, static fn($f) => !isset($albumed[$f])));
            } else {
                $album = (new \LitePic\Repository\AlbumRepository())->findByKey($albumKey);
                if ($album !== null) {
                    $albumFilenames = $albumImagesRepo->listFilenames((int)$album['id']);
                    // 保留 album_images 的排序,不要被全局 listIdentifiersSafe 覆盖
                    $allowed = array_flip($albumFilenames);
                    $all_images = array_values(array_filter($albumFilenames, static fn($f) => isset($allowed[$f])));
                } else {
                    $all_images = [];
                }
            }
        }

        // 存储全部图片用于分页
        $this->images = $all_images;
        $this->total_images = count($all_images);
    }

    // 初始化分页（GET URL 承载页码，便于 PJAX / 浏览器历史 / 分享）
    private function initPagination(): void {
        // 固定每页 18 张
        $this->per_page = ITEMS_PER_PAGE;
        $this->total_pages = max(1, (int)ceil($this->total_images / $this->per_page));

        // 兼容旧 ?page=N 地址：统一跳到 /gallery/page/N。
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && isset($_GET['page'])) {
            $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/gallery'), PHP_URL_PATH);
            $normalizedPath = is_string($path) ? rtrim($path, '/') : '/gallery';
            if ($normalizedPath === '/gallery') {
                header('Location: ' . $this->pageUrl(max(1, (int)$_GET['page'])), true, 301);
                exit;
            }
        }

        // 兼容旧 POST 分页请求：转换成 GET URL，避免刷新时重复提交提示。
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page'])) {
            $postPage = max(1, (int)$_POST['page']);
            header('Location: ' . $this->pageUrl($postPage));
            exit;
        }

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $this->current_page = min(max(1, $page), $this->total_pages);
        $_SESSION[self::SESSION_PAGE] = $this->current_page;

        // 切片当前页数据
        $offset = ($this->current_page - 1) * $this->per_page;
        $this->paged_images = array_slice($this->images, $offset, $this->per_page);
    }

    private function normalizePage(int $page): int {
        return max(1, min($page, $this->total_pages));
    }

    private function pageUrl(int $page): string {
        $page = $this->normalizePage($page);
        $params = [];
        $album = isset($_GET['album']) ? trim((string)$_GET['album']) : '';
        if ($album !== '') {
            $params['album'] = $album;
        }
        $query = http_build_query($params);
        $path = $page > 1 ? '/gallery/page/' . $page : '/gallery';
        return $path . ($query !== '' ? '?' . $query : '');
    }

    public function render(): void {
        // 页面配置
        $page_title = '图片库';

        require_once APP_ROOT . '/header.php';
        ?>

        <main class="page-container page-main gallery-main" data-pjax-container>
            <section class="page-shell gallery-shell gallery-card">
                <?= $this->renderHeader() ?>
                <?= $this->renderBody() ?>
            </section>
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
        $albums = (new \LitePic\Repository\AlbumRepository())->all();
        $currentAlbumKey = isset($_GET['album']) ? (string)$_GET['album'] : '';
        ob_start();
        ?>
        <div class="gallery-filters">
            <!-- 相册筛选 — 服务端筛选(GET ?album=<key>,key 是 slug 或数字 ID),不走 JS,刷新保留 -->
            <select class="filter-album" id="filterAlbum" name="filter_album"
                    onchange="(function(s){var u=new URL(window.location.href);u.pathname='/gallery';u.searchParams.delete('page');if(s){u.searchParams.set('album',s);}else{u.searchParams.delete('album');}window.location.href=u.toString();})(this.value)">
                <option value="">全部图片</option>
                <option value="none" <?= $currentAlbumKey === 'none' ? 'selected' : '' ?>>未加入任何相册</option>
                <?php if (!empty($albums)): ?>
                    <optgroup label="按相册">
                    <?php foreach ($albums as $a): ?>
                        <?php $aKey = \LitePic\Service\Album\AlbumService::urlKey($a); ?>
                        <option value="<?= htmlspecialchars($aKey) ?>"
                                <?= $currentAlbumKey === $aKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$a['name']) ?> (<?= (int)$a['image_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
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
        $compression_labels = [
            'tinypng' => 'TinyPNG',
            'gd' => 'GD',
            'imagemagick' => 'ImageMagick',
        ];
        $compression_label = $compression_labels[strtolower((string)COMPRESSION_MODE)] ?? (string)COMPRESSION_MODE;
        $conversion_label = \LitePic\Service\Image\ImageFormat::targetLabel((string)CONVERT_PREFERRED_FORMAT);
        $conversion_action = \LitePic\Service\Image\ImageFormat::normalizeTarget((string)CONVERT_PREFERRED_FORMAT) ?: 'webp';
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
                <button type="button" class="batch-btn" data-action="compress" title="按后台默认压缩方式：<?= htmlspecialchars($compression_label) ?>" disabled>
                    <i class="fa-light fa-compress"></i>
                    <span>批量压缩</span>
                </button>
                <button type="button" class="batch-btn" data-action="<?= htmlspecialchars($conversion_action) ?>" title="按后台默认转换格式：<?= htmlspecialchars($conversion_label) ?>" disabled>
                    <i class="fa-light fa-image"></i>
                    <span>批量转换</span>
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
                $info = (new \LitePic\Service\Image\ImageInfo())->getSafe($img);
                if (!$info) {
                    error_log("Failed to get image info for: " . htmlspecialchars($img));
                    continue;
                }

                $card = new \LitePic\View\ImageCard($info, true, true, true);
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
        <nav class="pagination" aria-label="分页导航" data-pjax-scroll="preserve">
            <ul class="pagination-list">
                <li>
                    <?php if ($current === 1): ?>
                        <span class="page-link page-link--nav disabled" aria-disabled="true" aria-label="第一页" title="第一页">
                            <i class="fa-light fa-angles-left" aria-hidden="true"></i>
                        </span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($this->pageUrl(1)) ?>" data-pjax class="page-link page-link--nav" aria-label="第一页" title="第一页">
                            <i class="fa-light fa-angles-left" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($current === 1): ?>
                        <span class="page-link page-link--nav disabled" aria-disabled="true" aria-label="上一页" title="上一页">
                            <i class="fa-light fa-angle-left" aria-hidden="true"></i>
                        </span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($this->pageUrl($current - 1)) ?>" data-pjax class="page-link page-link--nav" aria-label="上一页" title="上一页">
                            <i class="fa-light fa-angle-left" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </li>

                <?php if ($start > 1): ?>
                    <li><a href="<?= htmlspecialchars($this->pageUrl(1)) ?>" data-pjax class="page-link">1</a></li>
                    <?php if ($start > 2): ?><li><span class="page-ellipsis">…</span></li><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li>
                        <?php if ($i === $current): ?>
                            <span class="page-link active" aria-current="page"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($this->pageUrl($i)) ?>" data-pjax class="page-link"><?= $i ?></a>
                        <?php endif; ?>
                    </li>
                <?php endfor; ?>

                <?php if ($end < $total): ?>
                    <?php if ($end < $total - 1): ?><li><span class="page-ellipsis">…</span></li><?php endif; ?>
                    <li><a href="<?= htmlspecialchars($this->pageUrl($total)) ?>" data-pjax class="page-link"><?= $total ?></a></li>
                <?php endif; ?>

                <li>
                    <?php if ($current === $total): ?>
                        <span class="page-link page-link--nav disabled" aria-disabled="true" aria-label="下一页" title="下一页">
                            <i class="fa-light fa-angle-right" aria-hidden="true"></i>
                        </span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($this->pageUrl($current + 1)) ?>" data-pjax class="page-link page-link--nav" aria-label="下一页" title="下一页">
                            <i class="fa-light fa-angle-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($current === $total): ?>
                        <span class="page-link page-link--nav disabled" aria-disabled="true" aria-label="最后一页" title="最后一页">
                            <i class="fa-light fa-angles-right" aria-hidden="true"></i>
                        </span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($this->pageUrl($total)) ?>" data-pjax class="page-link page-link--nav" aria-label="最后一页" title="最后一页">
                            <i class="fa-light fa-angles-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </li>
            </ul>
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

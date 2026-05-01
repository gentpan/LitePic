<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}


// 获取所有图片
$image_repo = new \LitePic\Repository\ImageRepository();
$images = $image_repo->listIdentifiersSafe();

// 图片请求统计（PHP 直读 — 来自 images.view_count）
$view_counter_enabled = \LitePic\Service\Image\ImageServeService::isViewCounterEnabled();
$total_image_requests = $image_repo->totalViews();
$top_image_rows = $image_repo->topByViews(20);
$top_images = [];
foreach ($top_image_rows as $row) {
    $top_images[] = [
        'filename' => $row['filename'],
        'original_name' => $row['original_name'] !== '' ? $row['original_name'] : $row['filename'],
        'view_count' => $row['view_count'],
        'url' => \LitePic\Service\Image\ImageUrl::forIdentifier($row['filename']),
    ];
}

// 统计数据初始化
$stats = [
    'total_images' => 0,
    'total_size' => 0,
    'by_year' => [],
    'by_month' => [],
    'by_type' => [],
    'by_size_range' => [
        '0-100KB' => 0,
        '100KB-500KB' => 0,
        '500KB-1MB' => 0,
        '1MB-5MB' => 0,
        '5MB以上' => 0
    ]
];

// 遍历处理每张图片
foreach ($images as $image) {
    $filepath = \LitePic\Service\Image\PathService::resolveFilePath($image);
    if (!file_exists($filepath)) {
        continue;
    }

    $filesize = filesize($filepath);
    $upload_time = filemtime($filepath);
    $extension = strtolower(pathinfo($image, PATHINFO_EXTENSION));

    // 总计数据
    $stats['total_images']++;
    $stats['total_size'] += $filesize;

    // 按年统计
    $year = date('Y', $upload_time);
    if (!isset($stats['by_year'][$year])) {
        $stats['by_year'][$year] = ['count' => 0, 'size' => 0];
    }
    $stats['by_year'][$year]['count']++;
    $stats['by_year'][$year]['size'] += $filesize;

    // 按月统计
    $month = date('Y-m', $upload_time);
    if (!isset($stats['by_month'][$month])) {
        $stats['by_month'][$month] = ['count' => 0, 'size' => 0];
    }
    $stats['by_month'][$month]['count']++;
    $stats['by_month'][$month]['size'] += $filesize;

    // 按类型统计
    if (!isset($stats['by_type'][$extension])) {
        $stats['by_type'][$extension] = ['count' => 0, 'size' => 0];
    }
    $stats['by_type'][$extension]['count']++;
    $stats['by_type'][$extension]['size'] += $filesize;

    // 按大小范围统计
    $size_kb = $filesize / 1024;
    if ($size_kb <= 100) {
        $stats['by_size_range']['0-100KB']++;
    } elseif ($size_kb <= 500) {
        $stats['by_size_range']['100KB-500KB']++;
    } elseif ($size_kb <= 1024) {
        $stats['by_size_range']['500KB-1MB']++;
    } elseif ($size_kb <= 5120) {
        $stats['by_size_range']['1MB-5MB']++;
    } else {
        $stats['by_size_range']['5MB以上']++;
    }
}

// 倒序显示年/月（最新在前）
krsort($stats['by_year']);
krsort($stats['by_month']);

// 页面元信息
$page_title = '数据统计';

// 计算前端 charts 所需的 labels/data
$monthly_labels = array_keys($stats['by_month']);
$monthly_counts = array_map(static function (array $m): int {
    return (int)($m['count'] ?? 0);
}, array_values($stats['by_month']));

$yearly_labels = array_keys($stats['by_year']);
$yearly_counts = array_map(static function (array $y): int {
    return (int)($y['count'] ?? 0);
}, array_values($stats['by_year']));

$type_labels = array_keys($stats['by_type']);
$type_counts = array_map(static function (array $t): int {
    return (int)($t['count'] ?? 0);
}, array_values($stats['by_type']));

$sizes_labels = array_keys($stats['by_size_range']);
$sizes_counts = array_values($stats['by_size_range']);

// 加载页面头部
require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="page-shell stats-shell">
        <div class="page-shell-header">
            <h2 class="page-shell-title">
                <i class="fa-light fa-chart-line"></i>
                <span>数据统计</span>
            </h2>
        </div>
        <div class="page-shell-body">
            <div class="stats-wrapper">
        <!-- 总览圆形卡片 -->
        <div class="stats-circles">
            <div class="stat-circle">
                <div class="stat-circle-inner">
                    <div class="stat-circle-icon"><i class="fa-light fa-images"></i></div>
                    <div class="stat-circle-value"><?= number_format($stats['total_images']) ?></div>
                    <div class="stat-circle-label">总图片数</div>
                </div>
            </div>

            <div class="stat-circle">
                <div class="stat-circle-inner">
                    <div class="stat-circle-icon"><i class="fa-light fa-hard-drive"></i></div>
                    <div class="stat-circle-value"><?= \LitePic\Core\Format::filesize($stats['total_size']) ?></div>
                    <div class="stat-circle-label">总存储空间</div>
                </div>
            </div>

            <div class="stat-circle">
                <div class="stat-circle-inner">
                    <div class="stat-circle-icon"><i class="fa-light fa-calendar-days"></i></div>
                    <div class="stat-circle-value">
                        <?php
                        $current_month = date('Y-m');
                        $monthly_count = $stats['by_month'][$current_month]['count'] ?? 0;
                        echo number_format($monthly_count);
                        ?>
                    </div>
                    <div class="stat-circle-label">本月上传</div>
                </div>
            </div>

            <div class="stat-circle">
                <div class="stat-circle-inner">
                    <div class="stat-circle-icon"><i class="fa-light fa-eye"></i></div>
                    <div class="stat-circle-value"><?= number_format($total_image_requests) ?></div>
                    <div class="stat-circle-label">图片请求</div>
                </div>
            </div>
        </div>

        <div class="access-log-summary">
            <div>
                <strong>图片请求统计</strong>
                <span>
                    <?= $view_counter_enabled ? '已启用（PHP 直读）' : '未启用' ?>，
                    累计 <?= number_format($total_image_requests) ?> 次图片请求，
                    Top <?= count($top_images) ?> 张如下表。
                </span>
            </div>
            <div>
                <?php if (!$view_counter_enabled): ?>
                    在 设置 → 系统信息 → 图片请求统计 中开启，开启后每次访问 <code>/i/&lt;文件名&gt;</code> 即累加。
                <?php else: ?>
                    数字仅统计经过 PHP 路由的请求（不含浏览器缓存命中和 CDN 命中）。
                <?php endif; ?>
            </div>
        </div>

        <!-- 图表区域 -->
        <div class="stats-charts">
            <div class="chart-container">
                <h3>月度上传统计</h3>
                <canvas id="monthlyChart"></canvas>
            </div>

            <div class="chart-container">
                <h3>年度上传趋势</h3>
                <canvas id="yearlyTrendChart"></canvas>
            </div>

            <div class="chart-container">
                <h3>文件类型分布</h3>
                <canvas id="typeChart"></canvas>
            </div>

            <div class="chart-container">
                <h3>文件大小分布</h3>
                <canvas id="sizeChart"></canvas>
            </div>
        </div>

        <!-- 详细表格 -->
        <div class="stats-tables">
            <div class="stats-table">
                <h3>年度统计</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>年份</th><th>图片数量</th><th>占用空间</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['by_year'] as $year => $data): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$year) ?></td>
                                <td><?= number_format($data['count']) ?></td>
                                <td><?= \LitePic\Core\Format::filesize($data['size']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="stats-table">
                <h3>月度统计</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>月份</th><th>图片数量</th><th>占用空间</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['by_month'] as $month => $data): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$month) ?></td>
                                <td><?= number_format($data['count']) ?></td>
                                <td><?= \LitePic\Core\Format::filesize($data['size']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="stats-table stats-table-wide">
                <h3>图片请求 Top 20</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>图片</th><th>请求次数</th><th>访问地址</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($top_images)): ?>
                            <tr>
                                <td colspan="3">暂无图片请求记录</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($top_images as $item): ?>
                                <tr>
                                    <td title="<?= htmlspecialchars((string)$item['filename']) ?>">
                                        <?= htmlspecialchars((string)$item['original_name']) ?>
                                    </td>
                                    <td><?= number_format((int)$item['view_count']) ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars((string)$item['url']) ?>" target="_blank" rel="noopener">
                                            <?= htmlspecialchars((string)$item['url']) ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
            </div>
        </div>
    </section>

<script>
    const statsData = <?= json_encode([
        'monthly' => ['labels' => $monthly_labels, 'data' => $monthly_counts],
        'yearly'  => ['labels' => $yearly_labels,  'data' => $yearly_counts],
        'types'   => ['labels' => $type_labels,    'data' => $type_counts],
        'sizes'   => ['labels' => $sizes_labels,   'data' => $sizes_counts]
    ], JSON_UNESCAPED_UNICODE) ?>;
</script>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

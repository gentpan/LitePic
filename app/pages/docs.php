<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}


$page_title = 'LitePic V2 文档';

$allowed_types = array_map(static fn(string $ext): string => '.' . strtoupper($ext), ALLOWED_TYPES);
$allowed_types_text = implode(' / ', $allowed_types);
$max_upload_mb = (int)round(MAX_FILE_SIZE / 1024 / 1024);
$compression_mode = strtoupper(get_compression_mode());
$remote_mode = strtoupper((string)REMOTE_STORAGE_MODE);
$thumb_size = THUMBNAIL_MAX_WIDTH . 'x' . THUMBNAIL_MAX_HEIGHT;

require_once APP_ROOT . '/header.php';
?>

<main class="container page-main">
    <section class="page-shell docs-shell">
        <div class="page-shell-header docs-hero">
            <h2 class="page-shell-title">
                <i class="fa-light fa-book-open-cover"></i>
                <span>LitePic V2 使用文档</span>
            </h2>
            <p class="docs-hero-desc">面向部署者、第三方调用方和 WordPress 接入方的完整说明。</p>
            <div class="docs-hero-badges">
                <span class="docs-badge">支持格式 <?= htmlspecialchars($allowed_types_text) ?></span>
                <span class="docs-badge">单文件上限 <?= $max_upload_mb ?>MB</span>
                <span class="docs-badge">缩略图 <?= htmlspecialchars($thumb_size) ?></span>
                <span class="docs-badge">压缩模式 <?= htmlspecialchars($compression_mode) ?></span>
                <span class="docs-badge">远程存储 <?= htmlspecialchars($remote_mode) ?></span>
            </div>
        </div>

        <div class="page-shell-body docs-layout">
            <section class="docs-card docs-card-featured">
                <h3>项目简介</h3>
                <p><strong>LitePic V2</strong> 是一个轻量级 PHP 图床系统，提供本地上传、第三方 API 上传、自动缩略图、自动压缩、自动转 WebP、图库管理、统计分析以及 R2/S3 远程同步能力。</p>
                <p>系统设计目标是“上传不中断”：即使压缩或转换失败，原始上传流程仍可继续完成，并在处理结果中返回具体原因。</p>
            </section>

            <section class="docs-card">
                <h3>图片功能介绍</h3>
                <div class="docs-grid docs-grid-2">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-cloud-arrow-up"></i> 上传能力</h4>
                        <p>支持单图/多图、拖拽、粘贴、第三方 API 上传，服务端统一校验格式和大小。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-image"></i> 缩略图机制</h4>
                        <p>上传后自动生成缩略图，图库列表优先展示缩略图，复制和查看使用原图地址。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-compress"></i> 自动压缩</h4>
                        <p>支持 ImageMagick / GD / TinyPNG / 混合模式。不可压缩格式自动跳过，不阻塞上传。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-file-image"></i> 自动转 WebP</h4>
                        <p>支持 JPG/JPEG/PNG/GIF 转换为 WebP，可按设置仅保留 WebP 成果文件。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-layer-group"></i> 图库管理</h4>
                        <p>支持批量选择、批量删除、批量压缩、批量转 WebP、复制多种链接格式。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-chart-line"></i> 统计分析</h4>
                        <p>提供访问量、图片数、空间占用、文件类型分布、时间维度统计。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-arrows-rotate"></i> 历史文件扫描</h4>
                        <p>可扫描旧 upload 目录并重建图库索引、缩略图与统计数据，便于迁移升级。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-database"></i> R2/S3 同步</h4>
                        <p>支持 OFF/SYNC/BACKUP 模式，可同步原图和缩略图到对象存储。</p>
                    </article>
                </div>
            </section>

            <section class="docs-card">
                <h3>上传后处理链路</h3>
                <ol class="docs-steps">
                    <li>接收文件并完成格式/大小校验。</li>
                    <li>保存原图并记录原始文件名映射。</li>
                    <li>生成缩略图（支持格式才生成）。</li>
                    <li>按配置执行自动压缩（失败只记录，不终止）。</li>
                    <li>按配置执行自动 WebP 转换（失败只记录，不终止）。</li>
                    <li>如 WebP 成功且配置要求，仅保留最终文件并清理原图。</li>
                    <li>按远程模式同步到 R2/S3，并返回处理报告。</li>
                </ol>
            </section>

            <section class="docs-card">
                <h3>第三方上传 API</h3>
                <table class="docs-table">
                    <tbody>
                        <tr><th>接口</th><td><code>POST /api/upload.php</code></td></tr>
                        <tr><th>导出</th><td><code>GET /api/export.php</code>，支持分页或 <code>all=1</code> 全量导出</td></tr>
                        <tr><th>鉴权</th><td><code>X-API-Key: &lt;token&gt;</code> 或 <code>Authorization: Bearer &lt;token&gt;</code></td></tr>
                        <tr><th>文件字段</th><td><code>image</code> / <code>image[]</code> / <code>file</code> / <code>files[]</code></td></tr>
                        <tr><th>返回</th><td><code>results[]</code>，逐文件给出 <code>status</code> / <code>url</code> / <code>thumbnail_url</code> / <code>processing</code></td></tr>
                    </tbody>
                </table>

                <div class="docs-code-block">
                    <div class="docs-code-title">cURL 示例</div>
                    <pre class="docs-code" data-lang="bash"><code>curl -X POST "https://your-domain.com/api/upload.php" \
  -H "Authorization: Bearer ltp_xxxxxxxxx" \
  -F "image[]=@/path/a.jpg" \
  -F "image[]=@/path/b.png"</code></pre>
                </div>

                <div class="docs-code-block">
                    <div class="docs-code-title">成功返回示例（节选）</div>
                    <pre class="docs-code" data-lang="json"><code>{
  "status": "success",
  "results": [
    {
      "status": "success",
      "filename": "20260227_xxx.webp",
      "original_name": "demo.jpg",
      "url": "https://your-domain.com/uploads/2026/02/20260227_xxx.webp",
      "thumbnail_url": "https://your-domain.com/uploads/2026/02/.thumbs/20260227_xxx.webp",
      "processing": {
        "auto_compress": {"enabled": true, "compressed": true, "method": "imagemagick"},
        "auto_webp": {"enabled": true, "created": true},
        "original_deleted": true,
        "final_filename": "20260227_xxx.webp"
      }
    }
  ]
}</code></pre>
                </div>

                <div class="docs-code-block">
                    <div class="docs-code-title">导出全部图片示例</div>
                    <pre class="docs-code" data-lang="bash"><code>curl "https://your-domain.com/api/export.php?all=1" \
  -H "Authorization: Bearer ltp_xxxxxxxxx"</code></pre>
                </div>
            </section>

            <section class="docs-card">
                <h3>后台图片操作接口（已登录/已鉴权）</h3>
                <table class="docs-table">
                    <thead>
                        <tr>
                            <th>操作</th>
                            <th>请求</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>压缩</td>
                            <td><code>GET /action.php?action=compress&amp;file=xxx.jpg</code></td>
                            <td>仅支持 JPG/JPEG/PNG，返回压缩比例和体积变化。</td>
                        </tr>
                        <tr>
                            <td>转 WebP</td>
                            <td><code>GET /action.php?action=webp&amp;file=xxx.png</code></td>
                            <td>支持 JPG/JPEG/PNG/GIF，成功后返回新文件 URL。</td>
                        </tr>
                        <tr>
                            <td>删除</td>
                            <td><code>GET /action.php?action=delete&amp;file=xxx.webp</code></td>
                            <td>删除原图并清理缩略图，必要时联动远程存储删除。</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="docs-card">
                <h3>Token 与安全策略</h3>
                <ul class="docs-list">
                    <li>可在系统设置中创建多个 API Token，用于第三方系统调用上传 API。</li>
                    <li>Token 支持随时撤销，撤销后立即失效。</li>
                    <li>压缩 API Key（TinyPNG）支持多 Key 轮询，系统记录每个 Key 的调用次数与状态。</li>
                    <li>建议在生产环境启用 HTTPS，并限制 Token 泄露面（仅在服务端保存）。</li>
                </ul>
            </section>

            <section class="docs-card">
                <h3>WordPress 插件对接建议</h3>
                <ol class="docs-steps">
                    <li>在插件设置中填写图床地址（例如 <code>https://your-domain.com</code>）。</li>
                    <li>填写 API Token，并点击“连接测试”。</li>
                    <li>开启“WordPress 上传同步到图床”。</li>
                    <li>在文章编辑器中使用“插入图床图片”按钮，选择已上传图片或直接上传。</li>
                </ol>
                <p class="docs-note">推荐在插件中优先使用 <code>/api/upload.php</code> 作为上传入口，返回结构稳定且带处理报告。</p>
            </section>

            <section class="docs-card">
                <h3>运维与排障</h3>
                <ul class="docs-list">
                    <li>如出现“未压缩/未转 WebP”，可在开启调试后查看 <code>logs/YYYY-MM-DD.log</code> 的 upload post-process 记录。</li>
                    <li>旧数据迁移后，可使用扫描功能重建图库数据与缩略图。</li>
                    <li>当对象存储配置不完整时，系统会回退为本地可用模式，上传不会被中断。</li>
                </ul>
            </section>
        </div>
    </section>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

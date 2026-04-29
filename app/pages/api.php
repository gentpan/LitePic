<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}

$page_title = 'API 文档';

$allowed_types = array_map(static fn(string $ext): string => '.' . strtoupper($ext), ALLOWED_TYPES);
$allowed_types_text = implode(' / ', $allowed_types);
$max_upload_mb = (int)round(MAX_FILE_SIZE / 1024 / 1024);
$compression_mode = strtoupper(get_compression_mode());
$remote_mode = strtoupper((string)REMOTE_STORAGE_MODE);
$thumb_size = THUMBNAIL_MAX_WIDTH . 'x' . THUMBNAIL_MAX_HEIGHT;

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="page-shell docs-shell overflow-hidden">
        <div class="page-shell-header docs-hero flex flex-col gap-[10px]">
            <h2 class="page-shell-title">
                <i class="fa-light fa-code"></i>
                <span>API 文档</span>
            </h2>
            <p class="docs-hero-desc text-gray text-[0.95rem]">第三方上传接口与后台操作接口的完整说明。</p>
            <div class="docs-hero-badges flex flex-wrap gap-2">
                <span class="docs-badge inline-flex items-center border border-border bg-surface text-gray rounded-md">支持格式 <?= htmlspecialchars($allowed_types_text) ?></span>
                <span class="docs-badge inline-flex items-center border border-border bg-surface text-gray rounded-md">单文件上限 <?= $max_upload_mb ?>MB</span>
                <span class="docs-badge inline-flex items-center border border-border bg-surface text-gray rounded-md">缩略图 <?= htmlspecialchars($thumb_size) ?></span>
                <span class="docs-badge inline-flex items-center border border-border bg-surface text-gray rounded-md">压缩模式 <?= htmlspecialchars($compression_mode) ?></span>
                <span class="docs-badge inline-flex items-center border border-border bg-surface text-gray rounded-md">远程存储 <?= htmlspecialchars($remote_mode) ?></span>
            </div>
        </div>

        <div class="page-shell-body docs-layout grid gap-3">
            <section class="docs-card docs-card-featured border border-border bg-surface rounded-md">
                <h3>第三方上传 API</h3>
                <table class="docs-table w-full border-collapse">
                    <tbody>
                        <tr><th>接口</th><td><code>POST /api/upload.php</code></td></tr>
                        <tr><th>导出</th><td><code>GET /api/export.php</code>，支持分页或 <code>all=1</code> 全量导出</td></tr>
                        <tr><th>鉴权</th><td><code>X-API-Key: &lt;token&gt;</code> 或 <code>Authorization: Bearer &lt;token&gt;</code></td></tr>
                        <tr><th>文件字段</th><td><code>image</code> / <code>image[]</code> / <code>file</code> / <code>files[]</code></td></tr>
                        <tr><th>返回</th><td><code>results[]</code>，逐文件给出 <code>status</code> / <code>url</code> / <code>thumbnail_url</code> / <code>processing</code></td></tr>
                    </tbody>
                </table>
            </section>

            <section class="docs-card border border-border bg-surface rounded-md">
                <h3>cURL 示例</h3>
                <div class="docs-code-block border border-[#181818] bg-[#181818] rounded-md">
                    <div class="docs-code-title flex items-center border-b border-[#303030] bg-[#222] text-[#9fb3cf]">上传多张图片</div>
                    <pre class="docs-code bg-[#181818] text-[#dbe8ff] overflow-x-auto" data-lang="bash"><code>curl -X POST "https://your-domain.com/api/upload.php" \
  -H "Authorization: Bearer ltp_xxxxxxxxx" \
  -F "image[]=@/path/a.jpg" \
  -F "image[]=@/path/b.png"</code></pre>
                </div>

                <div class="docs-code-block border border-[#181818] bg-[#181818] rounded-md">
                    <div class="docs-code-title flex items-center border-b border-[#303030] bg-[#222] text-[#9fb3cf]">成功返回示例（节选）</div>
                    <pre class="docs-code bg-[#181818] text-[#dbe8ff] overflow-x-auto" data-lang="json"><code>{
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

                <div class="docs-code-block border border-[#181818] bg-[#181818] rounded-md">
                    <div class="docs-code-title flex items-center border-b border-[#303030] bg-[#222] text-[#9fb3cf]">导出全部图片</div>
                    <pre class="docs-code bg-[#181818] text-[#dbe8ff] overflow-x-auto" data-lang="bash"><code>curl "https://your-domain.com/api/export.php?all=1" \
  -H "Authorization: Bearer ltp_xxxxxxxxx"</code></pre>
                </div>
            </section>

            <section class="docs-card border border-border bg-surface rounded-md">
                <h3>后台图片操作接口（已登录/已鉴权）</h3>
                <table class="docs-table w-full border-collapse">
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
                            <td><code>POST /action.php (form-data: action=compress, file=xxx.jpg, csrf_token=...)</code></td>
                            <td>仅支持 JPG/JPEG/PNG，返回压缩比例和体积变化。</td>
                        </tr>
                        <tr>
                            <td>转 WebP</td>
                            <td><code>POST /action.php (form-data: action=webp, file=xxx.png, csrf_token=...)</code></td>
                            <td>支持 JPG/JPEG/PNG/GIF，成功后返回新文件 URL。</td>
                        </tr>
                        <tr>
                            <td>删除</td>
                            <td><code>POST /action.php (form-data: action=delete, file=xxx.webp, csrf_token=...)</code></td>
                            <td>删除原图并清理缩略图，必要时联动远程存储删除。</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </section>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

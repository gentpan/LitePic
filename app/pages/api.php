<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}

$page_title = 'API 文档';

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="page-shell docs-shell">
        <div class="page-shell-header docs-hero">
            <h2 class="page-shell-title">
                <i class="fa-light fa-code"></i>
                <span>API 文档</span>
            </h2>
            <p class="docs-hero-desc">版本化接口、第三方上传、图库读取与后台操作接口的完整说明。</p>
        </div>

        <div class="page-shell-body docs-layout">
            <section class="docs-card docs-card-featured">
                <h3>版本化 API 总览</h3>
                <table class="docs-table">
                    <tbody>
                        <tr><th>上传</th><td><code>POST /api/v1</code></td></tr>
                        <tr><th>图库</th><td><code>GET /api/v1/list</code>，支持分页、搜索和排序</td></tr>
                        <tr><th>导出</th><td><code>GET /api/v1/export</code>，支持分页或 <code>all=1</code> 全量导出</td></tr>
                        <tr><th>后台操作</th><td><code>POST /api/v1/action</code>，支持压缩、WebP、AVIF、删除</td></tr>
                        <tr><th>鉴权</th><td><code>X-API-Key: &lt;token&gt;</code> 或 <code>Authorization: Bearer &lt;token&gt;</code></td></tr>
                        <tr><th>文件字段</th><td><code>image</code> / <code>image[]</code> / <code>file</code> / <code>files[]</code></td></tr>
                        <tr><th>返回</th><td><code>results[]</code>，逐文件给出 <code>status</code> / <code>url</code> / <code>thumbnail_url</code> / <code>processing</code></td></tr>
                    </tbody>
                </table>
            </section>

            <section class="docs-card">
                <h3>WordPress 插件对接</h3>
                <ol class="docs-steps">
                    <li>在插件设置中填写图床地址（例如 <code>https://your-domain.com</code>）。</li>
                    <li>填写 API Token，并点击"连接测试"。</li>
                    <li>开启"WordPress 上传同步到图床"。</li>
                    <li>在文章编辑器中使用"插入图床图片"按钮，选择已上传图片或直接上传。</li>
                </ol>
                <p class="docs-note">推荐在插件中优先使用 <code>/api/v1</code> 作为上传入口，返回结构稳定且带处理报告。</p>
            </section>

            <section class="docs-card">
                <h3>cURL 示例</h3>
                <div class="docs-code-block">
                    <div class="docs-code-title">上传多张图片</div>
                    <pre class="docs-code" data-lang="bash"><code>curl -X POST "https://your-domain.com/api/v1" \
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
                    <div class="docs-code-title">导出全部图片</div>
                    <pre class="docs-code" data-lang="bash"><code>curl "https://your-domain.com/api/v1/export?all=1" \
  -H "Authorization: Bearer ltp_xxxxxxxxx"</code></pre>
                </div>
            </section>

            <section class="docs-card">
                <h3>功能覆盖</h3>
                <p class="docs-note">以下接口均有后端实现；普通上传 Token 可上传和执行导出读取，图库列表与压缩、转换、删除等后台操作需要管理员登录或管理员主密钥。</p>
                <table class="docs-table">
                    <thead>
                        <tr>
                            <th>能力</th>
                            <th>请求</th>
                            <th>权限</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>上传图片</td>
                            <td><code>POST /api/v1</code></td>
                            <td>上传 Token / 管理员</td>
                        </tr>
                        <tr>
                            <td>图库列表</td>
                            <td><code>GET /api/v1/list?page=1&amp;per_page=20</code></td>
                            <td>管理员主密钥 / 管理员登录</td>
                        </tr>
                        <tr>
                            <td>迁移导出</td>
                            <td><code>GET /api/v1/export?all=1</code></td>
                            <td>上传 Token / 管理员</td>
                        </tr>
                        <tr>
                            <td>图片处理</td>
                            <td><code>POST /api/v1/action</code></td>
                            <td>管理员主密钥 / 管理员登录</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="docs-card">
                <h3>后台图片操作接口</h3>
                <p class="docs-note">页面内调用会附带 <code>csrf_token</code>；外部脚本需使用管理员主密钥，不建议使用普通上传 Token 执行后台操作。</p>
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
                            <td><code>POST /api/v1/action (form-data: action=compress, file=xxx.jpg, csrf_token=...)</code></td>
                            <td>仅支持 JPG/JPEG/PNG，返回压缩比例和体积变化。</td>
                        </tr>
                        <tr>
                            <td>转 WebP</td>
                            <td><code>POST /api/v1/action (form-data: action=webp, file=xxx.png, csrf_token=...)</code></td>
                            <td>支持 JPG/JPEG/PNG/GIF，成功后返回新文件 URL。</td>
                        </tr>
                        <tr>
                            <td>转 AVIF</td>
                            <td><code>POST /api/v1/action (form-data: action=avif, file=xxx.jpg, csrf_token=...)</code></td>
                            <td>支持 JPG/JPEG/PNG/GIF，需 PHP 8.1+ 且 GD 支持 AVIF。</td>
                        </tr>
                        <tr>
                            <td>删除</td>
                            <td><code>POST /api/v1/action (form-data: action=delete, file=xxx.webp, csrf_token=...)</code></td>
                            <td>删除原图并清理缩略图，远程对象进入 24 小时延迟删除队列。</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </section>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

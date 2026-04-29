<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/core/bootstrap.php';
}

$page_title = '使用说明';

$allowed_types = array_map(static fn(string $ext): string => '.' . strtoupper($ext), ALLOWED_TYPES);
$allowed_types_text = implode(' / ', $allowed_types);
$max_upload_mb = (int)round(MAX_FILE_SIZE / 1024 / 1024);
$compression_mode = strtoupper(get_compression_mode());
$remote_mode = strtoupper((string)REMOTE_STORAGE_MODE);
$thumb_size = THUMBNAIL_MAX_WIDTH . 'x' . THUMBNAIL_MAX_HEIGHT;

$preferred_format = strtoupper((string)CONVERT_PREFERRED_FORMAT);
$auto_compress = AUTO_COMPRESS_ON_UPLOAD ? '已开启' : '已关闭';
$auto_webp = AUTO_CONVERT_WEBP_ON_UPLOAD ? '已开启' : '已关闭';
$auto_avif = AUTO_CONVERT_AVIF_ON_UPLOAD ? '已开启' : '已关闭';
$keep_original = KEEP_ORIGINAL_AFTER_PROCESS ? '保留' : '不保留';
$exif_clean = defined('ENABLE_EXIF_CLEAN') && ENABLE_EXIF_CLEAN ? '已开启' : '已关闭';

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="page-shell docs-shell">
        <div class="page-shell-header docs-hero">
            <h2 class="page-shell-title">
                <i class="fa-light fa-book-open-cover"></i>
                <span>使用说明</span>
            </h2>
            <p class="docs-hero-desc">面向部署者、第三方调用方和前端接入方的完整参考。</p>
            <div class="docs-hero-badges">
                <span class="docs-badge">支持格式 <?= htmlspecialchars($allowed_types_text) ?></span>
                <span class="docs-badge">单文件上限 <?= $max_upload_mb ?>MB</span>
                <span class="docs-badge">缩略图 <?= htmlspecialchars($thumb_size) ?></span>
                <span class="docs-badge">压缩模式 <?= htmlspecialchars($compression_mode) ?></span>
                <span class="docs-badge">偏好格式 <?= htmlspecialchars($preferred_format) ?></span>
                <span class="docs-badge">远程存储 <?= htmlspecialchars($remote_mode) ?></span>
            </div>
        </div>

        <div class="page-shell-body docs-layout">
            <!-- 项目简介 -->
            <section class="docs-card docs-card-featured">
                <h3>项目简介</h3>
                <p><strong>LitePic V3.0</strong> 是一个轻量级 PHP 图床系统，提供本地上传、第三方 API 上传、自动缩略图、自动压缩、多格式转换（WebP / AVIF）、图库管理、统计分析以及 R2/S3 远程同步能力。</p>
                <p>系统设计目标是<strong>"上传不中断"</strong>：即使压缩或格式转换失败，原始上传流程仍可继续完成，并在处理结果中返回具体原因。后台图库支持批量压缩、批量转换、批量删除与多种链接复制格式。</p>
            </section>

            <!-- 核心功能 -->
            <section class="docs-card">
                <h3>核心功能</h3>
                <div class="docs-grid docs-grid-2">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-cloud-arrow-up"></i> 上传能力</h4>
                        <p>支持单图/多图、拖拽、粘贴、第三方 API 上传。上传页全屏布局，无额外滚动条，体验沉浸。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-image"></i> 缩略图机制</h4>
                        <p>上传后自动生成缩略图，图库列表优先展示缩略图，复制和查看使用原图地址。SVG 文件自动隐藏尺寸标签。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-compress"></i> 自动压缩</h4>
                        <p>支持 ImageMagick / GD / TinyPNG / 混合模式。不可压缩格式自动跳过，不阻塞上传。支持批量压缩与压缩比例回显。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-file-code"></i> 格式转换</h4>
                        <p>支持 JPG/JPEG/PNG/GIF 转换为 WebP 或 AVIF。可在设置中选择<strong>偏好格式</strong>，图库批量按钮与处理链路自动适配。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-layer-group"></i> 图库管理</h4>
                        <p>支持批量选择、批量删除（带确认对话框防误触）、批量压缩、批量格式转换、复制多种链接格式。分页采用 POST→GET 重定向，刷新无提示。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-chart-line"></i> 统计分析</h4>
                        <p>提供访问量、图片数、空间占用、文件类型分布、时间维度统计。概览页采用环形卡片 + 图表 + 表格组合设计。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-fingerprint"></i> Passkey 登录</h4>
                        <p>除传统 API Key 登录外，支持 WebAuthn / Passkey 无密码登录。支持注册多个凭证，随时撤销。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-database"></i> R2/S3 同步</h4>
                        <p>支持 OFF / SYNC / BACKUP 三种模式，可同步原图和缩略图到 Cloudflare R2 或 AWS S3 对象存储。</p>
                    </article>
                </div>
            </section>

            <!-- 环境配置速查 -->
            <section class="docs-card">
                <h3>环境配置速查</h3>
                <p>以下为本站当前生效的运行时配置，可在 <code>.env</code> 文件或系统设置中调整。</p>
                <table class="docs-table">
                    <tbody>
                        <tr>
                            <th>自动压缩</th>
                            <td><?= htmlspecialchars($auto_compress) ?> — 上传后自动执行压缩（仅 JPG/JPEG/PNG）</td>
                        </tr>
                        <tr>
                            <th>自动转 WebP</th>
                            <td><?= htmlspecialchars($auto_webp) ?> — 上传后自动转换为 WebP</td>
                        </tr>
                        <tr>
                            <th>自动转 AVIF</th>
                            <td><?= htmlspecialchars($auto_avif) ?> — 上传后自动转换为 AVIF（需 PHP 8.1+ 且启用 GD/Imagick AVIF 支持）</td>
                        </tr>
                        <tr>
                            <th>偏好格式</th>
                            <td><?= htmlspecialchars($preferred_format) ?> — 图库批量按钮与自动转换链路的首选目标格式</td>
                        </tr>
                        <tr>
                            <th>保留原图</th>
                            <td><?= htmlspecialchars($keep_original) ?> — 格式转换后是否保留原始文件</td>
                        </tr>
                        <tr>
                            <th>压缩模式</th>
                            <td><?= htmlspecialchars($compression_mode) ?> — HYBRID 优先尝试 ImageMagick，失败回退 GD；IMAGICK/GD/TINYPNG 为单模式</td>
                        </tr>
                        <tr>
                            <th>EXIF 清理</th>
                            <td><?= htmlspecialchars($exif_clean) ?> — 上传时是否剥离图片 EXIF 元数据</td>
                        </tr>
                        <tr>
                            <th>远程存储</th>
                            <td><?= htmlspecialchars($remote_mode) ?> — OFF 关闭 / SYNC 同步上传 / BACKUP 仅备份</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- 上传后处理链路 -->
            <section class="docs-card">
                <h3>上传后处理链路</h3>
                <ol class="docs-steps">
                    <li>接收文件并完成格式 / 大小校验。</li>
                    <li>保存原图并记录原始文件名映射（支持中文名保留）。</li>
                    <li>如开启 EXIF 清理，自动剥离敏感元数据。</li>
                    <li>生成缩略图（支持格式才生成，失败只记录不终止）。</li>
                    <li>按配置执行自动压缩（失败只记录，不终止）。</li>
                    <li>按配置执行自动格式转换（WebP 或 AVIF，由偏好格式决定；失败只记录，不终止）。</li>
                    <li>如转换成功且未开启"保留原图"，仅保留最终文件并清理原图。</li>
                    <li>按远程模式同步到 R2/S3（如启用），并返回完整处理报告。</li>
                </ol>
                <p class="docs-note"><strong>注意：</strong>每一步的异常都会记录到处理报告中，可在响应的 <code>processing</code> 字段中查看详细状态，便于排查。</p>
            </section>

            <!-- 认证与安全 -->
            <section class="docs-card">
                <h3>认证与安全</h3>
                <div class="docs-grid docs-grid-2">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-key"></i> API Key 登录</h4>
                        <p>传统密码框方式，输入管理员 API Key 后通过 SHA-256 校验并写入 Cookie。支持记住登录状态。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-fingerprint"></i> Passkey 登录</h4>
                        <p>基于 WebAuthn 标准，支持指纹 / Face ID / 硬件密钥等生物识别方式登录。可在设置中注册或撤销凭证。</p>
                    </article>
                </div>
                <ul class="docs-list" style="margin-top:12px">
                    <li>可在系统设置中创建多个第三方 API Token，用于外部系统调用上传接口。</li>
                    <li>Token 支持随时撤销，撤销后立即失效。</li>
                    <li>TinyPNG 压缩 API Key 支持多 Key 轮询，系统记录每个 Key 的调用次数与状态。</li>
                    <li>所有后台操作（压缩 / 转换 / 删除）均受 CSRF Token 保护。</li>
                    <li>建议在生产环境启用 HTTPS，并限制 Token 泄露面（仅在服务端保存）。</li>
                </ul>
            </section>

            <!-- 第三方接入 -->
            <section class="docs-card">
                <h3>第三方接入</h3>

                <h4><i class="fa-light fa-wordpress"></i> WordPress 插件对接</h4>
                <ol class="docs-steps">
                    <li>在插件设置中填写图床地址（例如 <code>https://your-domain.com</code>）。</li>
                    <li>填写 API Token，并点击"连接测试"。</li>
                    <li>开启"WordPress 上传同步到图床"。</li>
                    <li>在文章编辑器中使用"插入图床图片"按钮，选择已上传图片或直接上传。</li>
                </ol>
                <p class="docs-note">推荐在插件中优先使用 <code>/api/upload.php</code> 作为上传入口，返回结构稳定且带处理报告。</p>

                <h4 style="margin-top:16px"><i class="fa-light fa-code"></i> 上传 API 速查</h4>
                <table class="docs-table">
                    <tbody>
                        <tr><th>接口</th><td><code>POST /api/upload.php</code></td></tr>
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
            </section>

            <!-- 后台操作接口 -->
            <section class="docs-card">
                <h3>后台图片操作接口</h3>
                <p>以下接口需登录或携带有效 API Key，所有请求均需附带 <code>csrf_token</code>。</p>
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
                            <td><code>POST /action.php</code><br>action=compress, file=xxx.jpg</td>
                            <td>仅支持 JPG/JPEG/PNG，返回压缩比例和体积变化。</td>
                        </tr>
                        <tr>
                            <td>转 WebP</td>
                            <td><code>POST /action.php</code><br>action=webp, file=xxx.png</td>
                            <td>支持 JPG/JPEG/PNG/GIF，成功后返回新文件 URL。</td>
                        </tr>
                        <tr>
                            <td>转 AVIF</td>
                            <td><code>POST /action.php</code><br>action=avif, file=xxx.jpg</td>
                            <td>支持 JPG/JPEG/PNG/GIF，需 PHP 8.1+ 且编译了 AVIF 支持。</td>
                        </tr>
                        <tr>
                            <td>删除</td>
                            <td><code>POST /action.php</code><br>action=delete, file=xxx.webp</td>
                            <td>删除原图并清理缩略图，必要时联动远程存储删除。</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- 运维与排障 -->
            <section class="docs-card">
                <h3>运维与排障</h3>
                <ul class="docs-list">
                    <li>如出现"未压缩 / 未转 WebP / 未转 AVIF"，可在开启调试后查看 <code>logs/YYYY-MM-DD.log</code> 的 upload post-process 记录。</li>
                    <li>旧数据迁移后，可使用设置中的扫描功能重建图库数据、缩略图与统计数据。</li>
                    <li>当对象存储配置不完整时，系统会回退为本地可用模式，上传不会被中断。</li>
                    <li>若 AVIF 转换失败，请确认 PHP 版本 ≥ 8.1，且 GD/Imagick 已编译 AVIF 支持（<code>php -m | grep -i avif</code>）。</li>
                    <li>页脚统计数据采用缓存机制，若需刷新可手动清除 <code>data/footer_stats_cache.json</code>。</li>
                </ul>
            </section>
        </div>
    </section>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

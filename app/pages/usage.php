<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/app/Core/bootstrap.php';
}

$page_title = '使用说明';

$preferred_format = strtoupper((string)CONVERT_PREFERRED_FORMAT);
$auto_compress = AUTO_COMPRESS_ON_UPLOAD ? '已开启' : '已关闭';
$auto_webp = AUTO_CONVERT_WEBP_ON_UPLOAD ? '已开启' : '已关闭';
$auto_avif = AUTO_CONVERT_AVIF_ON_UPLOAD ? '已开启' : '已关闭';
$keep_original = KEEP_ORIGINAL_AFTER_PROCESS ? '保留' : '不保留';
$exif_clean = defined('ENABLE_EXIF_CLEAN') && ENABLE_EXIF_CLEAN ? '已开启' : '已关闭';
$web_server = detect_web_server_software();
$web_server_label = (string)$web_server['label'];
$web_server_raw = (string)$web_server['raw'];
$hotlink_hosts = hotlink_allowed_hosts();
if (empty($hotlink_hosts)) {
    $hotlink_hosts = ['example.com'];
}
$hotlink_domain_text = implode(', ', $hotlink_hosts);
$hotlink_regex = implode('|', array_map(static fn(string $host): string => preg_quote($host, '/'), $hotlink_hosts));
$nginx_referers = [];
foreach ($hotlink_hosts as $host) {
    $nginx_referers[] = $host;
    if (str_contains($host, '.') && !str_starts_with($host, '*.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
        $nginx_referers[] = '*.' . $host;
    }
}
$nginx_referers = array_values(array_unique($nginx_referers));
$nginx_valid_referers = trim((HOTLINK_ALLOW_EMPTY_REFERER ? 'none blocked ' : '') . 'server_names ' . implode(' ', $nginx_referers));
$apache_empty_referer_rule = HOTLINK_ALLOW_EMPTY_REFERER ? '    RewriteCond %{HTTP_REFERER} !^$' . PHP_EOL : '';
$apache_hotlink_snippet = '<IfModule mod_rewrite.c>' . PHP_EOL
    . '    RewriteEngine On' . PHP_EOL
    . $apache_empty_referer_rule
    . '    RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?(' . $hotlink_regex . ')(:[0-9]+)?(/|$) [NC]' . PHP_EOL
    . '    RewriteRule ^uploads/.*\.(jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|tiff|tif)$ - [F,L]' . PHP_EOL
    . '</IfModule>';
$nginx_hotlink_snippet = 'location ~* ^/uploads/.*\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?)$ {' . PHP_EOL
    . '    valid_referers ' . $nginx_valid_referers . ';' . PHP_EOL
    . '    if ($invalid_referer) { return 403; }' . PHP_EOL
    . '    try_files $uri =404;' . PHP_EOL
    . '}';
$caddy_header_rule = HOTLINK_ALLOW_EMPTY_REFERER ? '    header Referer *' . PHP_EOL : '';
$caddy_hotlink_snippet = '@badImageReferer {' . PHP_EOL
    . '    path /uploads/*' . PHP_EOL
    . $caddy_header_rule
    . '    not {' . PHP_EOL
    . '        header_regexp Referer ^https?://([^/]+\.)?(' . $hotlink_regex . ')(:[0-9]+)?(/|$)' . PHP_EOL
    . '    }' . PHP_EOL
    . '}' . PHP_EOL . PHP_EOL
    . 'respond @badImageReferer 403';

require_once APP_ROOT . '/header.php';
?>

<main class="page-container page-main">
    <section class="page-shell docs-shell">
        <div class="page-shell-header docs-hero">
            <h2 class="page-shell-title">
                <i class="fa-light fa-book-open-cover"></i>
                <span>使用说明</span>
            </h2>
            <p class="docs-hero-desc">面向部署者和日常使用者的完整参考。</p>
        </div>

        <div class="page-shell-body docs-layout">
            <!-- 项目简介 -->
            <section class="docs-card docs-card-featured">
                <h3>项目简介</h3>
                <p><strong>LitePic V3.0</strong> 面向个人站长、自托管用户和轻量团队，提供一个开箱即用的 PHP 图床后台。它不依赖复杂服务栈，把上传、缩略图、压缩、WebP / AVIF 转换、图库管理、访问统计和 R2/S3 同步集中在一个简洁的单机应用里。</p>
                <p>LitePic 的核心思路是<strong>先保证图片成功入库，再异步处理耗时任务</strong>。压缩、转换、水印、缩略图或远程同步失败时，不会拖垮上传流程；系统会记录处理结果，方便后续排查和批量补处理。日常使用时，你可以直接复制 URL、HTML、Markdown、BBCode，也可以在图库中批量压缩、转换和删除。</p>
            </section>

            <!-- 核心功能 -->
            <section class="docs-card">
                <h3>核心功能</h3>
                <div class="docs-grid docs-grid-2">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-cloud-arrow-up"></i> 上传能力</h4>
                        <p>支持单图/多图、拖拽、粘贴上传。上传页全屏布局，无额外滚动条，体验沉浸。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-image"></i> 缩略图机制</h4>
                        <p>上传后自动生成缩略图，图库列表优先展示缩略图，复制和查看使用原图地址。SVG 可识别宽高时显示分辨率，无法识别时显示“矢量图”。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-compress"></i> 自动压缩</h4>
                        <p>支持 ImageMagick / GD / TinyPNG 三种压缩方式。不可压缩格式自动跳过，不阻塞上传。支持批量压缩与压缩比例回显。</p>
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
                        <h4><i class="fa-light fa-database"></i> R2/S3 远程存储</h4>
                        <p>支持远程备份和云端存储两种用途。备份模式保留本站图片地址；云端存储模式会让复制链接、API 返回和图库图片优先使用 R2/S3 公网地址。</p>
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
                            <td><?= htmlspecialchars($auto_avif) ?> — 上传后自动转换为 AVIF（需 PHP 8.1+ 且启用 GD AVIF 支持）</td>
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
                            <th>EXIF 清理</th>
                            <td><?= htmlspecialchars($exif_clean) ?> — 上传时是否剥离图片 EXIF 元数据</td>
                        </tr>
                        <tr>
                            <th>服务器检测</th>
                            <td><?= htmlspecialchars($web_server_label) ?><?= $web_server_raw !== '' ? ' — ' . htmlspecialchars($web_server_raw) : ' — 当前环境未提供 SERVER_SOFTWARE' ?></td>
                        </tr>
                        <tr>
                            <th>防盗链域名</th>
                            <td><?= htmlspecialchars($hotlink_domain_text) ?> — 设置页保存后会用于 Referer 白名单</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- 压缩方式选择 -->
            <section class="docs-card" id="compression-modes">
                <h3>压缩方式选择</h3>
                <p>设置页的“压缩方式”只影响 JPG/JPEG/PNG 的压缩链路，不影响 WebP / AVIF 转换。当前系统不会混合回退，选择哪一种就只调用对应后端。</p>
                <div class="docs-grid docs-grid-3">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-wand-magic-sparkles"></i> TinyPNG</h4>
                        <p>走外部 TinyPNG API，压缩率通常稳定，适合 JPEG/PNG 体积优化。需要配置 API Key、服务器能访问外网，并注意调用额度；不负责 WebP / AVIF 转换。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-code"></i> GD</h4>
                        <p>PHP 常见内置扩展，部署最简单，适合基础 JPG/PNG 压缩。WebP / AVIF 转换也依赖 GD 的 <code>imagewebp()</code> / <code>imageavif()</code>，但画质控制和格式能力弱于 ImageMagick。</p>
                    </article>
                    <article class="docs-item">
                        <h4><i class="fa-light fa-image"></i> ImageMagick</h4>
                        <p>调用服务器上的 <code>magick</code> 或 <code>convert</code> 命令进行压缩，适合更复杂的图片处理和较大的图片。需要服务器允许命令执行并安装 ImageMagick；当前 WebP / AVIF 转换仍按 GD 函数检测。</p>
                    </article>
                </div>
                <p class="docs-note"><strong>转换策略：</strong>单张 WebP / AVIF 转换会直接处理并返回结果；图库批量转 AVIF 选择 2 张及以上时会进入异步任务队列，避免单个 PHP 请求长时间占用 CPU。</p>
            </section>

            <!-- 修改 PHP 上传限制 -->
            <section class="docs-card">
                <h3>修改 PHP 上传限制</h3>
                <p>LitePic 的后台设置会同步写入 <code>.env</code> 和站点根目录 <code>.user.ini</code>，但最终是否生效仍取决于 PHP-FPM、Nginx/Apache 和面板环境的上限。推荐先在后台设置中保存一次，再按服务器环境补齐下面的限制。</p>
                <ol class="docs-steps">
                    <li>进入 <strong>设置 → 基础设置</strong>，修改“最大上传大小（MB）”，保存后系统会写入 <code>MAX_FILE_SIZE</code> 与 <code>.user.ini</code>。</li>
                    <li>确认 PHP 的 <code>upload_max_filesize</code> 不小于后台设置值，<code>post_max_size</code> 要略大于 <code>upload_max_filesize</code>。</li>
                    <li>Nginx 还需要配置 <code>client_max_body_size</code>，否则大文件会在进入 PHP 前被拒绝。</li>
                    <li>修改 PHP-FPM 或 Web 服务器配置后，需要重载或重启对应服务。</li>
                </ol>

                <div class="docs-tutorial-list">
                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-file-pen"></i> php.ini / PHP-FPM</h4>
                        <p>适用于自建环境、Docker、宝塔面板 PHP 配置页等，改完后需要重载当前 PHP-FPM。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">php.ini 推荐值</div>
                            <pre class="docs-code" data-lang="ini"><code>upload_max_filesize = 50M
post_max_size = 52M
max_file_uploads = 50
memory_limit = 256M
max_execution_time = 120
max_input_time = 120</code></pre>
                        </div>
                        <div class="docs-code-block">
                            <div class="docs-code-title">重载 PHP-FPM 示例</div>
                            <pre class="docs-code" data-lang="bash"><code>sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx</code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-server"></i> Nginx / Apache</h4>
                        <p>Nginx 默认限制请求体大小，需要单独放开；Apache 的 <code>.htaccess</code> 仅在 mod_php 环境下支持 <code>php_value</code>。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">Nginx server 块</div>
                            <pre class="docs-code" data-lang="nginx"><code>server {
    client_max_body_size 52m;
}</code></pre>
                        </div>
                        <div class="docs-code-block">
                            <div class="docs-code-title">Apache .htaccess（mod_php）</div>
                            <pre class="docs-code" data-lang="apache"><code>php_value upload_max_filesize 50M
php_value post_max_size 52M
php_value memory_limit 256M
php_value max_execution_time 120</code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-file-shield"></i> .user.ini 白名单</h4>
                        <p>PHP-FPM 常用，保存后台设置时也会写入站点根目录。公开系统信息文件用于服务器状态读取。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">.user.ini 示例（PHP-FPM 常用）</div>
                            <pre class="docs-code" data-lang="ini"><code>open_basedir=/path/to/LitePic/:/tmp/:/proc/cpuinfo:/proc/meminfo:/proc/uptime:/etc/os-release
upload_max_filesize=50M
post_max_size=52M
max_file_uploads=50
memory_limit=256M</code></pre>
                        </div>
                    </article>
                </div>
                <p class="docs-note"><strong>验证方式：</strong>进入 <strong>设置 → 服务器信息</strong> 查看“上传上限”；也可以执行 <code>php -i | grep -E "upload_max_filesize|post_max_size|memory_limit"</code>。如果页面仍显示“未生效”，通常是 PHP-FPM 未重启、Nginx <code>client_max_body_size</code> 未配置，或面板上层限制仍较低。</p>
            </section>

            <!-- 开启防盗链 -->
            <section class="docs-card" id="hotlink-protection">
                <h3>开启防盗链</h3>
                <p>LitePic 的防盗链分为两种：Apache / LiteSpeed 可由后台写入 <code>.htaccess</code>，保持 <code>/uploads/...</code> 原地址；Nginx、OpenResty、Caddy 也支持防盗链，但需要把对应规则放进 Web 服务器配置并重载服务。</p>
                <ol class="docs-steps">
                    <li>进入 <strong>设置 → 水印与防盗链</strong>，填写“防盗链允许域名”，多个域名用英文逗号分隔。</li>
                    <li>开启“启用防盗链（保持 /uploads/... 原路径）”，保存设置。</li>
                    <li>如果当前服务器是 Apache / LiteSpeed，后台会自动写入或移除 <code>.htaccess</code> 规则。</li>
                    <li>如果当前服务器是 Nginx / OpenResty / Caddy，请复制下面对应配置到站点配置里，然后重载 Web 服务。</li>
                </ol>

                <p class="docs-note"><strong>当前检测：</strong><?= htmlspecialchars($web_server_label) ?><?= $web_server_raw !== '' ? '（' . htmlspecialchars($web_server_raw) . '）' : '' ?>；当前白名单域名：<code><?= htmlspecialchars($hotlink_domain_text) ?></code>；无来源请求：<?= HOTLINK_ALLOW_EMPTY_REFERER ? '允许直接打开图片和隐私浏览器访问' : '不允许直接打开图片' ?>。</p>

                <div class="docs-tutorial-list">
                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-feather"></i> Apache / LiteSpeed</h4>
                        <p>后台保存设置时会自动写入站点根目录 <code>.htaccess</code>。如果需要手动配置，可复制下面的规则。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">.htaccess 防盗链规则</div>
                            <pre class="docs-code" data-lang="apache"><code><?= htmlspecialchars($apache_hotlink_snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-server"></i> Nginx / OpenResty</h4>
                        <p>OpenResty 使用 Nginx 配置语法。把规则放入当前站点的 <code>server</code> 块中，放在通用静态文件规则之前。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">Nginx / OpenResty location</div>
                            <pre class="docs-code" data-lang="nginx"><code><?= htmlspecialchars($nginx_hotlink_snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></pre>
                        </div>
                        <div class="docs-code-block">
                            <div class="docs-code-title">检查并重载</div>
                            <pre class="docs-code" data-lang="bash"><code>sudo nginx -t
sudo systemctl reload nginx</code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-shield-halved"></i> Caddy</h4>
                        <p>把规则放入当前站点块中。若设置允许空 Referer，则只拦截带有 Referer 且来源不在白名单内的请求；关闭后会连直接打开图片一起拦截。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">Caddyfile 防盗链规则</div>
                            <pre class="docs-code" data-lang="caddy"><code><?= htmlspecialchars($caddy_hotlink_snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></pre>
                        </div>
                        <div class="docs-code-block">
                            <div class="docs-code-title">检查并重载</div>
                            <pre class="docs-code" data-lang="bash"><code>sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy</code></pre>
                        </div>
                    </article>
                </div>

                <article class="docs-tutorial-card" id="hotlink-empty-referer">
                    <h4><i class="fa-light fa-circle-info"></i> 允许无来源请求是什么意思？</h4>
                    <p>图片防盗链主要依赖浏览器请求里的 <code>Referer</code> 判断来源。“无来源请求”就是请求里没有 <code>Referer</code>，常见于直接打开图片地址、复制 URL 到新标签页、隐私浏览器、浏览器插件隐藏来源，或聊天软件、App、代理/CDN 请求图片时没有传来源。</p>
                    <ol class="docs-steps">
                        <li>开启后：没有 <code>Referer</code> 的请求会放行，用户可以直接打开图片链接；防盗链主要拦截带有外站 <code>Referer</code> 的盗链请求。</li>
                        <li>关闭后：没有 <code>Referer</code> 的请求也会被拒绝，规则更严格，但直接打开图片链接、部分隐私浏览器或 App 访问图片可能失败。</li>
                        <li>建议默认开启。这样不影响正常分享图片链接，同时还能拦截大多数外站直接引用。</li>
                    </ol>
                </article>

                <p class="docs-note"><strong>说明：</strong>这种方式不改变图片地址，仍然使用 <code>/uploads/xxx.webp</code>。它依赖 Referer，适合拦截普通外站引用，但不能作为严格鉴权。若需要完全由 PHP 控制访问，可使用 <code>/i/...</code> 受控入口，但新复制链接会变更路径。</p>
            </section>

            <!-- 水印设置 -->
            <section class="docs-card">
                <h3>水印设置</h3>
                <p>文字水印默认使用白色文字，并在文字背后生成半透明磨砂底层。字体会优先使用服务器上的 Ubuntu 字体；没有 Ubuntu 字体时会回退到系统可用字体或 GD 内置字体。</p>
                <ol class="docs-steps">
                    <li>进入 <strong>设置 → 水印与防盗链</strong>，开启“上传后自动添加文字水印”。</li>
                    <li>保持“启用水印磨砂底层”开启，可调整磨砂层透明度、内边距和圆角。</li>
                    <li>需要自定义字体时，上传 <code>TTF</code> 或 <code>OTF</code> 字体文件；中文水印建议上传包含中文字形的字体。</li>
                    <li>需要图片水印时，上传透明背景 <code>PNG</code> 文件；配置 PNG 水印后会优先使用 PNG 图片水印。</li>
                </ol>
                <p class="docs-note"><strong>建议：</strong>PNG 图片水印请使用透明背景，并把最大宽度控制在原图宽度的 20% 到 30% 之间，避免遮挡图片主体。</p>
            </section>

            <!-- WebP 开启教程 -->
            <section class="docs-card">
                <h3>开启 WebP 转换</h3>
                <p>WebP 转换依赖 PHP GD 的 <code>imagewebp()</code>。压缩方式选择 ImageMagick 只影响压缩链路，不等同于 WebP 转换能力。</p>
                <ol class="docs-steps">
                    <li>进入 <strong>设置 → 基础设置</strong>，将“转换优先格式”设为 <strong>WebP</strong>。</li>
                    <li>勾选“上传后自动转换 WebP”，保存设置。</li>
                    <li>确认服务器安装并启用 <code>gd</code> 扩展，且 <code>imagewebp()</code> 可用。</li>
                    <li>上传一张 JPG/PNG/GIF 测试，图库中应生成或展示对应 WebP 文件。</li>
                </ol>

                <div class="docs-tutorial-list">
                    <article class="docs-tutorial-card">
                        <h4><i class="fa-brands fa-linux"></i> Debian / Ubuntu</h4>
                        <p>先安装 GD 与 WebP 工具包，再重启当前 PHP-FPM 版本。若压缩方式选择 ImageMagick，再额外安装 ImageMagick。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">安装常用依赖</div>
                            <pre class="docs-code" data-lang="bash"><code>sudo apt update
sudo apt install -y php-gd php-imagick webp imagemagick
sudo systemctl restart php8.3-fpm</code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-circle-check"></i> 检查 WebP 能力</h4>
                        <p>确认 PHP GD 扩展和转换函数都可用，上传后转换和批量转换才会正常工作。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">命令行验证</div>
                            <pre class="docs-code" data-lang="bash"><code>php -m | grep -Ei "gd|imagick"
php -r 'var_dump(function_exists("imagewebp"));'</code></pre>
                        </div>
                    </article>
                </div>

                <p class="docs-note"><strong>宝塔 / 面板环境：</strong>在 PHP 管理中安装或启用 <code>fileinfo</code>、<code>gd</code>。如果 GD 已启用但 <code>imagewebp()</code> 返回 false，说明该 PHP 的 GD 编译时没有 WebP 支持，需要换用带 WebP 的 PHP 版本。</p>
            </section>

            <!-- AVIF 开启教程 -->
            <section class="docs-card">
                <h3>开启 AVIF 转换</h3>
                <p>AVIF 对环境要求比 WebP 高。推荐 PHP 8.1+，并确认 GD 编译了 AVIF 支持（<code>imageavif()</code> 可用）。</p>
                <ol class="docs-steps">
                    <li>确认 PHP 版本 ≥ 8.1：<code>php -v</code>。</li>
                    <li>进入 <strong>设置 → 基础设置</strong>，将“转换优先格式”设为 <strong>AVIF</strong>。</li>
                    <li>勾选“上传后自动转换 AVIF”，保存设置。</li>
                    <li>在 <strong>设置 → 服务器信息</strong> 查看“AVIF 支持”是否为已启用。</li>
                    <li>上传 JPG/PNG/GIF 测试，处理报告中应显示 AVIF 转换成功。</li>
                </ol>

                <div class="docs-tutorial-list">
                    <article class="docs-tutorial-card">
                        <h4><i class="fa-brands fa-linux"></i> Debian / Ubuntu</h4>
                        <p>不同发行版的 PHP 包是否内置 AVIF 取决于编译参数。先安装依赖，再用命令验证。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">安装 AVIF 相关依赖</div>
                            <pre class="docs-code" data-lang="bash"><code>sudo apt update
sudo apt install -y php-gd php-imagick imagemagick libavif-bin libavif-dev
sudo systemctl restart php8.3-fpm</code></pre>
                        </div>
                    </article>

                    <article class="docs-tutorial-card">
                        <h4><i class="fa-light fa-vial"></i> 检查 AVIF 能力</h4>
                        <p>PHP 版本和 GD 的 AVIF 函数都需要确认；只装 ImageMagick 不代表当前转换链路可用。</p>
                        <div class="docs-code-block">
                            <div class="docs-code-title">命令行验证</div>
                            <pre class="docs-code" data-lang="bash"><code>php -v
php -r 'var_dump(function_exists("imageavif"));'
avifenc --version</code></pre>
                        </div>
                    </article>
                </div>

                <p class="docs-note"><strong>排障重点：</strong>PHP 版本满足要求不代表 AVIF 一定可用。若 <code>imageavif()</code> 不存在，说明 GD 没有 AVIF 支持。此时可升级 PHP/GD，或先使用 WebP 作为转换格式。</p>
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
                    <li>按远程用途同步到 R2/S3（如启用）：远程备份只保存副本，云端存储会让图片访问地址优先指向公网访问域名。</li>
                </ol>
                <p class="docs-note"><strong>注意：</strong>每一步的异常都会记录到处理报告中，可在响应的 <code>processing</code> 字段中查看详细状态，便于排查。</p>
            </section>

            <!-- 认证与安全 -->
            <section class="docs-card">
                <h3>认证与安全</h3>
                <div class="docs-grid docs-grid-2">
                    <article class="docs-item">
                        <h4><i class="fa-light fa-key"></i> 管理员 Key 登录</h4>
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

            <!-- 外部接口入口 -->
            <section class="docs-card">
                <h3>接口与外部接入</h3>
                <p>上传接口、导出接口、后台图片操作接口和 WordPress 插件对接说明已移动到 <a href="/api"><code>/api</code> API 文档</a>。</p>
            </section>

            <!-- 运维与排障 -->
            <section class="docs-card">
                <h3>运维与排障</h3>
                <ul class="docs-list">
                    <li>如出现"未压缩 / 未转 WebP / 未转 AVIF"，可在开启调试后查看 <code>logs/YYYY-MM-DD.log</code> 的 upload post-process 记录。</li>
                    <li>旧数据迁移后，可使用设置中的扫描功能重建图库数据、缩略图与统计数据。</li>
                    <li>当对象存储配置不完整时，系统会回退为本地可用模式，上传不会被中断。</li>
                    <li>若 AVIF 转换失败，请确认 PHP 版本 ≥ 8.1，且 GD 已编译 AVIF 支持（<code>php -r 'var_dump(function_exists("imageavif"));'</code>）。</li>
                </ul>
            </section>

            <aside class="docs-issue-box">
                <i class="fa-brands fa-github" aria-hidden="true"></i>
                <div>
                    <strong>遇到问题？</strong>
                    <p>如果使用中发现 Bug、部署异常或有功能建议，可以到 GitHub Issues 提交问题。</p>
                </div>
                <a href="https://github.com/gentpan/LitePic/issues" target="_blank" rel="noopener noreferrer">提交问题</a>
            </aside>
        </div>
    </section>
</main>

<?php require_once APP_ROOT . '/footer.php'; ?>

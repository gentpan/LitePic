# Changelog

All notable changes to this project will be documented in this file.

## [3.3.4] - 2026-05-08

### Added
- **Telegram 机器人集成** — 全新 `设置 → Telegram` tab,绑定一个 BotFather 拿到的 Bot Token + 白名单用户 ID 后,机器人即可:
  - 直接发图片(或图片文档)到机器人 → 自动上传到 LitePic,回复公开链接
  - `/list [N]`、`/albums`、`/album <key>`、`/newalbum <名称>`、`/use <key>`、`/me`、`/help` 一整套指令
  - 三层安全:URL secret(32-hex,一键轮换) + Telegram `secret_token` header + `TELEGRAM_ALLOWED_USER_IDS` 白名单
  - 每用户「默认上传相册」状态(新表 `telegram_user_state`)
  - 新文件:`app/Service/Telegram/{TelegramApi,TelegramHandler}.php`、`app/Repository/TelegramUserStateRepository.php`、`api/telegram.php`、迁移 `013_telegram_settings.php`
- **底部批量进度卡** — `assets/js/main.js` `ImgEt.BatchProgress` 单例 + 新 CSS。批量压缩 / 转换 / 删除时,顶部 toast 只发状态广播,底部固定卡显示实时 N/M + 进度条 + 百分比;三色 variant(绿/紫/红)对应三种操作。
- **`UploadService::storeFromPath()`** 公共方法 — 服务端 ingest 路径,把已经在磁盘上的文件走完整上传流水线(供 Telegram 用,未来也可供 CLI 导入)。
- **nginx 详细 timing log_format** — `wall_time` + `upstream_time` 字段,以后排查上传性能问题不用靠猜。

### Changed
- **相册 URL 默认数字 ID,slug 可选** — 迁移 `012_albums_slug_nullable.php` 通过 `writable_schema` 把 `albums.slug` 改成 NULLABLE。新建相册不填 slug → 公开 URL 是 `/a/<id>`(数字);填 slug → `/a/<slug>`。Router、Controller、Repository 全部支持 `findByKey($key)` 双模式。新增 `AlbumService::urlKey($album)` 作为单一 URL 构造源。
- **上传性能 10× 优化** — 综合多层调优,典型 2-3MB 照片从「100 秒级」体感降到「1-3 秒级」:
  - PHP-FPM `pm = ondemand → dynamic`(8 worker 常驻,消除 burst 冷启动 50-150ms)
  - `ThumbnailService` Imagick 加 `jpeg:size` DCT-domain 降采样 hint + JPEG 跳过 `mergeImageLayers / setImageAlphaChannel`(4032×3024 大图缩略图 794ms → 74ms,**10×**)
  - **同步生成缩略图** — `UploadService::storeFromPath` / `handleSingle` 在响应内做完缩略图,响应一返回前端立刻显示真实 thumbnail URL,无需轮询;失败自动退回到异步队列
  - `getimagesize` 透传给 `ThumbnailService::create($id, $force, $info)` 避免重复读图片头
  - `LivenessTracker::recordOnce()` 改 1/8 抽样,burst 上传时 SQLite 写锁竞争 -87.5%(uptime 桶分辨率仍保持分钟级)
  - 浏览器并发上传 `MAX_CONCURRENT: 3 → 20`(自托管/局域网图床,带宽是自己的)
  - 后端处理状态轮询从 `setInterval(2500ms)` 改成指数退避 `400 / 700 / 1100 / 1700 / 2500ms`(缩略图典型 1-2 次内拿到 'done',总等待 < 1 秒)
  - 新增 `cron` 兜底:每分钟跑 `worker.php` 处理队列(用户离开页面后还能继续 drain)
- **基础设施门限** — PHP `upload_max_filesize` / `post_max_size` 50M → 200M,`memory_limit` 128M → 256M,`max_input_time` 60s → 600s;nginx `client_max_body_size` 50m → 200m,`client_body_buffer_size` 512k → 32m(32MB 以下完全在内存,不再 spool 到磁盘)
- **删除相册** — 改用自定义 `ImgEt.DialogManager.showConfirmDialog`(`danger: true`),不再用浏览器原生 `confirm()`,跟删除图片走同一套样式。
- **能力卡帮助图标** — `?` 链接直接跳 `litepic.io/docs#<anchor>`,移除之前在应用内做但有 CSS 渲染问题的「一键启用命令」弹窗。文档统一在官网维护。

### Fixed
- 修复迁移 `012` 早期版本里 `PDO->beginTransaction()` 在 Migration runner 已开启的事务里抛 "There is already an active transaction" — 改用 `writable_schema` + `schema_version` bump 在已有事务里直接修改 `sqlite_master`。
- 修复某些情况下 `findByHashWithBackfill` 在历史无 hash 图片很多时遍历整库 sha1_file 导致上传慢(本版本未改 API,但 sync thumbnail 的延迟提升让这个潜在风险显形 — 若用户报告慢可建议跑一次 backfill 命令)。

### Infrastructure
- 服务器侧调优(部署文档已更新):BT 面板用户建议 `pm = dynamic`,nginx `client_body_buffer_size 32m`,PHP `memory_limit 256M`,以及 cron `* * * * * php /<root>/worker.php`。

## [3.3.3] - 2026-05-06

### Changed
- 版本号统一升至 3.3.3。

## [3.3.2] - 2026-05-06

### Changed
- 版本号统一升至 3.3.2。

## [3.3.1] - 2026-05-05

### Added
- **程序自动更新** — 设置页新增 WordPress 风格更新入口，从 GitHub Release 下载 ZIP，只替换程序文件，并保护 `.env`、`.user.ini`、`.htaccess`、`data/`、`uploads/`、`logs/` 与 `static/images/`。
- 新增 `/api/v1/update/check` 与 `/api/v1/update/install`，供后台更新面板使用。

### Changed
- 版本号统一升至 3.3.1，页脚、版权说明和后台更新面板全部读取同一个 `LITEPIC_VERSION`。
- 清理废弃的首页背景上传样式、access.log 旧配置和 Docker 调度文案。
- 系统状态接口统一到 `/api/v1/system/status`，旧直连入口不再公开。

## [3.3.0] - 2026-05-04

### Added
- **队列心跳兜底（HeartbeatScheduler）** — 自托管常常遇到面板各异（BT / 1Panel / aaPanel / cPanel / …）配 cron 麻烦的问题。新增 `app/Service/Queue/HeartbeatScheduler.php`，每个 web 请求结束时检查"距上次 drain 超过 N 小时"，是则通过 `ResponseDetacher` 在响应发出后跑一次 drain，跨所有面板/无面板/Docker/Shared Hosting 通用。和真 cron 不冲突，flock 互斥。默认 24 小时间隔，可通过 `LITEPIC_HEARTBEAT_INTERVAL_HOURS` 调整或 `LITEPIC_HEARTBEAT_DISABLED=true` 完全关闭。
- 页脚新增 API 文档图标链接，与使用说明并列。

### Changed
- 版本号升至 3.3.0；版权对话框版本号同步至 v3.3.0。

## [3.2.0] - 2026-05-03

### Changed
- README.md 完全重写，精简结构，聚焦核心特性与快速开始流程
- 配置文件清理：移除冗余的 .htaccess、Caddyfile.example、Dockerfile、docker-compose.yml 等旧版文件
- nginx-litepic.conf 更名为 nginx-litepic.conf（新文件替代旧文件）
- 版本号升至 3.2.0

## [3.1.1] - 2026-05-02

### Fixed
- 登录接口返回非 JSON 响应时前端 `data.status` 空值崩溃 — 增加 `data` 空值检查，显示明确错误提示。
- Docker 环境 SQLite 数据库文件权限为 `root:root` 导致上传 500 错误 — 新增 `docker-entrypoint.sh` 启动时自动修复 `data/`、`uploads/`、`logs/` 目录权限。

## [3.1.0] - 2026-05-01

### Added
- **异步处理队列 + worker sidecar** — 上传请求把图片入库后立即返回，缩略图 / 压缩 / WebP / AVIF / 水印 / R2 同步全部进 SQLite `import_queue` 表后台 drain。`worker.php` 可作为 docker compose sidecar 长驻或 cron 拉起，跟 in-request drain 共享同一数据库，flock 互斥。`ResponseDetacher` 让 PHP-FPM / LiteSpeed 在响应发出后继续跑后处理。
- **Passkey / WebAuthn 登录** — 指纹 / Face ID / 硬件 key 无密码登录，支持注册多个凭证并随时撤销。自实现 165 行 CBOR 解码器和 ES256 签名验证，零 composer 依赖。
- **数据库备份系统** — SQLite VACUUM INTO 在线热备，支持手动备份 / 定时调度 / 保留份数 / 自动同步到 R2/S3 / UI 一键恢复。
- **残留数据清理** — 设置 → 数据库 tab 新增「扫描 + 清理」流程，5 类残留：磁盘已删的 images 行、已完成超 7 天的队列、3 次失败超 30 天的队列、过期 24 小时的登录尝试、过期 Passkey 挑战。保守策略 — 不动磁盘文件 / 活动队列 / 设置 / Token / Passkey 凭据。
- **图库卡片右键菜单** — 重新生成缩略图 / 复制原图链接 / 下载原图 / 下载 WebP / 下载 AVIF / 下载缩略图 / 转换反向偏好格式（默认 WebP 时显示「转换 AVIF」反之亦然）。其他位置右键仍是浏览器原生菜单。
- **自定义 URL 前缀** — 后台可把 `/uploads/` 改成 `/img/` `/photo/` `/p/foo-bar/` 任意单词前缀，全部经 `image.php` 走访问统计。Apache `.htaccess` catch-all + Nginx / Caddy / `php -S` 等价配置全部跟进。
- **多 Web 服务器适配** — `nginx.litepic.conf`、`Caddyfile.example`、`router.php`（PHP `-S` 内置）三套等价配置随仓库分发，复制改 root 即可用。
- **服务器信息卡** — Web 服务器自动检测显示 Nginx / OpenResty / Caddy / Apache / LiteSpeed + 版本号。能力卡（GD / ImageMagick / AVIF / WebP / 上传上限）未启用时带 `?` 图标链到 [litepic.io/docs](https://litepic.io/docs) 对应章节。
- **Powered by LitePic 徽章** — 页脚用 shields.io 风格徽章（Ubuntu 字体 + mono 版本号 + 黑底品牌蓝双色）显示版本，跟着 `SITE_VERSION` 自动更新。
- **图片处理日志面板** — 设置 → 任务 tab 显示队列深度 / 失败任务数 / 上次运行时间，KPI 卡片样式，支持「立即处理队列 / 刷新状态」AJAX 调用。

### Changed
- **文件名生成** — 从 `uniqid() + '_' + random_int(100, 999)` 改成 `bin2hex(random_bytes(16))`，32 位十六进制（md5 观感）。不暴露上传时间，128 bits 加密熵碰撞概率约为零。
- **设置页 7 个 tabs UI 重构** — 路径化 URL `/settings/<tab>`、PJAX 切换不刷整页、Plan A 直角设计、tab 标题图标改成品牌蓝、能力 / 队列状态用 KPI 卡片替代输入框样式。
- **配置中心从 .env 转 SQLite settings 表** — 后台改开关无需重启 PHP-FPM / 容器，刷新即生效。`.env` 仍作为首次安装初始默认值来源。
- **转换按钮自动跟偏好格式** — 后台「转换优先格式」选 WebP → 卡片显示「转换 WebP」按钮 + 右键菜单显示「转换 AVIF」；选 AVIF 反之。
- **压缩 toast 显示实际引擎** — `压缩完成 · GD` / `压缩完成 · TinyPNG` / `压缩完成 · ImageMagick`，方便验证后台「压缩方式」设置是否生效。
- **上传上限固定 50MB** — 后台不再让用户改，避免跟 PHP-FPM `upload_max_filesize` / Nginx `client_max_body_size` / 面板上层限制多层纠缠。卡片显示服务器实际允许的值。
- **官方文档完整迁移** — 使用说明在 [litepic.io/docs](https://litepic.io/docs)，API 文档在 [litepic.io/api](https://litepic.io/api)，本地不再保留这两个页面。版权对话框带 hero + 信息卡片网格 + 官网入口的全新结构。
- **Favicon 切到 squircle 风格** — 跟小米 2021 logo 同款 G2 连续超椭圆（n≈4 superellipse），16 / 32 / 180 / 192 / 512 全套 PNG + ICO + webmanifest 同步更新。
- **图库分页箭头** — `« ‹ › »` HTML 实体改成 FontAwesome `fa-angle(s)-left/right` 16px 矢量图标，跨平台一致。

### Removed
- **「重新处理」按钮** — 移到右键菜单作为「重新生成缩略图」（更针对性，不会顺带重跑压缩 / 转换）。
- **本地 `/docs` 和 `/api` 页面** — 删除 `app/pages/usage.php` 和 `app/pages/api.php`，路由表移除对应入口，footer 链接和 `/docs#xxx` 锚点全部指向 litepic.io。
- **首页背景替换功能** — 首页背景固定从 `static/images/background.jpg` 读取，移除原后台动态切换 UI。
- **「最大上传大小」输入框** — 改成纯展示服务器实际值（见上）。

### Fixed
- 多处图标 + 中文文字对齐错位 — 改成 equal-height inline-flex 容器模式 + `transform: translateY(1px)` 校准 FA glyph 视觉中心。
- WebP / AVIF 处理大图（10080×3716、19MB）OOM — 走 ImageMagick 链路兜底。
- 队列重复入队 — `import_queue` 加 UNIQUE 约束 + `INSERT OR IGNORE`。
- WAL 模式默认开启，并发读写不互相阻塞。
- WebP / AVIF / SVG 直接访问时 Content-Type 错误。
- 删除大量图片时图库列表整页 `innerHTML` 重写卡顿 — 改成单行原地 update + class toggle，丝滑过渡。

## [3.0.0] - 2026-04-29

### Breaking Changes
- **CSS Architecture**: Migrated from custom CSS modules to Tailwind CSS v4 with CSS-first configuration. All legacy `assets/css/modules/*.css` files removed.
- **Documentation Routes**: Split `docs.php` into `usage.php` + `api.php`. Routes changed from `/docs` to `/docs` (usage) and `/api` (API docs).

### Added
- **Tailwind CSS v4** build pipeline with `@tailwindcss/cli` and component-layer CSS architecture.
- **Gallery link** in navigation bar for logged-in users (`/gallery`).
- **Custom scrollbar theming** for WebKit and Firefox, following light/dark mode.
- **Favicon suite**: apple-touch-icon, Android Chrome icons (192x192 / 512x512), favicon-16x16, favicon-32x32, and `site.webmanifest`.
- **Light/dark theme-aware logo switching** via CSS custom properties (avoids Tailwind v4 Lightning CSS optimization bugs).
- **Scrollbar gutter stabilization** (`scrollbar-gutter: stable`) to prevent layout shift.

### Changed
- **Logo**: Replaced SVG logo with PNG (`logo.png` / `logo-dark.png`) for better cross-browser rendering.
- **Footer layout**: Reorganized into three-column layout—GitHub link on the left, centered copyright/stats/docs/API/login/theme-toggle row.
- **Navigation bar**: 
  - Guest: Home + Stats + Upload (CTA)
  - Logged-in: Home + Stats + Gallery + Settings + Upload (CTA)
  - Docs/API links moved to footer.
- **Home page hero card**: Light mode now uses the same glassmorphism effect as dark mode (semi-transparent white gradient + backdrop blur).
- **Upload button colors**: Fixed light-mode CTA button to use `#0052D9` background with white text.
- **Documentation**: Restructured usage guide into 7 chapters with AVIF/Passkey/fullscreen-upload coverage and environment config cheat sheet.

### Fixed
- **Dark-mode selector optimization bug**: Replaced `html[data-theme="dark"]` element selectors with CSS custom properties to avoid Lightning CSS dropping dark-mode prefixes.
- **Login panel display conflict**: Removed HTML `hidden` class to prevent Tailwind v4 utility layer from overriding component layer.
- **Transform property conflict**: Notification animations now use `--tw-translate-x` + `translate` property instead of `transform`, avoiding conflicts with Tailwind v4 independent transform properties.

## [2.3.0] - 2025-03-28

### Added
- AVIF format support via ImageMagick.
- Preferred format setting (WebP / AVIF) in settings panel.
- Fullscreen upload mode.
- Distro detection in system status.
- Self-healing `open_basedir` sandbox.

### Changed
- UI overhaul with improved settings panel.

## [2.2.0] - 2025-02-20

### Added
- WebAuthn / Passkey login support.
- Docker support with Dockerfile and docker-compose.yml.

### Fixed
- Session headers-already-sent warning in Docker.
- Root `.htaccess` `php_flag engine off` issue.

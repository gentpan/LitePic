<div align="center">

<img src="static/logo.png#gh-light-mode-only" alt="LitePic" height="80">
<img src="static/logo-dark.png#gh-dark-mode-only" alt="LitePic" height="80">

# LitePic

**轻量级自托管图床 · SQLite · 异步处理 · 可选多用户 · S3 / R2 / WebDAV**

[![Version](https://img.shields.io/badge/version-3.7.0-0052D9?style=flat-square)](https://github.com/gentpan/LitePic/releases)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-111827?style=flat-square)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-litepic.io-0052D9?style=flat-square)](https://litepic.io/docs)

[官网](https://litepic.io) · [使用说明](https://litepic.io/docs) · [API 文档](https://litepic.io/api) · [更新日志](CHANGELOG.md)

</div>

---

LitePic 是一个 PHP 写的图床：一台 VPS、一份 SQLite，用 FrankenPHP 一行命令就能跑起来。默认仍是**单管理员**；需要时再打开多用户即可。

核心设计是**先入库，再处理**——上传只负责把原图安全落下，缩略图、压缩、WebP/AVIF、水印、远程同步全部进队列异步执行。某个环节挂了，原图和数据也不会丢。

## 特性

### 上传与处理
- 拖拽 / 点击 / 粘贴上传；前端批量队列，失败可单独重试
- 支持 JPG、PNG、GIF、WebP、AVIF、HEIC、SVG、ICO、BMP、TIFF
- 磁盘文件名自动随机化（原名仅作展示）；图库可双击改展示名
- 异步缩略图、压缩、WebP / AVIF 转换、文字或 PNG 水印
- 压缩引擎：TinyPNG / ImageMagick / GD
- Worker：响应后 drain / Cron / 手动触发，同一 SQLite，flock 互斥

### 存储与分发
- S3 兼容：Cloudflare R2、AWS S3、MinIO、Backblaze B2
- WebDAV **客户端**（设置 → 存储）：同步到坚果云 / Nextcloud / 群晖等
- **不是** WebDAV 服务端（无 `/dav`、无 `/files`）
- 模式：**远程备份**（本地为主）或 **云端存储**（链接直出远端；需公网访问域名）
- 同时只启用一种远程后端（S3 或 WebDAV）
- 本地删除后延迟清理远程对象；URL 前缀可自定义（如 `/img/`）

### 多用户（可选，默认关闭）
- **关闭**：与以往一致，仅管理员密码 / Passkey
- **邀请制 / 开放注册**：普通用户独立图库、相册与配额
- 管理员仍用原有 `ADMIN_API_KEY` / Passkey；站长账号为 `users` 表 id=1
- 可选 Google / GitHub OAuth、SMTP 邀请邮件
- 开启路径：设置 → 基础 → 多用户模式 → 保存后配置「用户 / 邀请码 / 注册与登录 / 邮件」

### 安全与后台
- Passkey / WebAuthn；API Key + Bearer Token；登录限速、CSRF、MIME 校验
- Referer 防盗链；上传目录禁止执行 PHP
- `/settings/<tab>` 路径化 + PJAX；队列 KPI、SQLite 热备份、残留清理、在线更新
- 服务器能力卡（PHP / FrankenPHP / GD / Imagick / WebP / AVIF / 上传上限）

### 相册与 Telegram
- 公开 / 未列出 / 私密 / 密码相册；宫格或瀑布流公开页
- 可选 Telegram Bot：白名单发图入库、相册归类

## 环境要求

| 项目 | 最低 | 推荐 |
|------|------|------|
| PHP | 8.0+（FrankenPHP 内嵌通常 ≥8.2） | 8.2+（OPCache） |
| 扩展 | fileinfo · pdo_sqlite · gd 或 imagick | imagick + libwebp + libheif |
| Web 服务器 | FrankenPHP（内置 Caddy） | 经典模式（不要开 worker mode） |
| HTTPS | 生产必需 | Passkey / OAuth 强依赖 |

> 也可用 Nginx + PHP-FPM（见 `nginx-litepic.conf`）。宝塔等面板**必须**配置伪静态，见下方「Nginx / 宝塔」。

## 快速开始

```bash
git clone https://github.com/gentpan/LitePic.git
cd LitePic
cp .env.example .env
# Nginx + PHP-FPM 可选：cp .user.ini.example .user.ini
mkdir -p uploads data logs
# 属主 = 跑 PHP 的用户（FrankenPHP 官方包多为 frankenphp）
chown -R "$(id -u):$(id -g)" uploads data logs 2>/dev/null || true
# chown -R frankenphp:frankenphp uploads data logs
chmod -R u+rwX,g+rwX uploads data logs
```

编辑 `.env`（至少改这两项）：

```ini
SITE_NAME="LitePic"
SITE_URL="https://img.example.com"
# ADMIN_API_KEY 默认 12345678，登录后到「设置 → 账号」改掉
```

启动：

```bash
# 生产（自动 HTTPS）
SERVER_NAME=img.example.com frankenphp run --config ./Caddyfile

# 本地试跑 → http://127.0.0.1:8080
frankenphp run --config ./Caddyfile
```

浏览器打开站点，默认密码 `12345678` 登录后立刻改密。大批量处理建议加 cron：

```bash
* * * * * php /path/to/LitePic/worker.php
```

### Nginx / 宝塔

伪静态要把未命中文件的请求交给 `index.php`，否则 `/gallery`、`/settings`、`/register` 会 404：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

宝塔：网站 → 设置 → 配置文件 / 伪静态，加入上面一段后重载 Nginx。完整示例见仓库 `nginx-litepic.conf`。  
仓库还带了 `/gallery/`、`/upload/` 等目录 shim 作兜底；`/settings/<tab>`、`/gallery/page/N` 等子路径仍依赖 `try_files`。

### 开启多用户（可选）

1. 管理员登录 → 设置 → **基础** → 多用户模式选「邀请制」或「开放注册」→ 保存  
2. 按需配置：用户、邀请码、OAuth、SMTP  
3. 访客打开 `/register`（邀请制需邀请码或邀请链接）

关闭多用户后，已有账号与数据保留，仅停用注册/普通用户登录入口；管理员照常使用。

## 前端构建

```bash
npm install
npm run build:css       # CSS（含 minify）
npm run watch:css       # CSS 监听
npm run build:js        # assets/js/main.min.js
npm run build           # CSS + JS
```

`bin/deploy.sh` 默认会重建 CSS；改 JS 后请本地 `npm run build:js` 再部署。

## API

统一入口 `/api/v1`。鉴权任选：

```
X-API-Key: your-api-key
Authorization: Bearer your-api-key
```

管理员主密钥、账号页创建的 Token，以及多用户模式下用户自己的 Token 均可用于上传（Token 归属对应用户）。

```bash
# 上传
curl -X POST https://img.example.com/api/v1 \
  -H "X-API-Key: $KEY" \
  -F "image=@photo.jpg"

# 导出列表
curl "https://img.example.com/api/v1/export?page=1&per_page=100" \
  -H "X-API-Key: $KEY"

# 触发队列
curl -X POST https://img.example.com/api/v1/queue/drain \
  -H "X-API-Key: $KEY"
```

详见 [API 文档](https://litepic.io/api)。

## 目录结构

```
LitePic/
├── api/                  # /api/v1 与 oauth 等
├── app/
│   ├── Core/
│   ├── Http/             # 路由、控制器
│   ├── Migrations/       # 含 019_multiuser
│   ├── Repository/       # 含 User / Invite / OAuth
│   ├── Service/          # Auth（含多用户）、Image、Storage、Mail…
│   ├── View/
│   └── pages/            # 含 register.php
├── assets/               # CSS / JS 源码与编译产物
├── bin/deploy.sh
├── gallery/ upload/ …    # 宝塔兼容 shim（index.php）
├── data/                 # SQLite（勿提交）
├── static/
├── uploads/
├── Caddyfile             # 推荐
├── nginx-litepic.conf    # Nginx / 宝塔参考
├── bootstrap.php
├── index.php
├── worker.php
├── .env.example
└── .user.ini.example
```

## 升级与备份

备份这些即可：

```
.env
.user.ini          # 仅 PHP-FPM
data/
uploads/
logs/
static/images/
Caddyfile          # 若已定制
```

推荐后台：设置 → 系统 → 程序更新。在线更新读 `litepic.io/version.json`（失败回退 GitHub Release），只同步程序文件，跳过 `.env` / `data/` / `uploads/` / `logs/` / `static/images/`。定制过的 `Caddyfile` 更新前请先备份。

| 从版本 | 注意 |
|--------|------|
| 3.6.0 / 3.6.1 | WebDAV **服务端**已移除；改用设置 → 存储的 WebDAV **客户端**（迁移 `018`） |
| ≤ 3.6.x → 3.7 | 迁移 `019_multiuser` 自动执行；默认仍单用户，需手动开多用户 |

手动升级：

```bash
tar czf backup-$(date +%F).tar.gz .env .user.ini data/ uploads/ logs/ static/images/ Caddyfile
# 解压新版覆盖程序文件，勿覆盖上面备份项
# 打开后台，迁移自动跑完
```

## 文档

- 安装与部署：[litepic.io/docs](https://litepic.io/docs)
- API：[litepic.io/api](https://litepic.io/api)
- 更新日志：[CHANGELOG.md](CHANGELOG.md)
- Issues：[GitHub Issues](https://github.com/gentpan/LitePic/issues)

## 协议

[MIT License](LICENSE)。可自由分发与商用，请保留版权声明。

<div align="center">

<img src="static/logo.png#gh-light-mode-only" alt="LitePic" height="80">
<img src="static/logo-dark.png#gh-dark-mode-only" alt="LitePic" height="80">

# LitePic

**轻量级自托管图床 · SQLite 单文件 · 异步处理 · S3 / R2 远程存储**

[![Version](https://img.shields.io/badge/version-3.4.7-0052D9?style=flat-square)](https://github.com/gentpan/LitePic/releases)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-111827?style=flat-square)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-litepic.io-0052D9?style=flat-square)](https://litepic.io/docs)

[官网](https://litepic.io) · [使用说明](https://litepic.io/docs) · [API 文档](https://litepic.io/api) · [更新日志](CHANGELOG.md)

</div>

---

LitePic 是一个 PHP 写的图床。一台 VPS、一份 SQLite 文件，用 FrankenPHP 一行命令就能跑起来。

它的核心设计是**先入库，再处理**——上传只负责把原图安全保存下来，缩略图、压缩、WebP/AVIF 转换、水印、远程同步全部进队列异步执行。即使某个环节挂了，原图和数据都不会丢。

## 特性

### 上传与处理
- 拖拽 / 点击 / 粘贴上传，批量队列、断点续传友好
- 支持 JPG、PNG、GIF、WebP、AVIF、HEIC、SVG、ICO、BMP、TIFF
- 异步生成缩略图、压缩、WebP / AVIF 转换、文字或 PNG 水印
- 三种压缩引擎可切换：TinyPNG、ImageMagick、GD
- Worker 三种触发方式（轻量响应后 drain / Cron 常驻消费 / 手动触发），同一份 SQLite，flock 互斥

### 存储与分发
- S3 兼容协议：Cloudflare R2、AWS S3、MinIO、Backblaze B2
- 两种模式：**远程备份**（本地为主，云端冷备份）或 **云端存储**（图片直出对象存储公网地址）
- 本地删除自动延迟清理远程对象，避免误删
- URL 前缀可自定义，把 `/uploads/` 改成 `/img/` `/photo/` `/p/foo/` 任意短词

### 安全
- Passkey / WebAuthn 无密码登录（指纹 / Face ID / 硬件 Key），支持注册多个凭证
- API Key + Bearer Token 双方案，登录限速、CSRF Token、MIME 校验
- Referer 防盗链，支持白名单
- 上传目录禁止执行 PHP，敏感路径访问保护

### 后台
- 路径化 URL `/settings/<tab>`，PJAX 切换不刷整页
- 服务器能力卡（PHP / Web 服务器版本 / GD / Imagick / WebP / AVIF / 上传上限）一目了然，可识别 FrankenPHP
- 队列状态 KPI 卡：深度、失败任务数、上次运行时间，一键 drain
- 数据库一键热备份（VACUUM INTO）+ 自动调度 + 同步到 R2 + 一键恢复
- 残留数据扫描清理，5 类候选保守删除，永远不动磁盘文件和活动队列

### 相册与 Telegram
- 公开 / 未列出 / 私密 / 密码相册，封面图与沉浸式公开浏览页
- 可选 Telegram Bot：白名单用户发图自动入库，支持相册归类

## 环境要求

| 项目 | 最低 | 推荐 |
|------|------|------|
| PHP | 8.0+（FrankenPHP 内嵌通常 ≥8.2） | 8.2+（启用 OPCache） |
| 扩展 | fileinfo · pdo_sqlite · gd 或 imagick | imagick + libwebp + libheif |
| Web 服务器 | FrankenPHP（内置 Caddy） | FrankenPHP 经典模式（不要开 worker mode） |
| HTTPS | 生产必需 | 必需（Passkey 强依赖；Caddy 可自动签发） |

> 仍可使用 Nginx + PHP-FPM，仓库保留 `nginx-litepic.conf` 作对照；推荐部署以根目录 `Caddyfile` 为准。

## 快速开始

```bash
git clone https://github.com/gentpan/LitePic.git
cd LitePic
cp .env.example .env
# Nginx + PHP-FPM 可选：cp .user.ini.example .user.ini（按机器改 open_basedir）
mkdir -p uploads data logs
# 属主必须是「跑 PHP 的那个用户」，否则上传会 Permission denied：
#   - systemd 安装的 FrankenPHP → frankenphp
#   - Nginx + PHP-FPM → 多为 www-data / nginx / www
# 若用当前 shell 用户直接 `frankenphp run`，下面这行可省略。
chown -R "$(id -u):$(id -g)" uploads data logs 2>/dev/null || true
# 官方包以 frankenphp 用户跑时：
#   chown -R frankenphp:frankenphp uploads data logs
chmod -R u+rwX,g+rwX uploads data logs
```

编辑 `.env`（默认值即可起步，至少改这两项）：

```ini
SITE_NAME="LitePic"
SITE_URL="https://img.example.com"
# ADMIN_API_KEY 默认 12345678，登录后在「设置 → 账号」改成强随机密码
```

> 公网暴露前务必改掉默认密码。后台首次用 `12345678` 登录，会有横幅提示修改。

启动：

```bash
# 推荐：FrankenPHP（经典模式）。生产可设 SERVER_NAME 开自动 HTTPS
SERVER_NAME=img.example.com frankenphp run --config ./Caddyfile

# 本地试跑（无 TLS）：默认监听 :8080 → http://127.0.0.1:8080
frankenphp run --config ./Caddyfile

# 可选：Nginx + PHP-FPM，参考 nginx-litepic.conf
# 可选 Docker：dunglas/frankenphp，挂载本目录并设 SERVER_NAME
```

打开浏览器访问站点后，用默认密码登录，进入设置改密并配置站点即可开始上传。

大批量处理建议再加一行 cron（与网页内 drain / 心跳不冲突，flock 互斥）：

```bash
* * * * * php /path/to/LitePic/worker.php
```

## 前端构建

样式用 Tailwind CSS v4 编译；JS 用 esbuild 压缩。改 CSS / JS 后：

```bash
npm install
npm run build:css       # 一次性构建 CSS（已 minify）
npm run watch:css       # CSS 开发监听
npm run build:css:dev   # CSS 未压缩，调试用
npm run build:js        # 生成 assets/js/main.min.js
npm run build           # CSS + JS 一起构建
```

`bin/deploy.sh` 部署时默认会重建 CSS；JS 变更后请本地执行 `npm run build:js` 再推。

## API

所有新接口统一走 `/api/v1`。请求头任选一种：

```
X-API-Key: your-api-key
Authorization: Bearer your-api-key
```

**上传：**

```bash
curl -X POST https://img.example.com/api/v1 \
  -H "X-API-Key: $KEY" \
  -F "image=@photo.jpg"
```

**导出图片列表：**

```bash
curl "https://img.example.com/api/v1/export?page=1&per_page=100" \
  -H "X-API-Key: $KEY"
```

**手动触发队列：**

```bash
curl -X POST https://img.example.com/api/v1/queue/drain \
  -H "X-API-Key: $KEY"
```

详细参数与响应字段见 [API 文档](https://litepic.io/api)。

## 目录结构

```
LitePic/
├── api/                  # /api/v1 各业务入口（由 v1.php 分发）
├── app/
│   ├── Core/             # 数据库、日志、CSRF、响应、配置
│   ├── Http/             # 控制器、页面路由
│   ├── Migrations/       # SQLite 迁移（按序号执行）
│   ├── Repository/       # 数据访问层
│   ├── Service/          # 业务服务（Image / Storage / Queue / Auth）
│   ├── View/             # 可复用视图片段
│   └── pages/            # 页面模板
├── assets/
│   ├── css/              # Tailwind 源码、components/、编译产物
│   └── js/               # 前端脚本（main.js → main.min.js）
├── bin/deploy.sh         # 一键部署（环境变量配置目标机）
├── data/                 # SQLite + 运行时状态（勿提交）
├── static/               # logo、favicon、首页背景
├── uploads/              # 本地图片存储（含 .thumbs/）
├── Caddyfile             # FrankenPHP / Caddy（推荐）
├── nginx-litepic.conf    # Nginx + PHP-FPM（可选）
├── bootstrap.php         # 引导入口
├── index.php             # 前端控制器
├── image.php             # /i/… 与自定义前缀出图
├── action.php            # 管理操作（压缩 / 转换 / 删除等）
├── worker.php            # 异步队列 worker
├── config.php            # 配置常量
├── .env.example          # 环境变量模板
└── .user.ini.example     # PHP-FPM 目录 ini 模板（勿提交真实 .user.ini）
```

## 升级与备份

升级时备份这几样就够：

```
.env
.user.ini          # Nginx + PHP-FPM；由 .user.ini.example 复制而来。FrankenPHP 改 Caddyfile php_ini
data/
uploads/
logs/
static/images/     # 首页背景、水印图片等自定义资源
Caddyfile          # 若已按站点改过域名 / root / php_ini，请一并备份
```

`data/` 包含图库索引、任务队列、Token、Passkey 注册数据等运行状态。即使启用了远程存储仍建议本地保留 `data/`。

推荐使用后台一键更新：

```
设置 → 系统 → 程序更新 → 检查更新 → 立即更新
```

在线更新会从 GitHub Release 下载新版 ZIP，只覆盖 LitePic 程序文件，并跳过 `.env`、`.user.ini`、`data/`、`uploads/`、`logs/` 和 `static/images/`。`Caddyfile` 属于程序文件会被替换——若已按站点改过，更新前请先备份，更新后再合并回自定义项。

无法在线更新时，可以手动升级：

```bash
# 1. 备份
tar czf backup-$(date +%F).tar.gz .env .user.ini data/ uploads/ logs/ static/images/ Caddyfile

# 2. 下载新版 LitePic ZIP，解压后覆盖程序文件
#    不要覆盖 .env / .user.ini / data / uploads / logs / static/images
#    Caddyfile 若已定制，合并官方改动后再替换

# 3. 打开后台设置页，数据库迁移会自动执行
```

## 文档

- 安装与部署：[litepic.io/docs](https://litepic.io/docs)
- API 参考：[litepic.io/api](https://litepic.io/api)
- 更新日志：[CHANGELOG.md](CHANGELOG.md)
- 反馈与建议：[GitHub Issues](https://github.com/gentpan/LitePic/issues)

## 协议

[MIT License](LICENSE)。可自由分发与商用，请保留版权声明。

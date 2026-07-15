<div align="center">

<img src="static/logo.png#gh-light-mode-only" alt="LitePic" height="80">
<img src="static/logo-dark.png#gh-dark-mode-only" alt="LitePic" height="80">

# LitePic

**轻量级自托管图床 · SQLite 单文件 · 异步处理 · S3 / R2 远程存储**

[![Version](https://img.shields.io/badge/version-3.4.3-0052D9?style=flat-square)](https://github.com/gentpan/LitePic/releases)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-111827?style=flat-square)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-litepic.io-0052D9?style=flat-square)](https://litepic.io/docs)

[官网](https://litepic.io) · [使用说明](https://litepic.io/docs) · [API 文档](https://litepic.io/api) · [更新日志](CHANGELOG.md)

</div>

---

LitePic 是一个 PHP 写的图床。一台 VPS、一份 SQLite 文件、一行命令就能跑起来。

它的核心设计是**先入库，再处理**——上传只负责把原图安全保存下来，缩略图、压缩、WebP/AVIF 转换、水印、远程同步全部进队列异步执行。即使某个环节挂了，原图和数据都不会丢。

## 特性

### 上传与处理
- 拖拽 / 点击 / 粘贴上传，批量队列、断点续传友好
- 支持 JPG、PNG、GIF、WebP、AVIF、SVG、ICO、BMP、TIFF
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
- 服务器能力卡（PHP / Web 服务器版本 / GD / Imagick / WebP / AVIF / 上传上限）一目了然
- 队列状态 KPI 卡：深度、失败任务数、上次运行时间，一键 drain
- 数据库一键热备份（VACUUM INTO）+ 自动调度 + 同步到 R2 + 一键恢复
- 残留数据扫描清理，5 类候选保守删除，永远不动磁盘文件和活动队列

## 环境要求

| 项目 | 最低 | 推荐 |
|------|------|------|
| PHP | 8.0+ | 8.2+（启用 OPCache） |
| 扩展 | fileinfo · pdo_sqlite · gd 或 imagick | imagick + libwebp + libheif |
| Web 服务器 | Nginx / OpenResty | Nginx + PHP-FPM |
| HTTPS | 生产必需 | 必需（Passkey 强依赖） |

## 快速开始

```bash
git clone https://github.com/gentpan/LitePic.git
cd LitePic
cp .env.example .env
mkdir -p uploads data logs && chmod -R 755 uploads data logs
```

编辑 `.env`（默认值即可起步，至少改这两项）：

```ini
SITE_NAME="LitePic"
SITE_URL="https://img.example.com"
# ADMIN_API_KEY 默认 12345678，登录后在「设置 → 安全」改成强随机字符串
```

> 公网暴露前务必把 `ADMIN_API_KEY` 改掉。后台首次登录用默认 `12345678`，会有横幅提示更换。

启动：

```bash
# 本地或生产：参考 nginx-litepic.conf，改 root + server_name 后 reload
```

打开浏览器访问 nginx 站点域名，第一次进设置页面注册管理员账号即可开始上传。

## 前端构建

样式用 Tailwind CSS v4 编译。仅在修改 CSS 时才需要运行：

```bash
npm install
npm run build:css       # 一次性构建（已 minify）
npm run watch:css       # 开发监听
npm run build:css:dev   # 未压缩，调试用
```

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
├── api/                  # API 入口，POST/GET 各业务路径
├── app/
│   ├── Core/             # 数据库、日志、响应、路由内核
│   ├── Http/             # 控制器、HTTP 路由
│   ├── Migrations/       # SQLite 迁移文件（按序号执行）
│   ├── Repository/       # 数据访问层（PSR-4）
│   ├── Service/          # 业务服务（Image / Storage / Queue / Auth）
│   └── pages/            # 页面模板
├── assets/
│   ├── css/              # Tailwind 源码 + 编译产物
│   └── js/               # 前端脚本
├── data/                 # SQLite 数据库 + 运行时 JSON（不要提交）
├── static/               # logo、favicon、首页背景
├── uploads/              # 本地图片存储（含 .thumbs/ 缩略图）
├── nginx-litepic.conf    # Nginx 参考配置
├── worker.php            # 异步任务 worker（cron 或 sidecar 长驻）
├── config.php            # 配置读取入口
└── .env.example          # 环境配置模板
```

## 升级与备份

升级时备份这几样就够：

```
.env
.user.ini
data/
uploads/
logs/
static/images/   # 首页背景、水印图片等自定义资源
```

`data/` 包含图库索引、任务队列、Token、Passkey 注册数据等运行状态。即使启用了远程存储仍建议本地保留 `data/`。

推荐使用后台一键更新：

```
设置 → 系统 → 程序更新 → 检查更新 → 立即更新
```

在线更新会从 GitHub Release 下载新版 ZIP，只覆盖 LitePic 程序文件，并跳过 `.env`、`.user.ini`、`data/`、`uploads/`、`logs/` 和 `static/images/`。

无法在线更新时，可以手动升级：

```bash
# 1. 备份
tar czf backup-$(date +%F).tar.gz .env .user.ini data/ uploads/ logs/ static/images/

# 2. 下载新版 LitePic ZIP，解压后覆盖程序文件
#    不要覆盖 .env / .user.ini / data / uploads / logs / static/images

# 3. 打开后台设置页，数据库迁移会自动执行
```

## 文档

- 安装与部署：[litepic.io/docs](https://litepic.io/docs)
- API 参考：[litepic.io/api](https://litepic.io/api)
- 更新日志：[CHANGELOG.md](CHANGELOG.md)
- 反馈与建议：[GitHub Issues](https://github.com/gentpan/LitePic/issues)

## 协议

[MIT License](LICENSE)。可自由分发与商用，请保留版权声明。

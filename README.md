# LitePic

LitePic V3.0.0 是一个面向自托管场景的 PHP 图床系统。它把图片上传、缩略图、压缩、WebP / AVIF 转换、图库管理、访问统计、水印、防盗链、API 上传和 R2/S3 远程存储整合在一个轻量应用里，不依赖数据库，适合个人站点、博客、设计素材库和小团队内部图床。

LitePic 的核心设计是“先入库，再处理”。上传只负责把图片安全保存下来，缩略图、压缩、转换、水印和远程同步可以进入队列分批执行。即使某一步处理失败，原图也不会丢失，后台会给出明确结果，后续可以重新处理。

[![Version](https://img.shields.io/badge/version-3.0.0-blue?style=flat-square)](https://github.com/gentpan/LitePic)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square)](https://www.php.net/)
[![Database](https://img.shields.io/badge/database-none-22c55e?style=flat-square)](https://github.com/gentpan/LitePic)
[![License](https://img.shields.io/badge/license-MIT-111827?style=flat-square)](LICENSE)

## 功能概览

- 本地图片上传：支持点击选择、拖拽、粘贴和多文件队列上传。
- 上传队列：先加入待上传队列，再选择单个上传或全部上传；批量上传使用统一进度条。
- 多格式支持：JPG、JPEG、PNG、GIF、WebP、AVIF、ICO、SVG、BMP、TIFF、TIF。
- 允许格式配置：后台可选择允许上传的格式，上传页会自动同步显示可用格式。
- 缩略图：图库和最近上传优先显示缩略图，复制和查看仍使用原图地址。
- 图片压缩：支持 TinyPNG、GD、ImageMagick 三种压缩方式。
- 格式转换：支持 WebP / AVIF；单张转换可直接执行，多张 AVIF 可进入异步任务队列。
- 水印：支持文字水印和 PNG 图片水印；文字水印支持字体、颜色、透明度和磨砂背景层。
- 防盗链：Apache / LiteSpeed 可由后台写入 `.htaccess`；Nginx、OpenResty、Caddy 提供配置片段。
- 图库管理：四列图片卡片、批量压缩、批量转换、批量删除、多种链接复制格式。
- 访问日志统计：可扫描 Web 服务器 access.log，统计 `/uploads/...` 图片请求次数。
- 远程存储：支持 S3 兼容协议，可对接 Cloudflare R2、AWS S3、MinIO 等。
- 远程存储模式：支持“远程备份”和“云端存储”两种用途。
- 后台设置：大部分开关即时生效；复杂表单可统一保存。
- 登录安全：支持 API Key 登录、Passkey 登录、CSRF 校验和登录限速。
- API 接入：统一使用 `/api/v1`，支持第三方上传和图片列表导出。

## 适用场景

LitePic 更适合以下场景：

- 不想维护数据库，只需要一个轻量图床后台。
- 博客、Markdown、论坛或 CMS 需要稳定的图片链接。
- 希望上传后自动生成 WebP / AVIF 或压缩图片。
- 想把本地图片同步备份到 R2/S3。
- 想让最终图片直接从 R2/S3 公网域名访问。
- 需要简单的访问请求次数统计，而不是复杂分析系统。

如果你需要多人权限体系、复杂相册协作、对象审核流或企业级审计，LitePic 不是这类大型 DAM 系统。

## 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | 8.0+ | 推荐 PHP 8.1+ |
| Web 服务器 | Apache / Nginx / OpenResty / Caddy / LiteSpeed | Apache 和 LiteSpeed 可直接使用 `.htaccess` |
| fileinfo | 必需 | 用于 MIME 检测 |
| GD | 推荐 | 本地压缩、WebP、部分 AVIF 能力 |
| ImageMagick / Imagick | 推荐 | 更好的图片处理兼容性 |
| cURL | 推荐 | TinyPNG、R2/S3 请求 |
| HTTPS | 生产推荐 | Cookie Secure、Passkey 和跨站安全更可靠 |

## 快速安装

```bash
git clone https://github.com/gentpan/LitePic.git
cd LitePic
cp .env.example .env
```

编辑 `.env`，至少设置管理员密钥：

```ini
SITE_NAME="LitePic"
SITE_DESCRIPTION="我的图床系统"
SITE_URL="https://img.example.com"
ADMIN_API_KEY="change-to-a-long-random-secret"
```

创建运行目录并授权：

```bash
mkdir -p uploads data logs
chmod -R 755 uploads data logs
```

开发环境可以直接运行：

```bash
php -S 127.0.0.1:5555 router.php
```

访问：

```text
http://127.0.0.1:5555
```

生产环境建议使用 PHP-FPM 配合 Nginx、OpenResty、Caddy、Apache 或 LiteSpeed。

## 推荐目录权限

Web 服务器用户需要写入这些目录：

```text
uploads/
uploads/.thumbs/
data/
logs/
static/images/
```

敏感文件不应被 Web 直接访问：

```text
.env
.user.ini
.git/
data/
logs/
```

项目已提供 `.htaccess` 和 `nginx.litepic.conf` 作为参考，生产部署时仍应按自己的服务器环境检查一遍。

## Nginx 示例

项目根目录提供 `nginx.litepic.conf`，可以作为生产配置基础。核心规则如下：

```nginx
server {
    listen 80;
    server_name img.example.com;
    root /var/www/LitePic;
    index index.php;

    client_max_body_size 50m;

    location ~ /\.(env|git) {
        deny all;
    }

    location ^~ /data/ {
        deny all;
    }

    location ^~ /logs/ {
        deny all;
    }

    location ~ ^/uploads/.*\.php$ {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

上传大图时还需要同步调整 PHP 和 Web 服务器限制：

```ini
upload_max_filesize = 50M
post_max_size = 52M
max_file_uploads = 50
memory_limit = 256M
max_execution_time = 120
max_input_time = 120
```

Nginx 还需要：

```nginx
client_max_body_size 52m;
```

## 基础配置

`.env.example` 包含完整配置模板。常用配置如下：

```ini
SITE_NAME="LitePic"
SITE_DESCRIPTION="轻量级图床程序"
SITE_URL=""

MAX_FILE_SIZE_MB=20
UPLOAD_ALLOWED_TYPES=jpg,jpeg,png,gif,webp,avif,ico,svg,bmp,tiff,tif

ADMIN_API_KEY=""
COOKIE_SECURE=false

AUTO_COMPRESS_ON_UPLOAD=false
AUTO_CONVERT_WEBP_ON_UPLOAD=false
AUTO_CONVERT_AVIF_ON_UPLOAD=false
CONVERT_PREFERRED_FORMAT=webp
KEEP_ORIGINAL_AFTER_PROCESS=false
COMPRESSION_MODE=imagemagick
```

后台设置会同步写入 `.env` 和必要的运行配置。上传格式、自动压缩、自动转换、水印、防盗链等开关可以在后台调整。

## 图片处理说明

### 缩略图

默认缩略图尺寸为 `640x360`。图库、上传页最近上传和部分预览优先显示缩略图，减少页面加载压力。

SVG 属于矢量格式：如果能从 `width` / `height` 或 `viewBox` 识别宽高，卡片显示分辨率；无法识别时显示“矢量图”。

### 压缩方式

LitePic 当前提供三种压缩方式：

| 方式 | 适合场景 | 说明 |
| --- | --- | --- |
| TinyPNG | 追求压缩率 | 需要配置 TinyPNG API Key，有调用额度限制 |
| GD | 基础本地环境 | PHP 常见扩展，部署简单，处理能力相对基础 |
| ImageMagick | 推荐本地方案 | 格式兼容更好，适合更稳定的图片处理 |

### WebP / AVIF

WebP 通常兼容性更好，适合作为默认转换格式。AVIF 通常压缩率更高，但对服务器扩展、编码速度和浏览器兼容性要求更高。

单张转换会直接执行；多张 AVIF 转换建议使用异步任务队列，避免一次请求占满服务器资源。

## 水印

水印支持两种模式：

- 文字水印：默认白色文字，支持字体、字号、颜色、透明度、位置、边距。
- 图片水印：支持上传 PNG 水印图，并配置显示宽度、透明度和位置。

文字水印默认会尝试使用 Ubuntu 字体。中文水印建议上传或填写服务器上的 TTF / OTF 字体路径。

水印可以开启磨砂背景层：

```ini
WATERMARK_PANEL_ENABLED=true
WATERMARK_PANEL_OPACITY=34
WATERMARK_PANEL_PADDING=10
WATERMARK_PANEL_RADIUS=10
```

水印会作用在新上传、导入、转换后的最终文件上。

## 防盗链

LitePic 支持两类防盗链策略。

### 原路径防盗链

图片地址保持为：

```text
/uploads/2026/04/example.jpg
```

Apache / LiteSpeed 环境下，后台可以自动写入或移除 `.htaccess` 规则。Nginx、OpenResty 和 Caddy 需要复制后台生成的配置片段到服务器配置中，再重载服务。

### PHP 受控入口

如果使用 PHP 受控图片入口，图片可以通过 `/i/...` 访问。这个方式由 PHP 检查 Referer，但性能不如 Web 服务器层防盗链，适合无法修改服务器配置的新手环境。

### 允许无来源请求

“允许无来源请求”表示 Referer 为空时不拦截。它会允许用户直接打开图片、某些隐私浏览器隐藏 Referer、或部分客户端不发送 Referer 的请求。关闭后，这类请求也会被拒绝。

## 远程存储

LitePic 使用 S3 兼容协议，支持 Cloudflare R2、AWS S3、MinIO 等。

```ini
REMOTE_STORAGE_USAGE=backup
S3_BUCKET=""
S3_REGION=auto
S3_ENDPOINT=""
S3_KEY=""
S3_SECRET=""
S3_PATH_PREFIX=uploads
S3_PUBLIC_BASE_URL=""
REMOTE_STORAGE_DELETE_DELAY_SECONDS=86400
```

### 远程备份

适合“本地仍是主存储，R2/S3 只是备份”的场景。

- 复制链接仍使用 LitePic 本站地址。
- 图库仍从本地读取。
- 远程保存一份原图和缩略图。
- 本地删除后，远程对象进入延迟删除队列，默认 24 小时后删除。

### 云端存储

适合“LitePic 负责上传、压缩、转换，最终图片从 R2/S3 公网域名访问”的场景。

- 需要配置 `S3_PUBLIC_BASE_URL`。
- 复制链接和 API 返回优先使用云端公网地址。
- 本地保留处理缓存和图库索引。
- 远程同步失败时需要先处理失败原因，避免复制到不可访问地址。

## 访问日志统计

如果开启 access.log 统计，LitePic 会扫描 Web 服务器访问日志，统计 `/uploads/...` 图片被请求的次数。

这统计的是“请求次数”，不是准确的引用次数。浏览器缓存、CDN 缓存、代理、爬虫和同一页面重复加载都会影响结果。

```ini
ACCESS_LOG_STATS_ENABLED=true
ACCESS_LOG_PATHS="/var/log/nginx/access.log"
ACCESS_LOG_CACHE_TTL=300
ACCESS_LOG_MAX_BYTES=20971520
```

多个日志路径可以用逗号分隔。

## 扫描导入和任务队列

后台支持扫描 `upload` / `uploads` 或自定义目录，并按原路径导入图库。选中的目录和子目录都会被扫描。

导入后处理采用任务队列执行：扫描只负责导入，缩略图、压缩、转换、水印和远程同步会排队分批处理。每次处理数量可控，避免一次请求占满服务器。

## 登录和安全

LitePic 支持：

- 管理员 API Key 登录。
- Passkey / WebAuthn 无密码登录。
- 登录失败限速。
- CSRF Token 保护。
- MIME 类型校验。
- SVG 危险内容检测。
- 上传目录禁止执行 PHP。
- 敏感路径访问保护。

生产环境建议：

- 设置强随机 `ADMIN_API_KEY`。
- HTTPS 下启用 `COOKIE_SECURE=true`。
- 关闭 `DEBUG` 和 `DISPLAY_ERRORS`。
- 限制 `.env`、`data`、`logs`、`.git` 的 Web 访问。
- 定期备份 `uploads`、`data` 和 `.env`。

## API

LitePic V3.0.0 的新接口统一使用：

```text
/api/v1
```

旧的 `/api/upload.php`、`/api/list.php`、`/api/export.php` 和 `/action.php` 不作为公开接入入口。

### 认证

可以使用任一 Header：

```text
X-API-Key: your-api-key
Authorization: Bearer your-api-key
```

后台可创建第三方上传 Token。出于安全原因，已创建 Token 不会长期明文展示。

### 上传图片

```bash
curl -X POST "https://img.example.com/api/v1" \
  -H "X-API-Key: your-api-key" \
  -F "image=@/path/to/photo.jpg"
```

响应示例：

```json
{
  "status": "success",
  "results": [
    {
      "status": "success",
      "filename": "2026/04/example.jpg",
      "original_name": "photo.jpg",
      "url": "https://img.example.com/uploads/2026/04/example.jpg",
      "thumbnail_url": "https://img.example.com/uploads/.thumbs/2026/04/example.thumb.jpg"
    }
  ]
}
```

### 导出列表

```bash
curl "https://img.example.com/api/v1/export?page=1&per_page=100" \
  -H "X-API-Key: your-api-key"
```

更多字段和示例可在部署后的 `/api` 页面查看。

## 前端资源构建

项目使用 Tailwind CLI 构建 CSS。

安装依赖：

```bash
npm install
```

构建未压缩 CSS：

```bash
npm run build:css
```

构建压缩 CSS：

```bash
npm run build:css:min
```

开发监听：

```bash
npm run watch:css
```

## 目录结构

```text
LitePic/
├── api/                    # API 入口
│   └── v1.php              # /api/v1 统一接口
├── app/
│   ├── core/               # 启动和核心加载
│   ├── http/               # 路由
│   └── pages/              # 页面模板
├── assets/
│   ├── css/                # 样式源码和构建产物
│   └── js/                 # 前端脚本
├── data/                   # 运行时 JSON 数据
├── lib/                    # 组件和类库
├── logs/                   # 日志目录
├── static/                 # Logo、favicon、首页背景图
├── uploads/                # 本地图片存储
├── action.php              # 内部 action 分发
├── config.php              # 配置读取
├── functions.php           # 核心函数
├── image.php               # 图片受控入口
├── index.php               # 页面入口
├── router.php              # PHP 内置服务器路由
└── nginx.litepic.conf      # Nginx 参考配置
```

## 备份建议

至少备份：

```text
.env
uploads/
data/
static/images/
```

如果启用了远程存储，仍建议备份 `data/`，因为其中包含图库索引、任务队列、Token、Passkey 等运行数据。

## 常见问题

### 上传大文件失败

同时检查：

- `.env` 中 `MAX_FILE_SIZE_MB`
- PHP `upload_max_filesize`
- PHP `post_max_size`
- Nginx `client_max_body_size`
- 面板或容器层限制

### WebP / AVIF 没有生成

检查：

- 后台是否开启自动转换。
- `CONVERT_PREFERRED_FORMAT` 是否正确。
- GD 或 Imagick 是否支持对应格式。
- 任务队列是否有待处理或失败任务。

### SVG 显示“矢量图”

SVG 没有可识别的 `width` / `height` / `viewBox` 时，LitePic 会显示“矢量图”。如果 SVG 包含明确宽高，会显示对应分辨率。

### 防盗链在 Nginx 不生效

后台写入 `.htaccess` 只对 Apache / LiteSpeed 有效。Nginx、OpenResty、Caddy 需要复制后台提供的配置片段到 Web 服务器配置，并重载服务。

### R2/S3 配置后链接仍是本地地址

如果使用“远程备份”模式，复制链接仍是本地地址。只有“云端存储”模式并配置公网访问域名后，复制链接和 API 返回才会优先使用云端地址。

## 反馈

问题反馈和功能建议请提交到：

```text
https://github.com/gentpan/LitePic/issues
```

## 协议

LitePic 使用 MIT License 开源。二次开发、分发或商用时，请保留版权与协议声明，并以仓库中的 LICENSE 文件为准。

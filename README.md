# LitePic

> 轻量级自托管图床 · 单文件数据库 · 异步图片处理 · S3 远程存储

[![Version](https://img.shields.io/badge/version-3.2.0-blue?style=flat-square)](https://github.com/gentpan/LitePic)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-111827?style=flat-square)](LICENSE)

LitePic 是一个面向自托管场景的 PHP 图床系统。上传、缩略图、压缩、WebP / AVIF 转换、水印、防盗链、访问统计、API 接入、R2/S3 远程存储全部整合在一个轻量应用里。

核心设计理念：**先入库，再处理**。上传只负责把图片安全保存下来，后续处理全部异步执行，即使某一步失败，原图也不会丢失。

---

## 主要特性

### 上传与处理

- 拖拽 / 点击 / 粘贴上传，支持批量队列
- JPG、PNG、GIF、WebP、AVIF、SVG、ICO、BMP、TIFF 全面支持
- 上传后异步生成缩略图、压缩、WebP / AVIF 转换、文字或 PNG 水印
- 支持 TinyPNG / GD / ImageMagick 三种压缩方式
- 三种 worker 触发方式（响应后自动 drain / Cron 兜底 / 手动按钮），互不冲突

### 安全

- Referer 防盗链，支持白名单
- Passkey / WebAuthn 无密码登录（指纹 / Face ID / 硬件 Key）
- API Key 登录、登录限速、CSRF Token、MIME 校验
- 上传目录禁止执行 PHP，敏感路径访问保护

### 图库

- 四列卡片展示，支持批量压缩、批量转换、批量删除
- 右键菜单：重新生成缩略图 / 下载任意格式 / 转换 WebP 或 AVIF
- 可自定义 URL 前缀（`/uploads/` → `/img/` `/photo/` 等）
- 支持扫描导入本地已有图片目录

### 远程存储

- S3 兼容协议：Cloudflare R2、AWS S3、MinIO 等直接对接
- 两种模式：**远程备份**（本地为主，R2 备份）或 **云端存储**（链接直出 R2 公网地址）
- 本地删除后自动延迟清理远程对象

### 部署

- 零数据库依赖：SQLite 单文件存储
- 支持 Apache、Nginx、OpenResty、Caddy、LiteSpeed、PHP 内置服务器
- 提供等价的 `.htaccess`、`nginx-litepic.conf`、`Caddyfile.example`、`router.php`

---

## 环境要求

| 项目 | 最低要求 | 推荐 |
|------|---------|------|
| PHP | 8.0+ | 8.1+ |
| Web 服务器 | 任意主流 | Nginx + PHP-FPM |
| fileinfo | 必需 | 必需 |
| GD / Imagick | 推荐 | ImageMagick |
| cURL | 推荐 | 安装 |
| HTTPS | 生产必需 | 开启（Passkey 跨域安全） |

---

## 快速开始

### 1. 克隆项目

```bash
git clone https://github.com/gentpan/LitePic.git
cd LitePic
```

### 2. 配置

复制环境配置模板并编辑：

```bash
cp .env.example .env
```

编辑 `.env`，至少填写：

```ini
SITE_NAME="LitePic"
SITE_URL="https://img.example.com"
ADMIN_API_KEY="请使用长随机字符串"
```

### 3. 创建目录并授权

```bash
mkdir -p uploads data logs
chmod -R 755 uploads data logs
```

### 4. 启动

**开发环境（PHP 内置服务器）：**

```bash
php -S 127.0.0.1:5555 router.php
```

**生产环境：**

复制对应的服务器配置文件（`nginx-litepic.conf` / `Caddyfile.example`），修改 root 路径和域名，重载服务。

### 5. 访问

打开浏览器访问你配置的域名或 `http://127.0.0.1:5555`，上传第一张图片。

---

## 前端构建

项目使用 Tailwind CSS v4 构建样式。

```bash
# 安装依赖
npm install

# 开发监听（自动重编译）
npm run watch:css

# 构建生产压缩 CSS
npm run build:css:min

# 构建未压缩版本（调试用）
npm run build:css
```

---

## 目录结构

```
LitePic/
├── api/                     # API 入口
│   └── v1.php               # /api/v1 统一接口
├── app/
│   ├── Core/                # 核心加载
│   ├── Http/                # 路由、控制器
│   ├── Repository/          # 数据库操作
│   ├── Service/             # 业务逻辑（图片、存储、队列）
│   └── pages/              # 页面模板
├── assets/
│   ├── css/                 # Tailwind 源码
│   └── js/                  # 前端脚本
├── data/                    # SQLite 数据库 + 运行时 JSON
├── lib/                     # 组件库（JSON DB、Passkey 等）
├── logs/                    # 日志目录
├── static/                  # Logo、favicon、背景图
├── uploads/                 # 本地图片存储（含 .thumbs/ 缩略图）
├── nginx-litepic.conf       # Nginx 参考配置
├── Caddyfile.example        # Caddy 参考配置
├── router.php               # PHP 内置服务器路由
├── worker.php               # 后台异步任务 worker
├── config.php               # 配置读取入口
└── .env.example             # 环境配置模板
```

---

## API 接入

所有新接口统一走 `/api/v1`。

### 认证

```bash
# 任选一种 Header
-H "X-API-Key: your-api-key"
-H "Authorization: Bearer your-api-key"
```

### 上传图片

```bash
curl -X POST "https://img.example.com/api/v1" \
  -H "X-API-Key: your-api-key" \
  -F "image=@/path/to/photo.jpg"
```

**响应示例：**

```json
{
  "status": "success",
  "results": [
    {
      "status": "success",
      "filename": "2026/05/example.jpg",
      "original_name": "photo.jpg",
      "url": "https://img.example.com/uploads/2026/05/example.jpg",
      "thumbnail_url": "https://img.example.com/uploads/.thumbs/2026/05/example.thumb.jpg"
    }
  ]
}
```

### 导出图片列表

```bash
curl "https://img.example.com/api/v1/export?page=1&per_page=100" \
  -H "X-API-Key: your-api-key"
```

### 触发任务队列

```bash
curl -X POST "https://img.example.com/api/v1/queue/drain" \
  -H "X-API-Key: your-api-key"
```

---

## 备份建议

至少备份以下目录：

```
.env
uploads/
data/
static/
```

如果启用了远程存储，仍建议备份 `data/`，因为其中包含图库索引、任务队列、登录 Token、Passkey 注册数据等运行状态。

---

## 反馈

问题与功能建议请提交 [GitHub Issues](https://github.com/gentpan/LitePic/issues)。

---

## 协议

MIT License。可自由分发和商用，但请保留 LICENSE 文件中的版权声明。

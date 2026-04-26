<div align="center">

# 🖼️ LitePic

**轻量级、安全、美观的 PHP 图床程序**

[![Version](https://img.shields.io/badge/version-2.2.0-blue?style=flat-square)](https://github.com/gentpan/LitePic)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square&logo=php)](https://php.net)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES6%2B-f7df1e?style=flat-square&logo=javascript)](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
[![No Database](https://img.shields.io/badge/database-none-brightgreen?style=flat-square)](https://github.com/gentpan/LitePic)
[![Dark Mode](https://img.shields.io/badge/theme-dark%2Flight-ff69b4?style=flat-square)](https://github.com/gentpan/LitePic)

[🚀 在线演示](https://img.xifeng.net) · [📖 使用文档](https://github.com/gentpan/LitePic/wiki) · [🐛 提交 Issue](https://github.com/gentpan/LitePic/issues)

</div>

---

## ✨ 特性

- 📁 **无数据库** — 纯文件系统存储，部署简单，零配置
- 🌓 **双主题** — 内置亮色 / 暗色 / 跟随系统三种模式
- 🖼️ **多格式支持** — JPG、PNG、GIF、WebP、SVG、ICO、BMP、TIFF
- ⚡ **自动处理** — 上传后自动压缩、转 WebP、生成缩略图
- ☁️ **远程同步** — 支持 S3 / Cloudflare R2 同步与备份
- 🔑 **API 上传** — 提供第三方 API Key，可对接 WordPress、Typora 等
- 📊 **统计面板** — 实时查看访问量、图片数、存储占用
- 🔒 **安全加固** — CSRF 防护、MIME 校验、SVG 危险内容检测、登录速率限制
- 📱 **响应式设计** — 完美适配桌面端与移动端

---

## 🚀 快速开始

### 环境要求

| 项目 | 最低版本 | 说明 |
|------|---------|------|
| PHP | 8.0+ | 必需 |
| GD 或 ImageMagick | — | 图片处理 |
| cURL | — | TinyPNG / R2 同步 |
| fileinfo | — | MIME 类型检测 |

### 方式一：Docker（推荐）

```bash
# 1. 克隆仓库
git clone https://github.com/gentpan/LitePic.git
cd LitePic

# 2. 复制环境配置
cp .env.example .env

# 3. 修改管理员密钥（必须！）
vim .env
# ADMIN_API_KEY=your-strong-random-key

# 4. 构建并启动
docker-compose up -d

# 5. 访问 http://localhost:8080
```

Docker 会自动处理 PHP 扩展和 Apache 配置，数据通过 volume 持久化。

### 方式二：手动安装

```bash
# 1. 克隆仓库
git clone https://github.com/gentpan/LitePic.git
cd LitePic

# 2. 复制环境配置
cp .env.example .env

# 3. 创建必要目录
mkdir -p uploads logs data

# 4. 修改管理员密钥（必须！）
vim .env
# ADMIN_API_KEY=your-strong-random-key

# 5. 确保目录可写
chmod -R 755 uploads data logs

# 6. 启动（开发）
php -S 127.0.0.1:8080 router.php
```

访问 `http://127.0.0.1:8080` 即可使用。

### 生产部署

#### Apache
```apache
# 确保已启用 mod_rewrite，.htaccess 已包含重写规则
```

#### Nginx
```nginx
# 参考项目根目录 nginx.litepic.conf
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/LitePic;
    index index.php;

    client_max_body_size 50m;

    # 禁止访问敏感文件
    location ~ /\.env  { deny all; return 403; }
    location ~ /\.git  { deny all; return 403; }
    location ~ ^/data/ { deny all; return 403; }
    location ~ ^/logs/ { deny all; return 403; }

    # 禁止 uploads 目录执行脚本
    location ~ ^/uploads/.*\.php$ { deny all; return 403; }

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

---

## ⚙️ 配置说明

编辑 `.env` 文件：

```ini
# 基础配置
SITE_NAME="LitePic"
ADMIN_API_KEY="your-strong-random-key"

# 上传限制（MB）
MAX_FILE_SIZE_MB=20

# 自动处理开关
AUTO_COMPRESS_ON_UPLOAD=false
AUTO_CONVERT_WEBP_ON_UPLOAD=false

# 远程存储（S3 / R2）
REMOTE_STORAGE_MODE=off
S3_PROVIDER=r2
S3_BUCKET="your-bucket"
S3_ENDPOINT="https://xxxxx.r2.cloudflarestorage.com"
S3_KEY="your-access-key"
S3_SECRET="your-secret-key"

# TinyPNG 压缩（可选）
TINIFY_API_KEYS="key1,key2"
```

> ⚠️ **安全提示**：生产环境务必将 `COOKIE_SECURE` 设为 `true`（HTTPS），`DEBUG` 设为 `false`。

---

## 📡 API 文档

### 认证方式

支持两种方式：
- **Header**: `X-API-Key: <your-key>`
- **Header**: `Authorization: Bearer <your-key>`

### 上传接口

```bash
curl -X POST https://your-domain.com/api/upload.php \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "image=@/path/to/image.png"
```

**响应示例**：
```json
{
  "status": "success",
  "results": [{
    "status": "success",
    "filename": "2026/04/xxxx.png",
    "original_name": "image.png",
    "url": "https://your-domain.com/uploads/2026/04/xxxx.png",
    "thumbnail_url": "https://your-domain.com/uploads/.thumbs/2026/04/xxxx.thumb.jpg"
  }]
}
```

### 导出接口

```bash
curl -H "X-API-Key: YOUR_API_KEY" \
  "https://your-domain.com/api/export.php?page=1&per_page=100"
```

更多 API 详情请访问 `/docs` 页面。

---

## 🛡️ 安全特性

| 防护措施 | 状态 | 说明 |
|---------|------|------|
| CSRF Token | ✅ | 所有状态变更操作强制校验 |
| MIME 校验 | ✅ | 上传文件头检测，防止扩展名伪造 |
| SVG 清洗 | ✅ | 检测危险标签（`<script>`、事件处理器） |
| 速率限制 | ✅ | 登录 5 次失败后封禁 5 分钟 |
| 敏感文件保护 | ✅ | `.env`、`.git`、`data/` 禁止 Web 访问 |
| 脚本执行禁止 | ✅ | `uploads/` 目录禁止执行 PHP |
| XSS 过滤 | ✅ | JS 模板字符串全面转义 |

---

## 🏗️ 开发

### CSS 构建

```bash
# 开发模式自动加载独立 CSS 模块（DEBUG=true）
# 生产模式构建合并压缩版
./build-css.sh
```

### 文件结构

```
LitePic/
├── api/              # API 接口
├── app/
│   ├── core/         # 核心逻辑
│   ├── http/         # HTTP 路由
│   └── pages/        # 页面模板
├── assets/
│   ├── css/modules/  # 模块化样式（10 个独立文件）
│   └── js/           # 前端脚本
├── data/             # 运行时数据（JSON）
├── lib/              # 类库
├── uploads/          # 图片存储
├── config.php        # 配置入口
├── functions.php     # 核心函数库
├── router.php        # 开发路由
└── build-css.sh      # CSS 构建脚本
```

---

## 🌍 WordPress 插件

项目包含 WordPress 插件，位于 `wordpress/litepic-wordpress/`：

- 文章编辑器增加「上传到 LitePic」按钮
- 后台管理已上传图片
- 支持配置 API 地址与 Key

安装方式：将文件夹压缩为 ZIP，在 WordPress 后台「插件 → 安装插件 → 上传」即可。

---

## 📄 开源协议

[MIT License](LICENSE) © 2026 LitePic Contributors

---

<div align="center">

Made with ❤️ by [Xifeng](https://xifeng.net)

</div>

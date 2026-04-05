# LitePic 本地开发（localhost）

## 1) 初始化并启动

在项目根目录执行：

```bash
./dev-local.sh
```

默认访问地址：

`http://127.0.0.1:8080`

脚本会自动创建本地目录：

- `uploads/`
- `logs/`
- `data/visit_count.txt`

并自动按 `.env` 的 `MAX_FILE_SIZE_MB` 启动 PHP 上传限制：

- `upload_max_filesize`
- `post_max_size`
- `max_file_uploads`
- `memory_limit`

修改“最大上传大小（MB）”后，请重启本地服务使其立即生效。

## 2) 本地自定义配置

可通过环境变量覆盖关键配置：

```bash
export ADMIN_API_KEY="your-local-key"
export THIRD_PARTY_API_KEYS="wp-key-1,tool-key-2"
export SITE_URL="http://127.0.0.1:8080"
export COOKIE_SECURE="false"
./dev-local.sh
```

可选变量：

- `HOST`（默认 `127.0.0.1`）
- `PORT`（默认 `8080`）
- `DEBUG`（`true/false`）
- `DISPLAY_ERRORS`（`true/false`）
- `THIRD_PARTY_API_KEYS`（逗号分隔）

示例：

```bash
HOST=localhost PORT=9000 ./dev-local.sh
```

## 3) 路由支持（Apache / Nginx）

- Apache：项目根目录已提供 `.htaccess`，可直接支持无后缀路由：
  - `/upload`
  - `/docs`
  - `/stats`
  - `/settings`
  - `/gallery`

- Nginx：项目根目录已提供示例配置：
  - `nginx.litepic.conf`
  - 按你的环境修改 `root` 和 `fastcgi_pass` 后启用即可。

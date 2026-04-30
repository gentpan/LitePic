# 第三方上传 API

## Endpoint

- `POST /api/v1`
- `GET /api/v1/export`

## 鉴权方式

二选一：

- Header: `X-API-Key: <your-key>`
- Header: `Authorization: Bearer <your-key>`

服务端会校验：

- `ADMIN_API_KEY`
- 或 `THIRD_PARTY_API_KEYS`（环境变量，逗号分隔）
- 或在 `settings.php` 的“API Token 管理”中创建的 Token

这些凭据仅用于上传和导出接口，不再授予图库管理、删除或系统状态读取权限。

导出接口 `GET /api/v1/export` 支持使用相同的上传 Token 读取图片清单，适合跨图床迁移时拉取全量图片地址。

## 请求参数

使用 `multipart/form-data`，支持字段：

- `image`（单文件）
- `image[]`（多文件）
- `file`（单文件）
- `files[]`（多文件）

## 导出参数

`GET /api/v1/export` 支持：

- `page`：页码，默认 `1`
- `per_page`：每页数量，默认 `100`，最大 `500`
- `q`：按文件名搜索
- `sort`：`date-desc` / `date-asc` / `name-asc` / `name-desc` / `size-asc` / `size-desc`
- `all=1`：一次性返回全部图片

## 响应示例

```json
{
  "status": "success",
  "results": [
    {
      "status": "success",
      "filename": "65f0a8f4438b1.png",
      "original_name": "demo.png",
      "url": "https://your-domain.com/uploads/2026/02/65f0a8f4438b1.png"
    }
  ]
}
```

## cURL 示例

```bash
curl -X POST "https://your-domain.com/api/v1" \
  -H "X-API-Key: your-third-party-key" \
  -F "image=@/path/to/demo.png"
```

## 导出示例

```bash
curl "https://your-domain.com/api/v1/export?all=1" \
  -H "Authorization: Bearer your-third-party-key"
```

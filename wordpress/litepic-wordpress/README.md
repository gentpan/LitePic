# LitePic External Uploader（WordPress 插件）

## 功能

- 在文章编辑器增加按钮：`上传到 LitePic`
- 上传后自动插入图片到编辑器
- 后台可配置 LitePic API 地址与 API Key

## 安装

1. 将 `litepic-wordpress` 目录打包为 zip。
2. WordPress 后台 -> 插件 -> 安装插件 -> 上传插件。
3. 启用 `LitePic External Uploader`。

## 配置

后台：`媒体 -> 图床设置`

- 图床网址：`https://你的图床域名`
  - 也支持填写 `https://你的图床域名/upload`
  - 插件会自动拼接接口：`/api/upload.php`、`/api/list.php`、`/action.php`
- 认证信息：支持填写
  - 第三方上传 Token
  - 或管理员 API Key（后台登录密钥）
- API Key：你在图床服务端配置的 key（`ADMIN_API_KEY` 或 `THIRD_PARTY_API_KEYS` 之一）

## 服务器端示例

```bash
export ADMIN_API_KEY="admin-key"
export THIRD_PARTY_API_KEYS="wp-key-1,wp-key-2"
```

或在部署环境配置同名环境变量。

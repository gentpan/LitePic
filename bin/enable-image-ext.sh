#!/usr/bin/env bash
#
# LitePic — 一键启用图片处理扩展（Debian / Ubuntu）
# =================================================
#
# 与设置页探针同源：读 /etc/os-release、PHP 版本、是否 FrankenPHP，
# 安装 phpX.Y-gd / phpX.Y-imagick / libheif1，可选写入 Caddyfile php_ini
# 上传上限，然后 validate + restart。
#
# 用法（需 root）:
#   sudo ./bin/enable-image-ext.sh
#   sudo ./bin/enable-image-ext.sh --upload 50M
#   sudo ./bin/enable-image-ext.sh --dry-run
#   sudo ./bin/enable-image-ext.sh --no-restart
#
set -euo pipefail

UPLOAD_SIZE=""
DRY_RUN=0
NO_RESTART=0
CADDYFILE="${LITEPIC_CADDYFILE:-/etc/frankenphp/Caddyfile}"

usage() {
  sed -n '2,18p' "$0" | sed 's/^# \?//'
  exit 0
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --upload)
      UPLOAD_SIZE="${2:-}"
      shift 2
      ;;
    --upload=*)
      UPLOAD_SIZE="${1#*=}"
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --no-restart)
      NO_RESTART=1
      shift
      ;;
    -h|--help)
      usage
      ;;
    *)
      echo "未知参数: $1" >&2
      exit 1
      ;;
  esac
done

run() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "+ $*"
  else
    echo "+ $*"
    "$@"
  fi
}

if [[ "$(id -u)" -ne 0 && "$DRY_RUN" -eq 0 ]]; then
  echo "请以 root 运行：sudo $0 $*" >&2
  exit 1
fi

# ---- 探针：发行版 ----
OS_ID=""
OS_PRETTY=""
if [[ -r /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_PRETTY="${PRETTY_NAME:-$NAME $VERSION_ID}"
fi

case "$OS_ID" in
  debian|ubuntu) ;;
  *)
    echo "当前系统「${OS_PRETTY:-unknown}」不是 Debian/Ubuntu，请按 https://litepic.io/docs 手动安装。" >&2
    exit 2
    ;;
esac

# ---- 探针：PHP 版本 ----
PHP_BIN=""
for candidate in php frankenphp; do
  if command -v "$candidate" >/dev/null 2>&1; then
    PHP_BIN="$candidate"
    break
  fi
done

PHP_VER=""
if [[ -n "$PHP_BIN" ]]; then
  if [[ "$PHP_BIN" == "frankenphp" ]]; then
    PHP_VER="$(frankenphp php-cli -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  else
    PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  fi
fi
if [[ -z "$PHP_VER" ]]; then
  PHP_VER="$(ls -1d /etc/php/*/mods-available 2>/dev/null | sed -n 's#.*/php/\([0-9.]*\)/.*#\1#p' | sort -V | tail -1 || true)"
fi
if [[ -z "$PHP_VER" ]]; then
  echo "无法探测 PHP 版本。" >&2
  exit 3
fi

# ---- 探针：Web / FrankenPHP ----
IS_FRANKEN=0
FRANKEN_VER=""
if command -v frankenphp >/dev/null 2>&1; then
  IS_FRANKEN=1
  FRANKEN_VER="$(frankenphp version 2>/dev/null | head -1 | sed -n 's/.*FrankenPHP v\([0-9.]*\).*/\1/p' || true)"
fi
if [[ -f "$CADDYFILE" ]]; then
  IS_FRANKEN=1
fi

WEB_LABEL="php${PHP_VER}-fpm"
if [[ "$IS_FRANKEN" -eq 1 ]]; then
  WEB_LABEL="FrankenPHP${FRANKEN_VER:+ $FRANKEN_VER}"
fi

echo "==> 探针：$OS_PRETTY · PHP $PHP_VER · $WEB_LABEL"

PKGS=("php${PHP_VER}-gd" "php${PHP_VER}-imagick" "libheif1")

echo "==> 将安装：${PKGS[*]}"
run apt-get update
run apt-get install -y "${PKGS[@]}"

# Debian 模块软链（若存在 phpenmod）
if command -v phpenmod >/dev/null 2>&1; then
  run phpenmod -v "$PHP_VER" gd imagick || true
fi

# ---- 上传上限（FrankenPHP Caddyfile）----
normalize_size() {
  local raw="${1^^}"
  raw="${raw// /}"
  if [[ "$raw" =~ ^[0-9]+M$ ]]; then
    echo "$raw"
    return
  fi
  if [[ "$raw" =~ ^[0-9]+$ ]]; then
    echo "${raw}M"
    return
  fi
  echo ""
}

if [[ -n "$UPLOAD_SIZE" && "$IS_FRANKEN" -eq 1 && -f "$CADDYFILE" ]]; then
  UP_NORM="$(normalize_size "$UPLOAD_SIZE")"
  if [[ -z "$UP_NORM" ]]; then
    echo "无效 --upload 值：$UPLOAD_SIZE（示例 20M）" >&2
    exit 4
  fi
  UP_NUM="${UP_NORM%M}"
  POST_NUM=$((UP_NUM + 2))
  if [[ "$POST_NUM" -lt $((UP_NUM + 2)) ]]; then POST_NUM=$((UP_NUM + 2)); fi
  POST_NORM="${POST_NUM}M"

  echo "==> 更新 $CADDYFILE php_ini 上传上限：upload=${UP_NORM} post=${POST_NORM}"
  BACKUP="${CADDYFILE}.bak.$(date +%Y%m%d%H%M%S)"
  if [[ "$DRY_RUN" -eq 0 ]]; then
    cp -a "$CADDYFILE" "$BACKUP"
  else
    echo "+ cp -a $CADDYFILE $BACKUP"
  fi

  # 用 Python 做幂等替换，避免 fragile sed 跨多行块
  PATCH_PY=$(cat <<'PY'
import re, sys
path, upload, post = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding="utf-8").read()

def upsert_ini(block: str, key: str, value: str) -> str:
    pat = re.compile(rf'(?m)^\s*{re.escape(key)}\s+[^\n]*$')
    line = f'\t\t\t{key} {value}'
    if pat.search(block):
        return pat.sub(line, block, count=1)
    # 插到 php_ini { 后一行
    return re.sub(
        r'(php_ini\s*\{)\s*',
        rf'\1\n{line}\n',
        block,
        count=1,
    )

m = re.search(r'(frankenphp\s*\{.*?php_ini\s*\{.*?\n\s*\})', text, re.S)
if not m:
    # 在首个全局 { } 内注入 frankenphp { php_ini { ... } }
    inj = (
        "\tfrankenphp {\n"
        "\t\tphp_ini {\n"
        f"\t\t\tupload_max_filesize {upload}\n"
        f"\t\t\tpost_max_size {post}\n"
        "\t\t}\n"
        "\t}\n\n"
    )
    text2, n = re.subn(r'(^\{\s*\n)', r'\1' + inj, text, count=1, flags=re.M)
    if n == 0:
        text = inj + text
    else:
        text = text2
else:
    block = m.group(1)
    block = upsert_ini(block, "upload_max_filesize", upload)
    block = upsert_ini(block, "post_max_size", post)
    text = text[:m.start(1)] + block + text[m.end(1):]

# 站点 request_body max_size（若已有则改数值）
body_pat = re.compile(r'(request_body\s*\{\s*max_size\s+)(\d+)(MB)')
post_mb = post.rstrip("Mm")
if body_pat.search(text):
    text = body_pat.sub(rf'\g<1>{post_mb}\3', text)

open(path, "w", encoding="utf-8").write(text)
print("updated", path)
PY
)
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "+ python3 - <<'PY' … patch $CADDYFILE …"
  else
    python3 -c "$PATCH_PY" "$CADDYFILE" "$UP_NORM" "$POST_NORM"
  fi
elif [[ -n "$UPLOAD_SIZE" && "$IS_FRANKEN" -eq 0 ]]; then
  UP_NORM="$(normalize_size "$UPLOAD_SIZE")"
  echo "==> 非 FrankenPHP：请手动在 php.ini 设置 upload_max_filesize=${UP_NORM:-20M}"
fi

# ---- 重启 ----
if [[ "$NO_RESTART" -eq 0 ]]; then
  if [[ "$IS_FRANKEN" -eq 1 ]]; then
    if [[ -f "$CADDYFILE" ]]; then
      run frankenphp validate --config "$CADDYFILE"
    fi
    run systemctl restart frankenphp
  else
    if systemctl list-unit-files "php${PHP_VER}-fpm.service" 2>/dev/null | grep -q php; then
      run systemctl restart "php${PHP_VER}-fpm"
    else
      echo "未找到 php${PHP_VER}-fpm 服务，请手动重启 PHP。" >&2
    fi
  fi
else
  echo "==> 跳过重启（--no-restart）"
fi

# ---- 自检 ----
echo "==> 自检"
probe_php() {
  local code='$g=extension_loaded("gd");$i=extension_loaded("imagick");$w=function_exists("imagewebp");$a=function_exists("imageavif");$h=false;if(class_exists("Imagick")){try{$im=new Imagick();$h=!empty($im->queryFormats("HEIC"))||!empty($im->queryFormats("HEIF"));$w=$w||!empty($im->queryFormats("WEBP"));$a=$a||!empty($im->queryFormats("AVIF"));$im->clear();}catch(Throwable $e){}}echo "gd=".($g?"yes":"no")." imagick=".($i?"yes":"no")." webp=".($w?"yes":"no")." avif=".($a?"yes":"no")." heic=".($h?"yes":"no")." upload=".ini_get("upload_max_filesize")." post=".ini_get("post_max_size")."\n";'
  if command -v frankenphp >/dev/null 2>&1; then
    frankenphp php-cli -r "$code" 2>/dev/null || true
  fi
  if command -v php >/dev/null 2>&1; then
    echo -n "cli: "
    php -r "$code" 2>/dev/null || true
  fi
}
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "(dry-run 跳过自检)"
else
  probe_php
fi

echo "==> 完成。可在 LitePic 设置 → 环境信息 点「刷新检测」更新徽章。"

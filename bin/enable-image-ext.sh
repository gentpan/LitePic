#!/usr/bin/env bash
#
# LitePic — 一键启用图片处理扩展（Debian / Ubuntu）
# =================================================
#
# 与设置页探针同源：读 /etc/os-release、PHP 版本、是否 FrankenPHP，
# 安装 phpX.Y-gd / phpX.Y-imagick / libheif1，链到 FrankenPHP 的
# /etc/php-zts/conf.d/，可选写入 Caddyfile php_ini，然后重启。
#
# 用法（需 root，或已配置 sudoers 后由网页调用）:
#   sudo ./bin/enable-image-ext.sh
#   sudo ./bin/enable-image-ext.sh --upload 50M
#   sudo ./bin/enable-image-ext.sh --install-sudoers   # 允许 frankenphp 用户免密执行本脚本
#   sudo ./bin/enable-image-ext.sh --web-run           # 网页调用：延迟重启以便 API 先返回
#   sudo ./bin/enable-image-ext.sh --dry-run
#   sudo ./bin/enable-image-ext.sh --no-restart
#
set -euo pipefail

UPLOAD_SIZE=""
DRY_RUN=0
NO_RESTART=0
DEFER_RESTART=0
INSTALL_SUDOERS=0
WEB_RUN=0
CADDYFILE="${LITEPIC_CADDYFILE:-/etc/frankenphp/Caddyfile}"
SUDOERS_FILE="/etc/sudoers.d/litepic-enable-ext"
SCRIPT_PATH="$(readlink -f "$0" 2>/dev/null || realpath "$0" 2>/dev/null || echo "$0")"

usage() {
  sed -n '2,20p' "$0" | sed 's/^# \?//'
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
    --defer-restart)
      DEFER_RESTART=1
      shift
      ;;
    --web-run)
      WEB_RUN=1
      DEFER_RESTART=1
      shift
      ;;
    --install-sudoers)
      INSTALL_SUDOERS=1
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
  echo "请以 root 运行：sudo $0 ..." >&2
  exit 1
fi

# ---- 安装 sudoers（供网页一键调用）----
if [[ "$INSTALL_SUDOERS" -eq 1 ]]; then
  RUN_USER="${LITEPIC_RUN_USER:-}"
  if [[ -z "$RUN_USER" ]]; then
    if id frankenphp >/dev/null 2>&1; then
      RUN_USER="frankenphp"
    elif id www-data >/dev/null 2>&1; then
      RUN_USER="www-data"
    else
      echo "无法推断 Web 运行用户，请设置 LITEPIC_RUN_USER=xxx" >&2
      exit 5
    fi
  fi
  echo "==> 写入 $SUDOERS_FILE（用户 $RUN_USER → $SCRIPT_PATH）"
  TMP="$(mktemp)"
  cat >"$TMP" <<EOF
# Managed by LitePic bin/enable-image-ext.sh --install-sudoers
# Allows the web process to one-click enable image extensions (admin UI only).
Defaults:${RUN_USER} !requiretty
${RUN_USER} ALL=(root) NOPASSWD: ${SCRIPT_PATH}
EOF
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "+ install -m 440 $TMP $SUDOERS_FILE"
    cat "$TMP"
    rm -f "$TMP"
  else
    if command -v visudo >/dev/null 2>&1; then
      visudo -cf "$TMP"
    else
      # 部分精简系统未装 sudo 包自带的 visudo；仍校验基本格式后写入
      if ! grep -qE '^[A-Za-z0-9._-]+ ALL=\(root\) NOPASSWD: /' "$TMP"; then
        echo "sudoers 内容校验失败" >&2
        rm -f "$TMP"
        exit 5
      fi
    fi
    install -m 440 "$TMP" "$SUDOERS_FILE"
    rm -f "$TMP"
    echo "已安装。网页「一键启用」可调用：sudo -n $SCRIPT_PATH --web-run"
  fi
  # 仅装 sudoers 时到此结束（除非同时带了其它意图；默认只装）
  if [[ "$WEB_RUN" -eq 0 && -z "$UPLOAD_SIZE" && "$NO_RESTART" -eq 0 ]]; then
    exit 0
  fi
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
export DEBIAN_FRONTEND=noninteractive
run apt-get update
run apt-get install -y "${PKGS[@]}"

# Debian NTS 模块（php-fpm / cli）
if command -v phpenmod >/dev/null 2>&1; then
  run phpenmod -v "$PHP_VER" gd imagick || true
fi

# FrankenPHP ZTS：实际扫 /etc/php-zts/conf.d/，需单独建链
# 也恢复被手动挪走的 ini（例如测试时 mv 到 /root/*.disabled-test）
enable_zts_mod() {
  local mod="$1"
  local zts_dir="/etc/php-zts/conf.d"
  local mods_dir="/etc/php/${PHP_VER}/mods-available"
  local src="${mods_dir}/${mod}.ini"
  local dest="${zts_dir}/20-${mod}.ini"

  if [[ ! -d "$zts_dir" ]]; then
    return 0
  fi
  if [[ ! -f "$src" ]]; then
    echo "警告：找不到 $src" >&2
    return 0
  fi

  # 若被挪到 /root 备份，优先还原
  local bak
  for bak in "/root/20-${mod}.ini.disabled-test" "/root/20-${mod}.ini.bak"; do
    if [[ -f "$bak" && ! -e "$dest" ]]; then
      echo "==> 还原 $bak → $dest"
      if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "+ mv $bak $dest"
      else
        mv "$bak" "$dest"
      fi
      return 0
    fi
  done

  if [[ -L "$dest" || -f "$dest" ]]; then
    echo "==> ZTS 已启用：$dest"
    return 0
  fi
  echo "==> 链接 ZTS 模块：$dest → $src"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "+ ln -sfn $src $dest"
  else
    ln -sfn "$src" "$dest"
  fi
}

if [[ "$IS_FRANKEN" -eq 1 ]]; then
  enable_zts_mod gd
  enable_zts_mod imagick
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
  POST_NORM="${POST_NUM}M"

  echo "==> 更新 $CADDYFILE php_ini 上传上限：upload=${UP_NORM} post=${POST_NORM}"
  BACKUP="${CADDYFILE}.bak.$(date +%Y%m%d%H%M%S)"
  if [[ "$DRY_RUN" -eq 0 ]]; then
    cp -a "$CADDYFILE" "$BACKUP"
  else
    echo "+ cp -a $CADDYFILE $BACKUP"
  fi

  PATCH_PY=$(cat <<'PY'
import re, sys
path, upload, post = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding="utf-8").read()

def upsert_ini(block: str, key: str, value: str) -> str:
    pat = re.compile(rf'(?m)^\s*{re.escape(key)}\s+[^\n]*$')
    line = f'\t\t\t{key} {value}'
    if pat.search(block):
        return pat.sub(line, block, count=1)
    return re.sub(
        r'(php_ini\s*\{)\s*',
        rf'\1\n{line}\n',
        block,
        count=1,
    )

m = re.search(r'(frankenphp\s*\{.*?php_ini\s*\{.*?\n\s*\})', text, re.S)
if not m:
    inj = (
        "\tfrankenphp {\n"
        "\t\tphp_ini {\n"
        f"\t\t\tupload_max_filesize {upload}\n"
        f"\t\t\tpost_max_size {post}\n"
        "\t\t}\n"
        "\t}\n\n"
    )
    text2, n = re.subn(r'(^\{\s*\n)', r'\1' + inj, text, count=1, flags=re.M)
    text = inj + text if n == 0 else text2
else:
    block = m.group(1)
    block = upsert_ini(block, "upload_max_filesize", upload)
    block = upsert_ini(block, "post_max_size", post)
    text = text[:m.start(1)] + block + text[m.end(1):]

body_pat = re.compile(r'(request_body\s*\{\s*max_size\s+)(\d+)(MB)')
post_mb = post.rstrip("Mm")
if body_pat.search(text):
    text = body_pat.sub(rf'\g<1>{post_mb}\3', text)

open(path, "w", encoding="utf-8").write(text)
print("updated", path)
PY
)
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "+ python3 … patch $CADDYFILE …"
  else
    python3 -c "$PATCH_PY" "$CADDYFILE" "$UP_NORM" "$POST_NORM"
  fi
elif [[ -n "$UPLOAD_SIZE" && "$IS_FRANKEN" -eq 0 ]]; then
  UP_NORM="$(normalize_size "$UPLOAD_SIZE")"
  echo "==> 非 FrankenPHP：请手动在 php.ini 设置 upload_max_filesize=${UP_NORM:-20M}"
fi

# ---- 自检（重启前，反映当前进程；重启后网页会再刷）----
echo "==> 自检（当前 CLI）"
probe_php() {
  local code='$g=extension_loaded("gd");$i=extension_loaded("imagick");$w=function_exists("imagewebp");$a=function_exists("imageavif");$h=false;if(class_exists("Imagick")){try{$im=new Imagick();$h=!empty($im->queryFormats("HEIC"))||!empty($im->queryFormats("HEIF"));$w=$w||!empty($im->queryFormats("WEBP"));$a=$a||!empty($im->queryFormats("AVIF"));$im->clear();}catch(Throwable $e){}}echo "gd=".($g?"yes":"no")." imagick=".($i?"yes":"no")." webp=".($w?"yes":"no")." avif=".($a?"yes":"no")." heic=".($h?"yes":"no")." upload=".ini_get("upload_max_filesize")." post=".ini_get("post_max_size")."\n";'
  if command -v frankenphp >/dev/null 2>&1; then
    echo -n "frankenphp: "
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
  # ZTS 软链后，新开的 php-cli 应能加载；当前 worker 需重启后才生效
  probe_php
fi

# ---- 重启 ----
do_restart() {
  if [[ "$IS_FRANKEN" -eq 1 ]]; then
    if [[ -f "$CADDYFILE" ]]; then
      frankenphp validate --config "$CADDYFILE"
    fi
    systemctl restart frankenphp
  else
    if systemctl list-unit-files "php${PHP_VER}-fpm.service" 2>/dev/null | grep -q php; then
      systemctl restart "php${PHP_VER}-fpm"
    else
      echo "未找到 php${PHP_VER}-fpm 服务，请手动重启 PHP。" >&2
      return 1
    fi
  fi
}

if [[ "$NO_RESTART" -eq 1 ]]; then
  echo "==> 跳过重启（--no-restart）"
elif [[ "$DRY_RUN" -eq 1 ]]; then
  if [[ "$DEFER_RESTART" -eq 1 ]]; then
    echo "+ (sleep 2; restart) &   # deferred"
  else
    echo "+ systemctl restart …"
  fi
elif [[ "$DEFER_RESTART" -eq 1 ]]; then
  echo "==> 延迟 2 秒后重启服务（便于网页 API 先返回）"
  nohup bash -c 'sleep 2; '"$(declare -f do_restart)"'; do_restart' >/tmp/litepic-enable-ext-restart.log 2>&1 &
  disown || true
else
  echo "==> 重启服务"
  do_restart
fi

echo "==> 完成。设置页点「刷新检测」可更新徽章。"
if [[ "$WEB_RUN" -eq 1 ]]; then
  echo "WEB_RESTART_SCHEDULED=1"
fi

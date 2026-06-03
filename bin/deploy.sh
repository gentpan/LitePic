#!/usr/bin/env bash
#
# LitePic deploy script
# =====================
#
# 把当前工作树推到生产服务器。流程:
#   1. (可选) 本地重建 CSS bundle
#   2. 服务器端打快照(tar + sqlite 复制) → /root/litepic-backups/
#   3. 维护模式 on (touch .maintenance)
#   4. tar 流式同步代码,排除 data/ uploads/ logs/ .env / .user.ini /
#      .htaccess / .git / node_modules / 已知 macOS 元数据垃圾 (._*, .DS_Store)
#   5. chown -R www:www
#   6. 触发迁移(让 bootstrap.php 跑 Migration::run)
#   7. 维护模式 off
#   8. HTTP 自检
#   9. 打印一键回滚命令
#
# 用法:
#   bin/deploy.sh                  正常部署
#   bin/deploy.sh --dry-run        只打印 tar 包内文件清单,不推
#   bin/deploy.sh --skip-build     跳过 npm run build:css
#   bin/deploy.sh --skip-snapshot  跳过服务器端快照(已确认 DB 没事时省 5-15s)
#
# 配置(全部 env var,有默认值):
#   LITEPIC_HOST     远端主机 (默认 170.168.6.148)
#   LITEPIC_USER     SSH 用户 (默认 root)
#   LITEPIC_KEY      SSH key 路径 (默认 ~/.ssh/gentpan.pem)
#   LITEPIC_REMOTE   远端站点目录 (默认 /www/wwwroot/126.uz)
#   LITEPIC_OWNER    远端 chown 目标 (默认 www:www)
#   LITEPIC_URL      自检 URL (默认 https://126.uz)

set -euo pipefail

HOST="${LITEPIC_HOST:-170.168.6.148}"
USER="${LITEPIC_USER:-root}"
KEY="${LITEPIC_KEY:-$HOME/.ssh/gentpan.pem}"
REMOTE="${LITEPIC_REMOTE:-/www/wwwroot/126.uz}"
OWNER="${LITEPIC_OWNER:-www:www}"
URL="${LITEPIC_URL:-https://126.uz}"

DRY_RUN=0
SKIP_BUILD=0
SKIP_SNAPSHOT=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)       DRY_RUN=1 ;;
        --skip-build)    SKIP_BUILD=1 ;;
        --skip-snapshot) SKIP_SNAPSHOT=1 ;;
        -h|--help)       sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "unknown flag: $arg (try --help)"; exit 2 ;;
    esac
done

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SSH() { ssh -i "$KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${USER}@${HOST}" "$@"; }

# tar excludes — 同时挡掉 macOS 元数据 (._*, .DS_Store) 和站点状态文件
TAR_EXCLUDES=(
    --exclude='./._*'
    --exclude='*.DS_Store'
    --exclude='._.DS_Store'
    --exclude='./data'
    --exclude='./uploads'
    --exclude='./logs'
    --exclude='./.env'
    --exclude='./.env.example'
    --exclude='./.user.ini'
    --exclude='./.htaccess'
    --exclude='./.git'
    --exclude='./node_modules'
    --exclude='./bin/deploy.sh'
    --exclude='./static/images/background.jpg'
    --exclude='./.maintenance'
    --exclude='*.pem'
    --exclude='./backup'
    --exclude='./release'
)

ts() { date +%Y%m%d-%H%M%S; }
say() { printf '\n==> %s\n' "$*"; }

if [[ $SKIP_BUILD -eq 0 ]]; then
    say "本地 CSS + JS bundle 重建"
    npm run build:css >/dev/null
    cp assets/css/main.css assets/css/main.min.css
    # main.min.js 从 main.js 真压(esbuild)—— 防止两者脱节,生产跑旧 JS
    npm run build:js >/dev/null 2>&1
    echo "  ✓ rebuilt + mirrored (css + js)"
fi

if [[ $DRY_RUN -eq 1 ]]; then
    say "DRY RUN — 列出会被打包的文件(前 100 行)"
    COPYFILE_DISABLE=1 tar -c "${TAR_EXCLUDES[@]}" -f - . | tar -tf - | head -100
    echo ""
    echo "总文件数:"
    COPYFILE_DISABLE=1 tar -c "${TAR_EXCLUDES[@]}" -f - . | tar -tf - | wc -l
    exit 0
fi

say "测试 SSH 连通"
SSH 'echo "  ✓ connected as $(whoami)@$(hostname) ($(uname -s))"'

TS=$(ts)
if [[ $SKIP_SNAPSHOT -eq 0 ]]; then
    say "服务器端快照"
    SSH "set -e
        cd ${REMOTE}
        mkdir -p /root/litepic-backups
        tar --exclude='./node_modules' --exclude='./logs/*.log' \
            -czf /root/litepic-backups/litepic-bak-${TS}.tar.gz .
        if [[ -f data/litepic.sqlite ]]; then
            cp data/litepic.sqlite /root/litepic-backups/litepic-db-${TS}.sqlite
        fi
        ls -lh /root/litepic-backups/litepic-bak-${TS}.tar.gz \
               /root/litepic-backups/litepic-db-${TS}.sqlite 2>/dev/null | sed 's/^/  /'"
fi

say "维护模式 ON"
SSH "touch ${REMOTE}/.maintenance && ls -la ${REMOTE}/.maintenance | sed 's/^/  /'"

# 服务器解包前还要兜底删一次 ._ 文件 — 万一上一个版本残留
say "清理服务器残留 macOS 元数据(若有)"
SSH "find ${REMOTE} \( -name '._*' -o -name '.DS_Store' \) -type f -delete 2>/dev/null; echo '  ✓ cleaned'"

say "tar 流式同步代码"
echo "  (从 ${ROOT} 推到 ${USER}@${HOST}:${REMOTE})"
# chown 必须跳过 BT 面板 / cPanel 这类控制面板锁住的 root-owned 文件
# (.user.ini、.htaccess、.env)—— 它们要么有 immutable 位,要么有面板的
# kernel-level 保护。`find -prune` 把这些路径完全排除在 chown 之外,
# 剩下的 tree 一次性 chown -R 给 www。
COPYFILE_DISABLE=1 tar -cz "${TAR_EXCLUDES[@]}" -f - . | \
    SSH "cd ${REMOTE} && tar -xz 2>/dev/null && \
         find . \( -path './.git' -o -name '.user.ini' -o -name '.htaccess' -o -name '.env' \) -prune \
             -o -exec chown ${OWNER} {} + 2>/dev/null && \
         echo '  ✓ extracted + chowned (.user.ini/.htaccess/.env 被面板保护,跳过)'"

say "运行迁移(触发 bootstrap.php)"
SSH "cd ${REMOTE} && sudo -u www php -r 'require __DIR__.\"/bootstrap.php\"; echo \"  ✓ bootstrap OK\n\";'"

say "维护模式 OFF"
SSH "rm -f ${REMOTE}/.maintenance && echo '  ✓ .maintenance removed'"

say "HTTP 自检"
http_code=$(curl -sk -o /dev/null -w "%{http_code}" "${URL}/" || echo "000")
http_time=$(curl -sk -o /dev/null -w "%{time_total}" "${URL}/" || echo "?")
echo "  ${URL}/  →  HTTP ${http_code} in ${http_time}s"
ver=$(curl -sk "${URL}/" | grep -oE 'v3\.3\.[0-9]+' | head -1 || true)
echo "  页脚版本: ${ver:-未抓到}"

say "完成"
echo ""
echo "回滚命令(如需):"
echo "  ssh -i ${KEY} ${USER}@${HOST} '"
echo "    cd ${REMOTE} && touch .maintenance && \\"
echo "    tar -xzf /root/litepic-backups/litepic-bak-${TS}.tar.gz && \\"
echo "    cp /root/litepic-backups/litepic-db-${TS}.sqlite data/litepic.sqlite && \\"
echo "    chown -R ${OWNER} . && rm -f .maintenance'"

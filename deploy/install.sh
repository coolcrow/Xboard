#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# AIBolt 一行命令安装（v2 极简模式）
#
# 客户只需提供 2 个信息：
#   1. 域名（有=自动 HTTPS，无=IP 直达）
#   2. 管理员邮箱
# 其余全部自动。
#
# 用法:
#   curl -fsSL <url> | sudo bash
#   curl -fsSL <url> | sudo bash -s -- --domain panel.example.com --email admin@example.com
#   curl -fsSL <url> | sudo bash -s -- --mirror https://mirror.ghproxy.com
# ═══════════════════════════════════════════════════════════════
set -euo pipefail

info()  { echo -e "\e[32m[AIBolt]\e[0m $*"; }
warn()  { echo -e "\e[33m[⚠]\e[0m $*"; }
fail()  { echo -e "\e[31m[✗]\e[0m $*"; exit 1; }

INSTALL_DIR="/opt/aibolt"
IMAGE="ghcr.io/coolcrow/xboard:bundle"
DOMAIN=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
PORT="7001"
MIRROR=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)   DOMAIN="$2"; shift 2 ;;
    --email)    ADMIN_EMAIL="$2"; shift 2 ;;
    --password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --port)     PORT="$2"; shift 2 ;;
    --dir)      INSTALL_DIR="$2"; shift 2 ;;
    --mirror)   MIRROR="$2"; shift 2 ;;
    --image)    IMAGE="$2"; shift 2 ;;
    *) fail "未知参数: $1" ;;
  esac
done

[[ $EUID -eq 0 ]] || fail "请以 root 运行"
[[ "$(uname -s)" == "Linux" ]] || fail "仅支持 Linux"

command -v curl >/dev/null 2>&1 || {
  (apt-get update -qq && apt-get install -y -qq curl ca-certificates >/dev/null 2>&1) || \
  (yum install -y curl >/dev/null 2>&1) || fail "curl 未安装且自动安装失败"
}

if ! command -v docker >/dev/null 2>&1; then
  info "安装 Docker..."
  curl -fsSL https://get.docker.com | sh >/dev/null 2>&1 || fail "Docker 安装失败"
  systemctl enable --now docker >/dev/null 2>&1
fi
COMPOSE="docker compose"
$COMPOSE version >/dev/null 2>&1 || COMPOSE="docker-compose"
$COMPOSE version >/dev/null 2>&1 || fail "Docker Compose 不可用"

if [ -z "$ADMIN_EMAIL" ]; then
  echo ""
  read -p "管理员邮箱（用于登录面板）: " ADMIN_EMAIL < /dev/tty
  [ -n "$ADMIN_EMAIL" ] || fail "邮箱不能为空"
fi

if [ -z "$DOMAIN" ]; then
  echo ""
  echo "域名（可选——填写后自动配置 HTTPS，直接回车则用 IP 访问）"
  read -p "域名: " DOMAIN < /dev/tty
fi

if [ -z "$ADMIN_PASSWORD" ]; then
  ADMIN_PASSWORD="AIBolt-$(openssl rand -hex 8 2>/dev/null || head -c16 /dev/urandom | xxd -p)"
fi

if [ -n "$DOMAIN" ]; then
  BIND="127.0.0.1:${PORT}:7001"
  APP_URL="https://${DOMAIN}"
else
  BIND="0.0.0.0:${PORT}:7001"
  APP_URL="http://$(curl -s -m 5 ifconfig.me 2>/dev/null || echo 'localhost')"
fi

PULL="$IMAGE"
[ -n "$MIRROR" ] && PULL="$(echo "$MIRROR" | sed 's|^https\?://||;s|/$||')/$IMAGE"

info "拉取镜像（约 1-3 分钟）..."
if ! docker pull "$PULL" >/dev/null 2>&1; then
  if [ "$PULL" != "$IMAGE" ]; then
    warn "镜像加速失败，尝试直连..."
    docker pull "$IMAGE" >/dev/null 2>&1 || fail "镜像拉取失败"
    PULL="$IMAGE"
  else
    for M in "mirror.ghproxy.com" "ghcr.nju.edu.cn"; do
      warn "直连失败，尝试 ${M}..."
      docker pull "${M}/${IMAGE}" >/dev/null 2>&1 && { PULL="${M}/${IMAGE}"; break; }
    done
    [ "$PULL" != "$IMAGE" ] || docker pull "$IMAGE" >/dev/null 2>&1 || fail "所有镜像源均不可达"
  fi
fi
info "镜像就绪"

info "部署面板..."
mkdir -p "$INSTALL_DIR"/{.docker/.data,storage/logs,storage/theme,caddy}
cd "$INSTALL_DIR"

if [ ! -s .env ]; then
  cat > .env <<EOF
APP_NAME=AIBolt
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')
APP_DEBUG=false
APP_URL=${APP_URL}
DB_CONNECTION=sqlite
REDIS_HOST=/data/redis.sock
REDIS_PORT=0
EOF
  chmod 600 .env
fi

if [ ! -s compose.yaml ]; then
  if [ -n "$DOMAIN" ]; then
    cat > Caddyfile <<EOF
${DOMAIN} {
    reverse_proxy xboard:7001
}
EOF
    cat > compose.yaml <<EOF
services:
  xboard:
    image: ${PULL}
    container_name: aibolt-panel
    restart: unless-stopped
    ports: ["${BIND}"]
    volumes:
      - ./.env:/www/.env:z
      - ./.docker/.data:/www/.docker/.data:z
      - ./storage/logs:/www/storage/logs:z
      - ./storage/theme:/www/storage/theme:z
    environment:
      - OCTANE_WORKERS=2
      - OCTANE_MAX_REQUESTS=10000
      - ENABLE_SQLITE=true
      - ENABLE_REDIS=true
      - ADMIN_ACCOUNT=${ADMIN_EMAIL}
      - ADMIN_PASSWORD=${ADMIN_PASSWORD}

  caddy:
    image: caddy:2-alpine
    container_name: aibolt-caddy
    restart: unless-stopped
    ports: ["80:80", "443:443"]
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./caddy/data:/data
      - ./caddy/config:/config
    depends_on: [xboard]
EOF
  else
    cat > compose.yaml <<EOF
services:
  xboard:
    image: ${PULL}
    container_name: aibolt-panel
    restart: unless-stopped
    ports: ["${BIND}"]
    volumes:
      - ./.env:/www/.env:z
      - ./.docker/.data:/www/.docker/.data:z
      - ./storage/logs:/www/storage/logs:z
      - ./storage/theme:/www/storage/theme:z
    environment:
      - OCTANE_WORKERS=2
      - OCTANE_MAX_REQUESTS=10000
      - ENABLE_SQLITE=true
      - ENABLE_REDIS=true
      - ADMIN_ACCOUNT=${ADMIN_EMAIL}
      - ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
  fi
  chmod 600 compose.yaml
fi

$COMPOSE up -d >/dev/null 2>&1

info "初始化面板（数据库/管理员/安全基线）..."
RETRY=0
while [ $RETRY -lt 30 ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" != "000" ] && break
  docker inspect aibolt-panel --format '{{.State.Running}}' 2>/dev/null | grep -q "false" && fail "容器已退出: docker logs aibolt-panel"
  RETRY=$((RETRY+1)); sleep 2
done

INSTALLED=$(docker exec aibolt-panel sh -c 'grep "^INSTALLED=" /www/.env 2>/dev/null | cut -d= -f2' || echo "")
if [ "$INSTALLED" != "true" ]; then
  docker exec aibolt-panel php /www/artisan xboard:install >/dev/null 2>&1 || {
    docker exec aibolt-panel php /www/artisan config:clear >/dev/null 2>&1
    fail "安装失败: docker logs aibolt-panel"
  }
  sed -i '/ADMIN_ACCOUNT/d;/ADMIN_PASSWORD/d' compose.yaml 2>/dev/null
fi

RETRY=0
while [ $RETRY -lt 15 ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && break
  RETRY=$((RETRY+1)); sleep 2
done
[ "$HTTP" = "200" ] || warn "面板返回 ${HTTP}"

docker exec aibolt-panel php artisan tinker --execute="admin_setting(['app_url'=>'${APP_URL}']);" >/dev/null 2>&1

if command -v crontab >/dev/null 2>&1; then
  CRON_TMP=$(mktemp)
  (crontab -l 2>/dev/null | grep -v "aibolt\|${INSTALL_DIR}" || true) > "$CRON_TMP"
  echo "* * * * * docker exec aibolt-panel php /www/artisan schedule:run >> ${INSTALL_DIR}/schedule.log 2>&1" >> "$CRON_TMP"
  echo "30 4 * * * docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite \".backup /tmp/bk\"' && docker cp aibolt-panel:/tmp/bk ${INSTALL_DIR}/bk-\$(date +\%Y\%m\%d).sqlite && gzip -f ${INSTALL_DIR}/bk-*.sqlite && ls -1t ${INSTALL_DIR}/bk-*.gz | tail -n +15 | xargs -r rm -f" >> "$CRON_TMP"
  echo "*/5 * * * * curl -s -o /dev/null -w \"\%{http_code}\" -m 8 http://127.0.0.1:${PORT}/api/v1/guest/comm/config | grep -q 200 || (cd ${INSTALL_DIR} && docker compose restart xboard) >> ${INSTALL_DIR}/health.log 2>&1" >> "$CRON_TMP"
  crontab "$CRON_TMP" && rm -f "$CRON_TMP"
fi

echo ""
echo "═══════════════════════════════════════════════"
echo "  ✅ AIBolt 安装完成"
echo "═══════════════════════════════════════════════"
echo ""
echo "  面板地址:  ${APP_URL}"
echo "  管理员:    ${ADMIN_EMAIL}"
echo "  密码:      ${ADMIN_PASSWORD}"
[ -n "$DOMAIN" ] && echo "  HTTPS:     自动配置（Let's Encrypt）"
echo "  安装目录:  ${INSTALL_DIR}"
echo ""
echo "  下一步: 登录面板 → 机器管理 → 新建机器"
echo "         → 复制节点安装命令到节点服务器执行"
echo ""
echo "  升级: cd ${INSTALL_DIR} && bash upgrade.sh"
echo "  卸载: cd ${INSTALL_DIR} && bash uninstall.sh"
echo "═══════════════════════════════════════════════"

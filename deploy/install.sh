#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# AIBolt 面板安装 v3
# 客户提供：域名（可选）+ 管理员邮箱 → 其余全自动
#
# curl -fsSL <url> | sudo bash
# curl -fsSL <url> | sudo bash -s -- --domain panel.example.com --email admin@x.com
# ═══════════════════════════════════════════════════════════════
set -euo pipefail

info()  { echo -e "\e[32m[AIBolt]\e[0m $*"; }
warn()  { echo -e "\e[33m[⚠]\e[0m $*"; }
fail()  { echo -e "\e[31m[✗]\e[0m $*"; exit 1; }

INSTALL_DIR="/opt/aibolt"
IMAGE="ghcr.io/coolcrow/xboard:bundle"
DOMAIN=""; ADMIN_EMAIL=""; ADMIN_PASSWORD=""; PORT="7001"; MIRROR=""
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" && pwd)"

# ── 参数 ──
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)   DOMAIN="$2"; shift 2 ;;
    --email)    ADMIN_EMAIL="$2"; shift 2 ;;
    --password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --port)     PORT="$2"
                [[ "$PORT" =~ ^[0-9]+$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || fail "端口必须为 1-65535"
                shift 2 ;;
    --dir)      INSTALL_DIR="$2"; shift 2 ;;
    --mirror)   MIRROR="$2"; shift 2 ;;
    --image)    IMAGE="$2"; shift 2 ;;
    -h|--help)
      cat <<'USAGE'
AIBolt 面板安装

选项:
  --domain DOMAIN     域名（可选——有则自动 HTTPS，无则 IP 直达）
  --email EMAIL       管理员邮箱（必填）
  --password PASS     管理员密码 ≥8 位（省略则自动生成）
  --port N            面板端口（默认 7001）
  --dir PATH          安装目录（默认 /opt/aibolt）
  --mirror URL        镜像加速（如 https://mirror.ghproxy.com）
  --image IMAGE       指定镜像
  -h, --help          帮助
USAGE
      exit 0 ;;
    *) fail "未知参数: $1（--help 查看）" ;;
  esac
done

[[ $EUID -eq 0 ]] || fail "请以 root 运行"
[[ "$(uname -s)" == "Linux" ]] || fail "仅支持 Linux"

# ── curl 前置 ──
command -v curl >/dev/null 2>&1 || {
  (apt-get update -qq && apt-get install -y -qq curl ca-certificates >/dev/null 2>&1) || \
  (yum install -y curl 2>/dev/null || dnf install -y curl) >/dev/null 2>&1 || \
  fail "curl 未安装且自动安装失败"
}

# ── 磁盘预检 ──
AVAIL_MB=$(df -BM --output=avail . 2>/dev/null | tail -1 | tr -dc '0-9')
[ -n "$AVAIL_MB" ] && [ "$AVAIL_MB" -lt 2048 ] && warn "磁盘 ${AVAIL_MB}MB（建议 ≥2GB）"

# ── Docker ──
if ! command -v docker >/dev/null 2>&1; then
  info "安装 Docker..."
  curl -fsSL https://get.docker.com | sh >/dev/null 2>&1 || fail "Docker 安装失败（https://get.docker.com）"
  systemctl enable --now docker >/dev/null 2>&1
fi
COMPOSE="docker compose"
$COMPOSE version >/dev/null 2>&1 || COMPOSE="docker-compose"
$COMPOSE version >/dev/null 2>&1 || {
  apt-get install -y docker-compose-plugin >/dev/null 2>&1 || \
  yum install -y docker-compose-plugin >/dev/null 2>&1 || \
  dnf install -y docker-compose-plugin >/dev/null 2>&1 || \
  fail "Docker Compose 不可用"
  COMPOSE="docker compose"
}

# ── 收集信息 ──
if [ -z "$ADMIN_EMAIL" ]; then
  read -p "管理员邮箱: " ADMIN_EMAIL < /dev/tty
  [ -n "$ADMIN_EMAIL" ] || fail "邮箱不能为空"
fi
if [ -z "$DOMAIN" ]; then
  echo ""
  echo "域名（可选——填写后自动配置 HTTPS，直接回车则用 IP 访问）"
  read -p "域名: " DOMAIN < /dev/tty
fi
if [ -n "$ADMIN_PASSWORD" ] && [ ${#ADMIN_PASSWORD} -lt 8 ]; then
  fail "密码至少 8 位"
fi

# ── SMTP（可选——不配置则关闭邮箱验证，用户免验证码直接注册） ──
SMTP_HOST=""; SMTP_PORT=""; SMTP_USER=""; SMTP_PASS=""; SMTP_FROM=""
if [ "$FIRST_INSTALL_CHECK" != "skip" ]; then
  echo ""
  echo "SMTP 邮件服务器（可选——配置后新用户注册需邮箱验证码，直接回车跳过）"
  read -p "SMTP 服务器地址（如 smtp.gmail.com，回车跳过）: " SMTP_HOST < /dev/tty
  if [ -n "$SMTP_HOST" ]; then
    read -p "SMTP 端口（默认 465）: " SMTP_PORT < /dev/tty
    SMTP_PORT="${SMTP_PORT:-465}"
    read -p "SMTP 用户名: " SMTP_USER < /dev/tty
    read -p "SMTP 密码: " SMTP_PASS < /dev/tty
    read -p "发件人地址（默认同用户名）: " SMTP_FROM < /dev/tty
    SMTP_FROM="${SMTP_FROM:-$SMTP_USER}"
  fi
fi

# ── 模式决策 ──
if [ -n "$DOMAIN" ]; then
  BIND="127.0.0.1:${PORT}:7001"
  APP_URL="https://${DOMAIN}"
  # DNS 预检
  SERVER_IP=$(curl -s -m 5 api.ipify.org 2>/dev/null || curl -s -m 5 ip.sb 2>/dev/null || echo "")
  DOMAIN_IP=$(dig +short "$DOMAIN" 2>/dev/null | head -1 || echo "")
  if [ -n "$SERVER_IP" ] && [ -n "$DOMAIN_IP" ] && [ "$DOMAIN_IP" != "$SERVER_IP" ]; then
    warn "域名 ${DOMAIN} 解析到 ${DOMAIN_IP}，本机 IP ${SERVER_IP}——DNS 未指向本机时 HTTPS 将失败"
  fi
  # 端口占用预检（80/443）
  for P in 80 443; do
    ss -tln 2>/dev/null | grep -q ":${P} " && warn "端口 ${P} 已被占用（可能已有 nginx）——HTTPS 可能失败"
  done
else
  BIND="0.0.0.0:${PORT}:7001"
  # 多源探测公网 IP
  PUB_IP=""
  for SVC in api.ipify.org ip.sb ifconfig.me; do
    PUB_IP=$(curl -s -m 5 "$SVC" 2>/dev/null | grep -oP '^\d+\.\d+\.\d+\.\d+$' | head -1)
    [ -n "$PUB_IP" ] && break
  done
  if [ -z "$PUB_IP" ]; then
    PUB_IP=$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[\d.]+' | head -1)
  fi
  [ -z "$PUB_IP" ] && PUB_IP="localhost" && warn "无法探测公网 IP——面板地址显示为 localhost，请手动确认"
  APP_URL="http://${PUB_IP}:${PORT}"
fi

# 端口占用预检（面板端口）
ss -tln 2>/dev/null | grep -q ":${PORT} " && warn "端口 ${PORT} 已被占用"

# ── 密码生成 ──
GENERATED_PASSWORD=false
if [ -z "$ADMIN_PASSWORD" ]; then
  ADMIN_PASSWORD="Ab-$(openssl rand -base64 12 | tr -d '/+=' | head -c 14)"
  GENERATED_PASSWORD=true
fi

# ── 镜像拉取（含离线 + 自动探测） ──
info "拉取镜像..."

# 离线模式：本地有 image-bundle.tar[.gz] 时直接 docker load
LOCAL_TAR="${SCRIPT_DIR}/image-bundle.tar"
[ ! -f "$LOCAL_TAR" ] && [ -f "${LOCAL_TAR}.gz" ] && LOCAL_TAR="${LOCAL_TAR}.gz"
if [ -f "$LOCAL_TAR" ]; then
  info "离线镜像: ${LOCAL_TAR}"
  docker load -i "$LOCAL_TAR" >/dev/null 2>&1 || fail "镜像导入失败"
  PULL=$(docker images --format "{{.Repository}}:{{.Tag}}" | grep "xboard:bundle" | head -1 || docker images --format "{{.Repository}}:{{.Tag}}" | grep xboard | head -1)
  [ -n "$PULL" ] || fail "导入后未找到镜像"
else
  PULL="$IMAGE"
  [ -n "$MIRROR" ] && PULL="$(echo "$MIRROR" | sed 's|^https\?://||;s|/$||')/$IMAGE"
  if ! docker pull "$PULL" >/dev/null 2>&1; then
    if [ "$PULL" != "$IMAGE" ]; then
      docker pull "$IMAGE" >/dev/null 2>&1 || fail "镜像拉取失败（直连+镜像均不可达）"
      PULL="$IMAGE"
    else
      for M in "mirror.ghproxy.com" "ghcr.nju.edu.cn"; do
        docker pull "${M}/${IMAGE}" >/dev/null 2>&1 && { PULL="${M}/${IMAGE}"; break; }
      done
      [ "$PULL" != "$IMAGE" ] || docker pull "$IMAGE" >/dev/null 2>&1 || fail "所有镜像源均不可达"
    fi
  fi
fi

# Caddy 镜像预拉取（Docker Hub 在中国不通时自动探测）
if [ -n "$DOMAIN" ]; then
  info "拉取 Caddy（自动 HTTPS）..."
  docker pull caddy:2-alpine >/dev/null 2>&1 || \
  docker pull docker.m.daocloud.io/library/caddy:2-alpine >/dev/null 2>&1 && \
  docker tag docker.m.daocloud.io/library/caddy:2-alpine caddy:2-alpine || \
  warn "Caddy 镜像拉取失败——HTTPS 可能不可用"
fi
info "镜像就绪"

# ── 部署 ──
info "部署面板..."
mkdir -p "$INSTALL_DIR"/{.docker/.data,storage/logs,storage/theme,caddy/data,caddy/config}
cd "$INSTALL_DIR"

# 首次 vs 重装
FIRST_INSTALL=true
[ -s compose.yaml ] && FIRST_INSTALL=false
FIRST_INSTALL_CHECK="$([ "$FIRST_INSTALL" = true ] && echo "ask" || echo "skip")"

if [ "$FIRST_INSTALL" = true ]; then
  cat > .env <<EOF
APP_NAME=AIBolt
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')
APP_DEBUG=false
APP_URL=${APP_URL}
DB_CONNECTION=sqlite
REDIS_HOST=/data/redis.sock
REDIS_PORT=0
MAIL_HOST=${SMTP_HOST}
MAIL_PORT=${SMTP_PORT}
MAIL_USERNAME=${SMTP_USER}
MAIL_PASSWORD=${SMTP_PASS}
MAIL_FROM_ADDRESS=${SMTP_FROM}
EOF
  # 版本锁定：记录安装时的镜像 digest（升级时比对）
echo "XBOARD_IMAGE_DIGEST=$(docker inspect ${PULL} --format '{{.Id}}' 2>/dev/null | head -c 71)" >> .env
chmod 600 .env

  if [ -n "$DOMAIN" ]; then
    echo "${DOMAIN} { reverse_proxy xboard:7001 }" > Caddyfile
  fi

  ENV_BLOCK="      - OCTANE_WORKERS=2
      - OCTANE_MAX_REQUESTS=10000
      - ENABLE_SQLITE=true
      - ENABLE_REDIS=true
      - ADMIN_ACCOUNT=${ADMIN_EMAIL}
      - ADMIN_PASSWORD=${ADMIN_PASSWORD}"

  HEALTH_BLOCK="    healthcheck:
      test: [\"CMD\", \"curl\", \"-f\", \"-m\", \"8\", \"http://127.0.0.1:7001/api/v1/guest/comm/config\"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s"

  if [ -n "$DOMAIN" ]; then
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
${ENV_BLOCK}
${HEALTH_BLOCK}

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
${ENV_BLOCK}
${HEALTH_BLOCK}
EOF
  fi
  chmod 600 compose.yaml
else
  info "已有安装（跳过配置生成——如需改域名/端口请删除 compose.yaml 后重跑）"
fi

# ── 启动（错误可见） ──
if ! $COMPOSE up -d 2>&1 | tail -5; then
  fail "容器启动失败——常见原因: 端口被占用 / 镜像不可达。诊断: $COMPOSE logs"
fi

# ── 等启动 + 初始化 ──
info "等待面板进程..."
RETRY=0; MAX=30; HTTP=000
while [ $RETRY -lt $MAX ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" != "000" ] && break
  docker inspect aibolt-panel --format '{{.State.Running}}' 2>/dev/null | grep -q "false" && fail "容器已退出: docker logs aibolt-panel"
  RETRY=$((RETRY+1)); sleep 2
done
[ "$HTTP" != "000" ] || fail "面板进程未启动（${MAX}×2s）: docker logs aibolt-panel"

# 初始化
INSTALLED=$(docker exec aibolt-panel sh -c 'grep "^INSTALLED=" /www/.env 2>/dev/null | cut -d= -f2' || echo "")
if [ "$INSTALLED" != "true" ]; then
  info "初始化（数据库/管理员/安全基线）..."
  INSTALL_OUTPUT=$(docker exec aibolt-panel php /www/artisan xboard:install 2>&1) || {
    docker exec aibolt-panel php /www/artisan config:clear >/dev/null 2>&1
    echo "$INSTALL_OUTPUT" | tail -8
    fail "安装失败——诊断: docker exec aibolt-panel php /www/artisan xboard:install"
  }
  echo "$INSTALL_OUTPUT" | grep -E "管理员|密码|一切就绪" | head -3
  # 清除 compose 中的临时凭据 + 重建容器使生效
  sed -i '/ADMIN_ACCOUNT/d;/ADMIN_PASSWORD/d' compose.yaml
  $COMPOSE up -d >/dev/null 2>&1
fi

# 健康验证（硬性）
RETRY=0; MAX=15; HTTP=000
while [ $RETRY -lt $MAX ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && break
  RETRY=$((RETRY+1)); sleep 2
done
[ "$HTTP" = "200" ] || fail "面板未就绪（HTTP ${HHTTP}）: docker logs aibolt-panel"

# Caddy 验证（域名模式）
if [ -n "$DOMAIN" ] && [ "$FIRST_INSTALL" = true ]; then
  sleep 5
  docker inspect aibolt-caddy --format '{{.State.Running}}' 2>/dev/null | grep -q "true" || warn "Caddy 容器未运行——HTTPS 可能失败"
fi

# app_url + SMTP 写入
docker exec aibolt-panel php /www/artisan tinker --execute="admin_setting(['app_url'=>'${APP_URL}']);" >/dev/null 2>&1
if [ -n "$SMTP_HOST" ]; then
  docker exec aibolt-panel php /www/artisan tinker --execute="
    admin_setting([
      'email_host' => '${SMTP_HOST}',
      'email_port' => ${SMTP_PORT},
      'email_username' => '${SMTP_USER}',
      'email_password' => '${SMTP_PASS}',
      'email_from_address' => '${SMTP_FROM}',
      'email_verify' => 1,
    ]);
    echo 'SMTP_OK';
  " >/dev/null 2>&1 && info "SMTP 已配置（邮箱验证已开启）"
else
  docker exec aibolt-panel php /www/artisan tinker --execute="admin_setting(['email_verify' => 0]);" >/dev/null 2>&1
  info "SMTP 未配置——邮箱验证已关闭（用户直接注册，后续可在后台开启）"
fi

# ── Cron ──
if command -v crontab >/dev/null 2>&1; then
  CRON_TMP=$(mktemp)
  (crontab -l 2>/dev/null | grep -v "aibolt\|${INSTALL_DIR}" || true) > "$CRON_TMP"
  echo "* * * * * docker exec aibolt-panel php /www/artisan schedule:run >> ${INSTALL_DIR}/schedule.log 2>&1" >> "$CRON_TMP"
  echo "30 4 * * * docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite \".backup /tmp/bk\"' && docker cp aibolt-panel:/tmp/bk ${INSTALL_DIR}/bk-\$(date +\%Y\%m\%d).sqlite && gzip -f ${INSTALL_DIR}/bk-*.sqlite 2>/dev/null; ls -1t ${INSTALL_DIR}/bk-*.gz 2>/dev/null | tail -n +15 | xargs -r rm -f" >> "$CRON_TMP"
  echo "*/5 * * * * curl -s -o /dev/null -w \"\%{http_code}\" -m 8 http://127.0.0.1:${PORT}/api/v1/guest/comm/config | grep -q 200 || (cd ${INSTALL_DIR} && ${COMPOSE} restart xboard) >> ${INSTALL_DIR}/health.log 2>&1" >> "$CRON_TMP"
  crontab "$CRON_TMP" && rm -f "$CRON_TMP"
  info "定时任务已注册"
else
  warn "crontab 不可用——无自动调度/备份/自愈（请安装 cron）"
fi

# ── 部署运维脚本 ──
for SCRIPT in upgrade.sh uninstall.sh restore.sh; do
  if [ -f "${SCRIPT_DIR}/${SCRIPT}" ]; then
    cp "${SCRIPT_DIR}/${SCRIPT}" "${INSTALL_DIR}/${SCRIPT}"
  fi
done

# ── 输出 ──
echo ""
echo "═══════════════════════════════════════════════"
echo "  ✅ AIBolt 安装完成"
echo "═══════════════════════════════════════════════"
echo ""
echo "  面板地址:  ${APP_URL}"
echo "  管理员:    ${ADMIN_EMAIL}"
if [ "$INSTALLED" != "true" ] || [ "$GENERATED_PASSWORD" = true ]; then
  if [ "$INSTALLED" != "true" ]; then
    echo "  密码:      ${ADMIN_PASSWORD}"
  else
    echo "  密码:      ${ADMIN_PASSWORD}（本次生成）"
  fi
else
  echo "  密码:      沿用首次安装（丢失请用忘记密码重置）"
fi
[ -n "$DOMAIN" ] && echo "  HTTPS:     Caddy 自动配置（Let's Encrypt）"
echo "  安装目录:  ${INSTALL_DIR}"
echo "  备份目录:  ${INSTALL_DIR}/bk-*.sqlite.gz"
echo ""
echo "  下一步: 登录面板 → 机器管理 → 新建机器"
echo "         → 复制节点安装命令到节点服务器执行"
echo ""
if [ -f "${INSTALL_DIR}/upgrade.sh" ]; then
  echo "  升级: cd ${INSTALL_DIR} && bash upgrade.sh"
  echo "  卸载: cd ${INSTALL_DIR} && bash uninstall.sh"
else
  echo "  升级: cd ${INSTALL_DIR} && docker compose pull && docker compose up -d"
fi
echo "═══════════════════════════════════════════════"

#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════
# AIBolt 面板一键安装
#
# 用法（交互式）:
#   curl -fsSL https://install.aibolt.tech | sudo bash
#
# 用法（无人值守）:
#   curl -fsSL https://install.aibolt.tech | sudo bash -s -- \
#     --unattended --admin-email admin@example.com --admin-password Secret123 \
#     --port 7001
#
# 或直接运行:
#   sudo bash install.sh [--unattended] [--admin-email X] [--admin-password X] [--port N] [--image IMAGE] [--mirror URL]
#
# 安装目录默认 /opt/aibolt，可用 --dir 覆盖。
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

# ── 颜色 ──
info()    { echo -e "\e[32m[AIBolt]\e[0m $*"; }
warn()    { echo -e "\e[33m[AIBolt WARN]\e[0m $*"; }
error()   { echo -e "\e[31m[AIBolt ERROR]\e[0m $*"; exit 1; }
step()    { echo -e "\e[36m── $* ──\e[0m"; }

# ── 默认值 ──
INSTALL_DIR="/opt/aibolt"
XBOARD_IMAGE="ghcr.io/coolcrow/xboard:bundle"
PANEL_PORT="7001"
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
MODE="interactive"
MIRROR=""
COMPOSE_FILE="compose.yaml"

# ── 参数解析 ──
while [[ $# -gt 0 ]]; do
  case "$1" in
    --unattended)     MODE="unattended"; shift ;;
    --admin-email)    ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --port)           PANEL_PORT="$2"; shift 2 ;;
    --dir)            INSTALL_DIR="$2"; shift 2 ;;
    --image)          XBOARD_IMAGE="$2"; shift 2 ;;
    --mirror)         MIRROR="$2"; shift 2 ;;
    -h|--help)
      cat <<'USAGE'
AIBolt 面板一键安装

选项:
  --unattended          无人值守模式（需配合 --admin-email/--admin-password）
  --admin-email EMAIL   管理员邮箱（无人值守必填）
  --admin-password PASS 管理员密码 ≥8 位（无人值守必填；交互模式自动生成）
  --port N              面板监听端口（默认 7001）
  --dir PATH            安装目录（默认 /opt/aibolt）
  --image IMAGE         镜像（默认 ghcr.io/coolcrow/xboard:bundle）
  --mirror URL          中国镜像加速地址（如 https://mirror.ghproxy.com）
  -h, --help            帮助
USAGE
      exit 0 ;;
    *) error "未知参数: $1（--help 查看用法）" ;;
  esac
done

# ── 前置检查 ──
step "[0/6] 前置检查"

[[ $EUID -eq 0 ]] || error "请以 root 运行（sudo bash install.sh）"

# OS 检查（仅支持 Linux）
[[ "$(uname -s)" == "Linux" ]] || error "仅支持 Linux"

# 端口检查
if ss -tln 2>/dev/null | grep -q ":${PANEL_PORT} "; then
  warn "端口 ${PANEL_PORT} 已被占用，可能已有服务运行"
  [[ "$MODE" == "unattended" ]] || read -p "继续使用此端口？(y/N) " REPLY
  [[ "${REPLY:-n}" == "y" || "$MODE" == "unattended" ]] || exit 1
fi

# 无人值守参数校验
if [[ "$MODE" == "unattended" ]]; then
  [[ -n "$ADMIN_EMAIL" ]] || error "无人值守模式需 --admin-email"
  [[ -n "$ADMIN_PASSWORD" ]] || error "无人值守模式需 --admin-password"
  [[ ${#ADMIN_PASSWORD} -ge 8 ]] || error "管理员密码至少 8 位"
  info "无人值守模式: admin=${ADMIN_EMAIL} port=${PANEL_PORT}"
fi

# ── Docker 安装 ──
step "[1/6] Docker 环境"

install_docker() {
  info "正在安装 Docker..."
  if command -v apt-get &>/dev/null; then
    apt-get update -qq && apt-get install -y -qq curl ca-certificates >/dev/null 2>&1
    curl -fsSL https://get.docker.com | sh >/dev/null 2>&1 || \
      curl -fsSL https://get.docker.com | sh
  elif command -v yum &>/dev/null || command -v dnf &>/dev/null; then
    curl -fsSL https://get.docker.com | sh >/dev/null 2>&1 || \
      curl -fsSL https://get.docker.com | sh
  else
    error "不支持的包管理器，请手动安装 Docker 后重试"
  fi
  systemctl enable --now docker
  info "Docker 安装完成: $(docker --version)"
}

if command -v docker &>/dev/null; then
  info "Docker 已安装: $(docker --version)"
else
  install_docker
fi

# Docker Compose 检测（v2 集成或独立二进制）
if docker compose version &>/dev/null; then
  COMPOSE_CMD="docker compose"
  info "Docker Compose v2: $(docker compose version --short)"
elif command -v docker-compose &>/dev/null; then
  COMPOSE_CMD="docker-compose"
  info "Docker Compose v1: $(docker-compose --version)"
else
  warn "未找到 Docker Compose，尝试安装..."
  apt-get install -y docker-compose-plugin 2>/dev/null || \
    yum install -y docker-compose-plugin 2>/dev/null || \
    error "请手动安装 Docker Compose"
  COMPOSE_CMD="docker compose"
fi

# ── 镜像拉取 ──
step "[2/6] 拉取镜像"

# 离线模式：当前目录有 image-bundle.tar 时直接 docker load（无需网络）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_TAR="${SCRIPT_DIR}/image-bundle.tar"
if [[ -f "$LOCAL_TAR" ]]; then
  info "检测到离线镜像包: ${LOCAL_TAR}"
  docker load -i "$LOCAL_TAR" || error "镜像导入失败（文件损坏？）"
  PULL_IMAGE=$(docker images --format "{{.Repository}}:{{.Tag}}" | grep xboard | head -1)
  [ -n "$PULL_IMAGE" ] || error "导入后未找到 xboard 镜像"
  info "离线导入完成: ${PULL_IMAGE}"
else
  PULL_IMAGE="$XBOARD_IMAGE"
  if [[ -n "$MIRROR" ]]; then
    PULL_IMAGE="${MIRROR}/${XBOARD_IMAGE}"
    info "使用镜像加速: ${MIRROR}"
  fi
  info "拉取 ${PULL_IMAGE}..."
  if ! docker pull "$PULL_IMAGE" 2>&1; then
    warn "主源拉取失败，尝试直连..."
    docker pull "$XBOARD_IMAGE" || error "镜像拉取失败，请检查网络或使用 --mirror / 离线包"
    PULL_IMAGE="$XBOARD_IMAGE"
  fi
fi

# ── 部署目录 ──
step "[3/6] 初始化部署目录"

mkdir -p "$INSTALL_DIR"/{.docker/.data,storage/logs,storage/theme}
cd "$INSTALL_DIR"

# 生成 .env（APP_KEY 随机）
if [ ! -f .env ] || [ ! -s .env ]; then
  APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
  cat > .env <<EOF
APP_NAME=AIBolt
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=http://localhost
LOG_CHANNEL=stack
LOG_LEVEL=error
DB_CONNECTION=sqlite
REDIS_HOST=/data/redis.sock
REDIS_PORT=0
EOF
  chmod 600 .env
  info ".env 已生成（APP_KEY 随机）"
else
  info ".env 已存在，跳过生成"
fi

# 写 compose.yaml
if [ ! -f compose.yaml ] || [ ! -s compose.yaml ]; then
  cat > compose.yaml <<EOF
services:
  xboard:
    image: ${PULL_IMAGE}
    container_name: aibolt-panel
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PANEL_PORT}:7001"
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
    environment:
      - OCTANE_WORKERS=2
      - OCTANE_MAX_REQUESTS=10000
      - ENABLE_SQLITE=true
      - ENABLE_REDIS=true
      - ADMIN_ACCOUNT=${ADMIN_EMAIL}
      - ADMIN_PASSWORD=${ADMIN_PASSWORD}
    healthcheck:
      test: ["CMD", "curl", "-f", "-m", "8", "http://127.0.0.1:7001/api/v1/guest/comm/config"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s
EOF
  info "compose.yaml 已生成"
else
  info "compose.yaml 已存在，跳过（升级场景）"
fi

# ── 启动容器 ──
step "[4/6] 启动面板容器"

$COMPOSE_CMD up -d 2>&1 | tail -2

# 阶段 1：等待服务器有响应（任何 HTTP 状态码=进程活着）
info "等待面板进程启动..."
RETRY=0; MAX=20
while [ $RETRY -lt $MAX ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PANEL_PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" != "000" ] && [ "$HTTP" != "" ] && break
  RETRY=$((RETRY+1)); sleep 2
done
[ "$HTTP" != "000" ] && [ "$HTTP" != "" ] || error "面板进程未启动（${MAX}×2s），请检查: docker logs aibolt-panel"
info "面板进程已启动 (HTTP ${HTTP}，数据库待初始化)"

# ── 初始化（首次安装） ──
step "[5/6] 初始化数据库与管理员"

# 检查是否已安装
INSTALLED=$(docker exec aibolt-panel sh -c 'grep "^INSTALLED=" /www/.env 2>/dev/null | cut -d= -f2' || echo "")
if [ "$INSTALLED" = "true" ]; then
  info "已安装，跳过初始化（升级场景）"
else
  info "执行安装（SQLite + 内置 Redis + 基线安全配置自动应用）..."
  docker exec aibolt-panel php /www/artisan xboard:install 2>&1 | grep -E "管理员|密码|访问|secure|一切就绪|error|Error" || true

  # 阶段 2：安装后验证（数据库就绪，应返回 200）
  info "验证安装结果..."
  HRETRY=0; HMAX=15
  while [ $HRETRY -lt $HMAX ]; do
    HHTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PANEL_PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
    [ "$HHTTP" = "200" ] && break
    HRETRY=$((HRETRY+1)); sleep 2
  done
  if [ "$HHTTP" = "200" ]; then
    info "✅ 面板完全就绪 (HTTP 200)"
  else
    warn "面板返回 HTTP ${HHTTP}（非 200）——安装可能部分失败，请检查: docker logs aibolt-panel"
  fi

  # 提取生成的管理员信息（无人值守时已知；交互时从输出提取）
  if [ "$MODE" != "unattended" ]; then
    # 从安装输出中提取密码（格式: 管理员密码：xxx）
    GEN_PASS=$(docker exec aibolt-panel php /www/artisan tinker --execute="
      echo \App\Models\User::where('is_admin',1)->first()->email;
    " 2>/dev/null | tail -1 || echo "?")
    info "管理员账号: ${GEN_PASS}"
    info "密码已生成（见上方安装输出），或通过忘记密码重置"
  fi
fi

# 数据库迁移（升级场景=已安装时也跑，保证 schema 最新）
if [ "$INSTALLED" = "true" ]; then
  info "执行数据库迁移（升级）..."
  docker exec aibolt-panel php /www/artisan migrate --force 2>&1 | tail -2 || true
fi

# ── Cron 注册 ──
step "[6/6] 注册定时任务"

CRON_FILE="/tmp/aibolt-cron-$$"
CONTAINER_NAME="aibolt-panel"

# 保留已有的非 AIBolt cron 条目
(crontab -l 2>/dev/null | grep -v "aibolt\|${INSTALL_DIR}" || true) > "$CRON_FILE"

# 调度任务（每分钟：流量重置/统计聚合/佣金结算/订单超时）
echo "* * * * * docker exec ${CONTAINER_NAME} php /www/artisan schedule:run >> ${INSTALL_DIR}/schedule.log 2>&1" >> "$CRON_FILE"

# 数据库备份（每日 04:30，保留 14 份）
cat >> "$CRON_FILE" <<'CRON'
30 4 * * * /bin/sh -c 'docker exec aibolt-panel sh -c "sqlite3 /www/.docker/.data/database.sqlite \".backup /tmp/xw-bk.sqlite\"" && docker cp aibolt-panel:/tmp/xw-bk.sqlite /tmp/xw-bk.sqlite && gzip -f /tmp/xw-bk.sqlite && mkdir -p /opt/aibolt/backups && mv /tmp/xw-bk.sqlite.gz /opt/aibolt/backups/db-$(date +\%Y\%m\%d-\%H\%M).sqlite.gz && ls -1t /opt/aibolt/backups/db-*.sqlite.gz | tail -n +15 | xargs -r rm -f' >> /opt/aibolt/backups/backup.log 2>&1
CRON

# 健康检查（每 5 分钟，异常自动重启容器）
echo "*/5 * * * * curl -s -o /dev/null -w \"\%{http_code}\" -m 8 http://127.0.0.1:7001/api/v1/guest/comm/config | grep -q 200 || (cd /opt/aibolt && docker compose restart xboard) >> /opt/aibolt/healthcheck.log 2>&1" >> "$CRON_FILE"

crontab "$CRON_FILE"
rm -f "$CRON_FILE"
info "Cron 已注册: 调度(每分钟) + 备份(每日04:30) + 健康检查(每5分钟)"

# ── 完成 ──
echo ""
echo "═════════════════════════════════════════════════════════"
info "🎉 AIBolt 面板安装完成"
echo "═════════════════════════════════════════════════════════"
echo ""
echo "  面板地址:  http://<你的服务器IP>:${PANEL_PORT}"
echo "  管理入口:  http://<你的服务器IP>:${PANEL_PORT}/<secure_path>"
echo "  安装目录:  ${INSTALL_DIR}"
echo "  数据目录:  ${INSTALL_DIR}/.docker/.data（SQLite）"
echo "  备份目录:  ${INSTALL_DIR}/backups"
echo ""
if [ "$MODE" = "unattended" ]; then
  echo "  管理员:  ${ADMIN_EMAIL}"
  echo "  密码:    ${ADMIN_PASSWORD}"
else
  echo "  管理员密码见上方安装输出（或通过忘记密码重置）"
fi
echo ""
echo "  下一步:"
echo "  1. 配置反向代理（nginx/Caddy）将域名指向 127.0.0.1:${PANEL_PORT}"
echo "  2. 登录管理后台 → 系统配置 → 设置站点域名(app_url)"
echo "  3. 机器管理 → 新建机器 → 复制 agent 安装命令到节点机执行"
echo "  4. 节点管理 → 绑定节点 → 用户即可使用"
echo ""
echo "  升级: cd ${INSTALL_DIR} && docker compose pull && docker compose up -d"
echo "  卸载: cd ${INSTALL_DIR} && docker compose down && rm -rf ${INSTALL_DIR}"
echo ""

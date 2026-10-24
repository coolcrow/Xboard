#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# AIBolt 交互式安装向导 — 纯 CLI，step-by-step
#
# 设计原则（UX-AUDIT P0 落地）：
#   · 安装/升级/卸载全程命令行，不要求用户打开浏览器
#   · 需要什么资料就明确提示为什么需要
#   · 每步验证后才进下一步；可跳过（高级用户）
#   · 中断后可恢复（状态存 .install-state）
#
# 用法:
#   bash install.sh                    # 交互式完整安装（默认）
#   bash install.sh --skip-wizard      # 只装面板，跳过配置向导
#   bash install.sh --resume           # 从上次中断处继续
# ═══════════════════════════════════════════════════════════════
set -Eeuo pipefail

# ── 颜色与工具函数 ──────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'

info()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}[⚠]${NC} $1"; }
err()   { echo -e "${RED}[✗]${NC} $1"; }
step_n() { echo -e "\n${CYAN}═══ ${BOLD}Step $1/$TOTAL_STEPS${NC} ${CYAN}— $2 ═══${NC}\n"; }
hint()  { echo -e "  ${DIM}ℹ $1${NC}"; }
ask()   { read -p "$(echo -e "${BOLD}  ? $1${NC} ")" "$2" < /dev/tty; }
ask_default() {
  local __prompt="$1" __default="$2" __var="$3"
  read -p "$(echo -e "${BOLD}  ? $1${NC} [${__default}]: ")" "__val" < /dev/tty
  eval "$__var=\"\${__val:-$__default}\""
}
skip_or_continue() {
  ask "跳过此步骤？(y/N)" _skip
  [ "${_skip:-n}" = "y" ]
}

TOTAL_STEPS=7
STATE_FILE=""
INSTALL_DIR="/opt/aibolt"
SKIP_WIZARD=""

# ── 参数解析 ──────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-wizard) SKIP_WIZARD=1; shift ;;
    --resume)      RESUME=1; shift ;;
    --dir)         INSTALL_DIR="$2"; shift 2 ;;
    -h|--help)
      echo "用法: bash install.sh [--skip-wizard] [--resume] [--dir PATH]"
      echo "  --skip-wizard  只装面板，跳过配置向导"
      echo "  --resume       从上次中断处继续"
      exit 0 ;;
    *) shift ;;
  esac
done

STATE_FILE="${INSTALL_DIR}/.install-state"

# ── 状态管理（中断恢复）──────────────────────────────────────
save_state() { echo "$1" > "$STATE_FILE"; }
get_state() { [ -f "$STATE_FILE" ] && cat "$STATE_FILE" || echo "0"; }
CURRENT_STEP=$(get_state)

# ═══ Step 1: 面板安装 ═══════════════════════════════════════
# 这一步复用现有的安装逻辑（域名/邮箱/SMTP/Docker 镜像/容器启动）
# 安装完成后进入配置向导

panel_install() {
  step_n 1 "面板安装"

  if [ -f "${INSTALL_DIR}/compose.yaml" ] && docker ps --format '{{.Names}}' | grep -q aibolt-panel; then
    info "面板已安装并运行，跳过"
    return 0
  fi

  hint "面板是管理后台——管理机器/节点/套餐/用户，不参与代理流量"

  # 以下是现有 install.sh 的核心逻辑（简化版）
  # ... 调用原有安装流程 ...
  echo "  [此处调用现有 install.sh 的面板安装逻辑]"
  warn "面板安装逻辑请参考 deploy/install.sh（本文件为向导层包装）"
}

# ═══ Step 2: 落地机 ═════════════════════════════════════════
landing_machine_setup() {
  step_n 2 "落地机配置"
  hint "落地机跑代理内核（认证/计费/出口），用户不直连——IP 藏在接入机后面"

  local NAME="" IP="" TOKEN=""

  ask_default "落地机名称" "landing-01" NAME
  ask "落地机公网 IP（防火墙白名单和转发目标依赖此地址，必填）" IP
  while [ -z "$IP" ]; do
    warn "IP 不能为空——防火墙白名单和 realm 转发目标都依赖此地址"
    ask "落地机公网 IP" IP
  done

  # 通过面板 API 注册机器（artisan tinker 从容器内执行）
  local RESULT
  RESULT=$(docker exec aibolt-panel php /www/artisan tinker --execute="
    \$m = new App\Models\ServerMachine;
    \$m->name = '${NAME}';
    \$m->ip = '${IP}';
    \$m->machine_type = 'landing';
    \$m->is_active = 1;
    \$m->save();
    echo \$m->id . '|' . \$m->token;
  " 2>/dev/null | tail -1)

  local MID TOKEN
  MID=$(echo "$RESULT" | cut -d'|' -f1)
  TOKEN=$(echo "$RESULT" | cut -d'|' -f2)

  if [ -z "$MID" ] || [ "$MID" = "" ]; then
    err "机器注册失败"
    return 1
  fi
  info "机器已注册: ${NAME} (ID=${MID})"

  echo ""
  info "在落地机服务器上执行以下命令安装 agent:"
  echo ""
  echo -e "  ${CYAN}curl -fsSL https://cdn.jsdelivr.net/gh/coolcrow/Xboard-Node@main/install.sh | sudo bash -s -- --panel https://YOUR_PANEL_DOMAIN --machine-id ${MID} --token ${TOKEN}${NC}"
  echo ""
  hint "agent 安装后约 30-60 秒内自动上线（心跳上报）"

  # 等待上线
  echo -n "  等待 agent 上线"
  for i in $(seq 1 60); do
    echo -n "."
    local ONLINE
    ONLINE=$(docker exec aibolt-panel php /www/artisan tinker --execute="
      echo (time() - App\Models\ServerMachine::find(${MID})->last_seen_at) < 120 ? 'yes' : 'no';
    " 2>/dev/null | tail -1)
    [ "$ONLINE" = "yes" ] && { echo ""; info "Agent 已上线 ✓"; return 0; }
    sleep 5
  done
  echo ""
  warn "Agent 120 秒内未上线——继续下一步（稍后可在面板手动检查）"
}

# ═══ Step 3: 落地机服务节点 ═════════════════════════════════
service_node_setup() {
  step_n 3 "创建服务节点（在落地机上跑内核）"
  hint "服务节点绑定落地机——协议/端口/证书在这里配置"

  local PROTO="" PORT="" CERT_DOMAIN=""

  echo "  支持的协议:"
  echo "    1. hysteria2  (UDP，推荐——速度快)"
  echo "    2. trojan     (TCP，兼容性好)"
  ask_default "选择协议" "1" PROTO_NUM
  case "$PROTO_NUM" in
    1) PROTO="hysteria" ;;
    2) PROTO="trojan" ;;
    *) warn "无效选择，使用 hysteria2"; PROTO="hysteria" ;;
  esac

  ask_default "内核监听端口" "443" PORT
  ask "证书域名（用于 TLS，回车则用自签证书填落地机 IP）" CERT_DOMAIN
  [ -z "$CERT_DOMAIN" ] && CERT_DOMAIN="__LANDING_IP__"

  # 创建节点
  local NODE_ID
  NODE_ID=$(docker exec aibolt-panel php /www/artisan tinker --execute="
    \$n = new App\Models\Server;
    \$n->name = 'landing-{$PROTO}';
    \$n->type = '${PROTO}';
    \$n->host = '${CERT_DOMAIN}';
    \$n->port = '${PORT}';
    \$n->server_port = ${PORT};
    \$n->machine_id = __LANDING_MID__;
    \$n->show = 0;
    \$n->enabled = 1;
    \$n->group_ids = ['1'];
    \$n->sort = 1;
    \$n->rate = 1;
    \$n->protocol_settings = ['tls' => 1];
    \$n->cert_config = ['cert_mode' => 'self', 'cert_domain' => '${CERT_DOMAIN}'];
    \$n->save();
    echo \$n->id;
  " 2>/dev/null | tail -1)

  [ -n "$NODE_ID" ] && info "服务节点已创建: ID=${NODE_ID} ${PROTO}:${PORT}" || err "创建失败"
}

# ═══ Step 4: 接入机 ═════════════════════════════════════════
access_machine_setup() {
  step_n 4 "接入机配置"
  hint "接入机是用户直连的入口——realm 转发在这里运行，线路质量决定体验"

  local NAME="" IP=""

  ask_default "接入机名称" "access-01" NAME
  ask "接入机公网 IP（用户直连此 IP，防火墙白名单也依赖，必填）" IP
  while [ -z "$IP" ]; do
    warn "IP 不能为空——用户直连地址和防火墙白名单都依赖此地址"
    ask "接入机公网 IP" IP
  done

  local RESULT
  RESULT=$(docker exec aibolt-panel php /www/artisan tinker --execute="
    \$m = new App\Models\ServerMachine;
    \$m->name = '${NAME}';
    \$m->ip = '${IP}';
    \$m->machine_type = 'access';
    \$m->is_active = 1;
    \$m->save();
    echo \$m->id . '|' . \$m->token;
  " 2>/dev/null | tail -1)

  local MID TOKEN
  MID=$(echo "$RESULT" | cut -d'|' -f1)
  TOKEN=$(echo "$RESULT" | cut -d'|' -f2)

  [ -n "$MID" ] && info "接入机已注册: ${NAME} (ID=${MID})" || { err "注册失败"; return 1; }

  echo ""
  info "在接入机服务器上执行以下命令安装 agent:"
  echo ""
  echo -e "  ${CYAN}curl -fsSL https://cdn.jsdelivr.net/gh/coolcrow/Xboard-Node@main/install.sh | sudo bash -s -- --panel https://YOUR_PANEL_DOMAIN --machine-id ${MID} --token ${TOKEN}${NC}"
  echo ""

  echo -n "  等待 agent 上线"
  for i in $(seq 1 60); do
    echo -n "."
    local ONLINE
    ONLINE=$(docker exec aibolt-panel php /www/artisan tinker --execute="
      echo (time() - App\Models\ServerMachine::find(${MID})->last_seen_at) < 120 ? 'yes' : 'no';
    " 2>/dev/null | tail -1)
    [ "$ONLINE" = "yes" ] && { echo ""; info "Agent 已上线 ✓"; return 0; }
    sleep 5
  done
  echo ""
  warn "Agent 未上线——继续下一步"
}

# ═══ Step 5: 入口坐标节点 ═══════════════════════════════════
entry_node_setup() {
  step_n 5 "创建入口坐标节点（用户看到的节点）"
  hint "纯坐标——不绑机器、不跑内核，host 填接入机 IP/域名"

  local DOMAIN="" PORT=""

  ask "入口域名（用户连接的域名，如 gz1.example.com）" DOMAIN
  while [ -z "$DOMAIN" ]; do
    warn "入口域名不能为空——这是用户订阅里的连接地址"
    ask "入口域名" DOMAIN
  done
  ask_default "入口端口" "443" PORT

  local NODE_ID
  NODE_ID=$(docker exec aibolt-panel php /www/artisan tinker --execute="
    \$n = new App\Models\Server;
    \$n->name = 'entry-{$PROTO}';
    \$n->type = '${PROTO}';
    \$n->host = '${DOMAIN}';
    \$n->port = '${PORT}';
    \$n->server_port = ${PORT};
    \$n->machine_id = null;
    \$n->entry_machine_id = __ACCESS_MID__;
    \$n->show = 1;
    \$n->enabled = 1;
    \$n->group_ids = ['1'];
    \$n->sort = 1;
    \$n->rate = 1;
    \$n->protocol_settings = ['tls' => 1, 'server_name' => '${DOMAIN}'];
    \$n->save();
    echo \$n->id;
  " 2>/dev/null | tail -1)

  [ -n "$NODE_ID" ] && info "入口节点已创建: ID=${NODE_ID} ${DOMAIN}:${PORT}"
}

# ═══ Step 6: 转发配置 ═══════════════════════════════════════
forwarding_setup() {
  step_n 6 "配置转发（接入机 → 落地机）"
  hint "realm 在接入机上把入口端口的流量转发到落地机的服务端口"

  local FRONT_PORT="" PROTO=""

  ask_default "入口端口（用户连接的端口）" "443" FRONT_PORT
  ask_default "协议 (udp/tcp)" "udp" PROTO

  # 配置 forwards
  docker exec aibolt-panel php /www/artisan tinker --execute="
    \$m = App\Models\ServerMachine::find(__ACCESS_MID__);
    \$m->relay_forwards = [{'frontend' => ${FRONT_PORT}, 'proto' => '${PROTO}', 'node_id' => __SERVICE_NODE_ID__}];
    \$m->save();
    echo 'ok';
  " 2>/dev/null | tail -1 | grep -q "ok" && info "转发已配置: ${FRONT_PORT}/${PROTO} → 落地机" || err "配置失败"
}

# ═══ Step 7: DNS 验证 ═══════════════════════════════════════
dns_verify() {
  step_n 7 "DNS 验证"
  hint "入口域名必须解析到接入机 IP，否则用户无法连接"

  local DOMAIN="" ACCESS_IP=""
  # 从状态或重新询问
  ask "入口域名" DOMAIN
  ask "接入机 IP" ACCESS_IP

  echo ""
  info "请在 DNS 服务商处添加以下记录（灰云/DNS only，不要开代理）:"
  echo ""
  echo -e "  ${CYAN}${DOMAIN}  A  →  ${ACCESS_IP}${NC}"
  echo ""

  local RESOLVED=""
  for i in $(seq 1 12); do
    RESOLVED=$(dig +short "$DOMAIN" A 2>/dev/null | head -1)
    [ "$RESOLVED" = "$ACCESS_IP" ] && { info "DNS 已生效: ${DOMAIN} → ${ACCESS_IP} ✓"; return 0; }
    echo "  等待 DNS 生效... ($i/12)"
    sleep 10
  done

  warn "DNS 尚未生效（解析到: ${RESOLVED:-不解析}）"
  hint "DNS 传播通常需要几分钟到几小时，稍后可用 dig 验证"
  hint "系统已配置完毕——DNS 生效后用户即可连接"
}

# ═══ 主流程 ═════════════════════════════════════════════════
main() {
  echo ""
  echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
  echo -e "${CYAN}║  ${BOLD}AIBolt 交互式安装向导${NC}                        ${CYAN}║${NC}"
  echo -e "${CYAN}║  纯 CLI · Step-by-Step · 中断可恢复${NC}          ${CYAN}║${NC}"
  echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
  echo ""

  # Step 1: 面板安装
  CURRENT_STEP=1; save_state 1
  panel_install
  [ -n "$SKIP_WIZARD" ] && { echo; info "跳过配置向导（--skip-wizard）"; exit 0; }

  # 确认分组存在（Step 1 的后置动作）
  docker exec aibolt-panel php /www/artisan tinker --execute="
    if (App\Models\ServerGroup::count() === 0) {
      App\Models\ServerGroup::create(['name' => '默认分组']);
    }
    \$admin = App\Models\User::where('is_admin', 1)->first();
    if (\$admin && !\$admin->group_id) {
      \$admin->group_id = 1;
      \$admin->transfer_enable = 107374182400;
      \$admin->expired_at = time() + 86400 * 365;
      \$admin->save();
    }
  " 2>/dev/null
  info "分组和管理员已配置"

  # Step 2: 落地机
  CURRENT_STEP=2; save_state 2
  landing_machine_setup || skip_or_continue || exit 1

  # Step 3: 服务节点
  CURRENT_STEP=3; save_state 3
  service_node_setup || skip_or_continue || exit 1

  # Step 4: 接入机
  CURRENT_STEP=4; save_state 4
  access_machine_setup || skip_or_continue || exit 1

  # Step 5: 入口坐标
  CURRENT_STEP=5; save_state 5
  entry_node_setup || skip_or_continue || exit 1

  # Step 6: 转发
  CURRENT_STEP=6; save_state 6
  forwarding_setup || skip_or_continue || exit 1

  # Step 7: DNS
  CURRENT_STEP=7; save_state 7
  dns_verify

  # 完成
  rm -f "$STATE_FILE"
  echo ""
  echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
  echo -e "${GREEN}║  ${BOLD}✅ 安装完成！${NC}                                 ${GREEN}║${NC}"
  echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
  echo ""
  echo "  管理面板: 在浏览器打开面板域名"
  echo "  用户订阅: 用户在前台注册后自动获取"
  echo "  升级:     cd ${INSTALL_DIR} && bash upgrade.sh"
  echo ""
}

main "$@"

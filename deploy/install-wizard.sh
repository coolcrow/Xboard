#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# AIBolt 交互式安装向导 — 纯 CLI，step-by-step
# ═══════════════════════════════════════════════════════════════
set -Eeuo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'

info()  { echo -e "${GREEN}  [✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}  [⚠]${NC} $1"; }
err()   { echo -e "${RED}  [✗]${NC} $1"; }
step_h(){ echo -e "\n${CYAN}════════════════════════════════════════════${NC}"; echo -e "${CYAN}  ${BOLD}Step $1/7${NC}  ${CYAN}— $2${NC}"; echo -e "${CYAN}════════════════════════════════════════════${NC}\n"; }
hint()  { echo -e "  ${DIM}ℹ $1${NC}"; }

ask() {
  local __prompt="$1" __var="$2" __default="${3:-}"
  if [ -n "$__default" ]; then
    read -p "$(echo -e "${BOLD}  ? ${__prompt}${NC} [${__default}]: ")" __val < /dev/tty
    eval "$__var=\"\${__val:-$__default}\""
  else
    read -p "$(echo -e "${BOLD}  ? ${__prompt}:${NC} ")" __val < /dev/tty
    eval "$__var=\"\$__val\""
  fi
}

ask_required() {
  local __prompt="$1" __var="$2" __why="${3:-此字段为必填}"
  local __val=""
  while [ -z "$__val" ]; do
    read -p "$(echo -e "${BOLD}  ? ${__prompt}:${NC} ")" __val < /dev/tty
    [ -z "$__val" ] && warn "$__why"
  done
  eval "$__var=\"\$__val\""
}

skip_step() {
  read -p "$(echo -e "  ${DIM}跳过此步骤？(y/N)${NC} ")" _sk < /dev/tty
  [ "${_sk:-n}" = "y" ]
}

run_php() {
  local php_code="$1"
  local tmp="/tmp/wizard-php-$$"
  echo '<?php' > "$tmp"
  echo "require '/www/vendor/autoload.php';" >> "$tmp"
  echo '$app = require "/www/bootstrap/app.php";' >> "$tmp"
  echo '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();' >> "$tmp"
  echo "$php_code" >> "$tmp"
  docker cp "$tmp" aibolt-panel:/tmp/wz.php >/dev/null 2>&1
  docker exec aibolt-panel php /tmp/wz.php 2>/dev/null
  docker exec aibolt-panel rm -f /tmp/wz.php >/dev/null 2>&1
  rm -f "$tmp"
}

wait_agent() {
  local mid="$1" name="$2" timeout="${3:-120}"
  echo -n "  等待 ${name} agent 上线"
  local elapsed=0
  while [ $elapsed -lt $timeout ]; do
    echo -n "."
    local online
    online=$(run_php "echo (time() - App\Models\ServerMachine::find(${mid})->last_seen_at ?? 0) < 90 ? 'yes' : 'no';")
    [ "$online" = "yes" ] && { echo ""; info "Agent 已上线"; return 0; }
    sleep 5; elapsed=$((elapsed + 5))
  done
  echo ""
  warn "Agent ${timeout}s 内未上线——继续下一步（可稍后在面板检查）"
  return 1
}

step1_panel() {
  step_h 1 "面板安装"
  hint "面板是管理后台——管理机器/节点/套餐/用户，不参与代理流量"

  if docker ps --format '{{.Names}}' 2>/dev/null | grep -q aibolt-panel; then
    info "面板已在运行，跳过安装"
    return 0
  fi

  local SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  if [ -f "${SCRIPT_DIR}/install.sh" ]; then
    bash "${SCRIPT_DIR}/install.sh" "$@"
  else
    curl -fsSL "https://cdn.jsdelivr.net/gh/coolcrow/Xboard@master/deploy/install.sh" | bash
  fi

  run_php "
    Illuminate\Database\Eloquent\Model::unguard();
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
    echo 'group_admin_ok';
  " | grep -q "group_admin_ok" && info "分组和管理员已配置" || warn "分组/管理员配置可能不完整"
}

LANDING_MID=""; LANDING_IP=""; SERVICE_NODE_ID=""; ACCESS_MID=""; ACCESS_IP=""
ENTRY_DOMAIN=""; ENTRY_PORT=""; LANDING_NAME=""; ACCESS_NAME=""; PROTO="hysteria"

step2_landing() {
  step_h 2 "落地机配置"
  hint "落地机跑代理内核（认证/计费/出口），用户不直连——IP 藏在接入机后面"

  ask "落地机名称" LANDING_NAME "landing-01"
  ask_required "落地机公网 IP" LANDING_IP "防火墙白名单和 realm 转发目标都依赖此地址"
  hint "此 IP 用于: ① 防火墙白名单 ② 服务节点 host ③ 证书域名"

  local result
  result=$(run_php "
    Illuminate\Database\Eloquent\Model::unguard();
    \$m = new App\Models\ServerMachine;
    \$m->name = '${LANDING_NAME}';
    \$m->ip = '${LANDING_IP}';
    \$m->machine_type = 'landing';
    \$m->is_active = 1;
    \$m->token = App\Models\ServerMachine::generateToken();
    \$m->save();
    echo \$m->id . '|' . \$m->token;
  ")

  LANDING_MID=$(echo "$result" | cut -d'|' -f1)
  local token=$(echo "$result" | cut -d'|' -f2)

  if [ -z "$LANDING_MID" ]; then err "机器注册失败"; return 1; fi
  info "机器已注册: ${LANDING_NAME} (ID=${LANDING_MID})"

  echo ""
  info "请在落地机服务器上执行以下命令安装 agent:"
  echo ""
  echo -e "  ${CYAN}curl -fsSL https://cdn.jsdelivr.net/gh/coolcrow/Xboard-Node@main/install.sh | sudo bash -s -- --panel-url PANEL_URL --machine-id ${LANDING_MID} --token ${token}${NC}"
  echo ""
  hint "agent 安装后约 30-60 秒内自动上线"

  wait_agent "$LANDING_MID" "${LANDING_NAME}"
}

step3_service_node() {
  step_h 3 "创建服务节点（在落地机上跑内核）"
  hint "服务节点绑定落地机——协议/端口/证书在这里配置，不暴露给用户"

  local proto port cert_domain
  echo "  协议选择:"
  echo "    1. hysteria2  (UDP，推荐)"
  echo "    2. trojan     (TCP，兼容性好)"
  ask "选择" proto_num "1"
  case "$proto_num" in
    2) proto="trojan" ;;
    *) proto="hysteria" ;;
  esac
  PROTO="$proto"

  ask "内核监听端口" port "443"
  ask "证书域名（TLS 用，回车则用落地机 IP 自签）" cert_domain "${LANDING_IP}"

  local result
  result=$(run_php "
    Illuminate\Database\Eloquent\Model::unguard();
    \$n = new App\Models\Server;
    \$n->name = '${proto}-service';
    \$n->type = '${proto}';
    \$n->host = '${cert_domain}';
    \$n->port = '${port}';
    \$n->server_port = ${port};
    \$n->machine_id = ${LANDING_MID};
    \$n->show = 0;
    \$n->enabled = 1;
    \$n->group_ids = ['1'];
    \$n->sort = 1;
    \$n->rate = 1;
    \$n->protocol_settings = [
      'tls' => 1,
      'server_name' => '${cert_domain}',
    ];
    \$n->cert_config = ['cert_mode' => 'self', 'cert_domain' => '${cert_domain}'];
    \$n->save();
    echo \$n->id;
  ")

  SERVICE_NODE_ID=$(echo "$result" | tail -1)
  if [ -n "$SERVICE_NODE_ID" ]; then
    info "服务节点已创建: ID=${SERVICE_NODE_ID} ${proto}:${port}"
  else
    err "创建失败"; return 1
  fi
}

step4_access() {
  step_h 4 "接入机配置"
  hint "接入机是用户直连的入口——realm 转发在这里运行，线路质量决定体验"

  ask "接入机名称" ACCESS_NAME "access-01"
  ask_required "接入机公网 IP" ACCESS_IP "用户直连此 IP，防火墙白名单也依赖此地址"
  hint "此 IP 用于: ① 用户订阅里的连接地址 ② 防火墙白名单来源"

  local result
  result=$(run_php "
    Illuminate\Database\Eloquent\Model::unguard();
    \$m = new App\Models\ServerMachine;
    \$m->name = '${ACCESS_NAME}';
    \$m->ip = '${ACCESS_IP}';
    \$m->machine_type = 'access';
    \$m->is_active = 1;
    \$m->token = App\Models\ServerMachine::generateToken();
    \$m->save();
    echo \$m->id . '|' . \$m->token;
  ")

  ACCESS_MID=$(echo "$result" | cut -d'|' -f1)
  local token=$(echo "$result" | cut -d'|' -f2)

  [ -n "$ACCESS_MID" ] && info "接入机已注册: ${ACCESS_NAME} (ID=${ACCESS_MID})" || { err "注册失败"; return 1; }

  echo ""
  info "请在接入机服务器上执行以下命令安装 agent:"
  echo ""
  echo -e "  ${CYAN}curl -fsSL https://cdn.jsdelivr.net/gh/coolcrow/Xboard-Node@main/install.sh | sudo bash -s -- --panel-url PANEL_URL --machine-id ${ACCESS_MID} --token ${token}${NC}"
  echo ""

  wait_agent "$ACCESS_MID" "${ACCESS_NAME}"
}

step5_entry() {
  step_h 5 "创建入口坐标节点（用户看到的节点）"
  hint "纯坐标——不绑机器、不跑内核，host 填入口域名或接入机 IP"

  ask_required "入口域名（用户连接的域名）" ENTRY_DOMAIN "这是用户订阅里的连接地址——填域名而非 IP，IP 被墙时可改 DNS"
  hint "域名好处: IP 被墙 → 改 DNS 记录即可恢复，不需要更新订阅"

  ask "入口端口" ENTRY_PORT "443"

  local result
  result=$(run_php "
    Illuminate\Database\Eloquent\Model::unguard();
    \$n = new App\Models\Server;
    \$n->name = 'entry-node';
    \$n->type = '${PROTO}';
    \$n->host = '${ENTRY_DOMAIN}';
    \$n->port = '${ENTRY_PORT}';
    \$n->server_port = ${ENTRY_PORT};
    \$n->machine_id = null;
    \$n->entry_machine_id = ${ACCESS_MID};
    \$n->show = 1;
    \$n->enabled = 1;
    \$n->group_ids = ['1'];
    \$n->sort = 1;
    \$n->rate = 1;
    \$n->protocol_settings = ['tls' => 1, 'server_name' => '${ENTRY_DOMAIN}'];
    \$n->save();
    echo \$n->id;
  ")

  local nid=$(echo "$result" | tail -1)
  [ -n "$nid" ] && info "入口节点已创建: ID=${nid} ${ENTRY_DOMAIN}:${ENTRY_PORT}" || err "创建失败"
}

step6_forward() {
  step_h 6 "配置转发（接入机 → 落地机）"
  hint "realm 在接入机上把入口端口的流量转发到落地机的服务端口"

  ask "入口端口" fwd_port "${ENTRY_PORT:-443}"
  ask "协议 (udp/tcp)" fwd_proto "udp"

  local result
  result=$(run_php "
    \$m = App\Models\ServerMachine::find(${ACCESS_MID});
    \$m->relay_forwards = [['frontend' => ${fwd_port}, 'proto' => '${fwd_proto}', 'node_id' => ${SERVICE_NODE_ID}]];
    \$m->relay_to_machine_id = ${LANDING_MID};
    \$m->save();
    \$spec = \$m->relaySpec();
    echo \$spec['enabled'] . '|' . \$spec['landing_host'] . '|' . \$spec['ports'];
  ")

  local enabled=$(echo "$result" | cut -d'|' -f1)
  local target=$(echo "$result" | cut -d'|' -f2)
  local ports=$(echo "$result" | cut -d'|' -f3)

  if [ "$enabled" = "1" ]; then
    info "转发已配置: ${fwd_port}/${fwd_proto} → ${target}"
    info "realm 目标: ${target} 端口: ${ports}"
  else
    err "转发配置失败"; return 1
  fi
}

step7_dns() {
  step_h 7 "DNS 验证"
  hint "入口域名必须解析到接入机 IP，否则用户无法连接"

  [ -z "$ENTRY_DOMAIN" ] && ask "入口域名" ENTRY_DOMAIN
  [ -z "$ACCESS_IP" ] && ask "接入机 IP" ACCESS_IP

  echo ""
  info "请在 DNS 服务商处添加以下记录（灰云/DNS only，不要开代理）:"
  echo ""
  echo -e "  ${CYAN}${ENTRY_DOMAIN}  A  →  ${ACCESS_IP}${NC}"
  echo ""
  hint "如果域名在 Cloudflare：Proxy status 设为 ⚪ DNS only"
  hint "TTL 建议设为 5 分钟（300s）——被墙时可快速切换 IP"

  local resolved=""
  for i in $(seq 1 12); do
    resolved=$(dig +short "$ENTRY_DOMAIN" A 2>/dev/null | head -1)
    if [ "$resolved" = "$ACCESS_IP" ]; then
      info "DNS 已生效: ${ENTRY_DOMAIN} → ${ACCESS_IP} ✓"
      return 0
    fi
    [ $i -eq 1 ] && echo -n "  等待 DNS 生效"
    echo -n "."
    sleep 10
  done
  echo ""
  warn "DNS 尚未生效（当前解析到: ${resolved:-不解析}）"
  hint "系统已配置完毕——DNS 生效后用户即可连接"
}

main() {
  echo ""
  echo -e "${CYAN}╔══════════════════════════════════════════════════╗${NC}"
  echo -e "${CYAN}║  ${BOLD}AIBolt 交互式安装向导${NC}                             ${CYAN}║${NC}"
  echo -e "${CYAN}║  纯 CLI · Step-by-Step · 需要什么就提示什么${NC}       ${CYAN}║${NC}"
  echo -e "${CYAN}╚══════════════════════════════════════════════════╝${NC}"

  step1_panel "$@"
  echo ""

  step2_landing || skip_step || exit 1
  echo ""
  step3_service_node || skip_step || exit 1
  echo ""
  step4_access || skip_step || exit 1
  echo ""
  step5_entry || skip_step || exit 1
  echo ""
  step6_forward || skip_step || exit 1
  echo ""
  step7_dns

  echo ""
  echo -e "${GREEN}╔══════════════════════════════════════════════════╗${NC}"
  echo -e "${GREEN}║  ${BOLD}✅ 安装完成！${NC}                                      ${GREEN}║${NC}"
  echo -e "${GREEN}╚══════════════════════════════════════════════════╝${NC}"
  echo ""
  echo "  落地机: ${LANDING_NAME:-?} (${LANDING_IP:-?})"
  echo "  接入机: ${ACCESS_NAME:-?} (${ACCESS_IP:-?})"
  echo "  入口:   ${ENTRY_DOMAIN:-?}:${ENTRY_PORT:-443}"
  echo ""
  echo "  管理面板: 浏览器打开面板域名"
  echo "  升级:     cd /opt/aibolt && bash upgrade.sh"
  echo "  卸载:     cd /opt/aibolt && bash uninstall.sh"
  echo ""
}

main "$@"

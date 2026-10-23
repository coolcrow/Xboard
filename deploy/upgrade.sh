#!/usr/bin/env bash
# AIBolt 面板升级脚本——在安装目录运行
# 用法: cd /opt/aibolt && bash upgrade.sh [--image IMAGE]
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$INSTALL_DIR"

[ -f compose.yaml ] || { echo "[ERROR] 未找到 compose.yaml——请在安装目录运行此脚本"; exit 1; }

IMAGE=""
# ${1:-} 守卫：set -u 下裸 $1 在无参调用（文档主推用法）直接 unbound variable 崩溃
if [ "${1:-}" = "--image" ] && [ -n "${2:-}" ]; then
  IMAGE="$2"; shift 2
fi
if [ -z "$IMAGE" ]; then
  IMAGE=$(grep "image:" compose.yaml | head -1 | awk '{print $2}')
fi

echo "[AIBolt] 当前镜像: ${IMAGE}"
echo "[AIBolt] 拉取新镜像..."
PULL_VIA_MIRROR=""
# 直连 ghcr 会无限停滞而非报错——timeout 兜底让镜像回退有机会触发
PULL_TIMEOUT="${PULL_TIMEOUT:-180}"
if ! timeout "$PULL_TIMEOUT" docker pull "$IMAGE"; then
  # 国内机器直连 ghcr 常停滞——镜像回退（南京大学镜像/ghproxy 类前缀）
  MIRRORS="ghcr.nju.edu.cn"
  PULLED=""
  for M in $MIRRORS; do
    MIMG="$(echo "$IMAGE" | sed "s|^ghcr.io|$M|")"
    echo "[AIBolt] 直连失败，尝试镜像: ${MIMG}"
    if timeout "$PULL_TIMEOUT" docker pull "$MIMG"; then
      docker tag "$MIMG" "$IMAGE"
      PULLED=1
      PULL_VIA_MIRROR=1
      break
    fi
  done
  # 全部拉取源失败——本地已有同名镜像则降级用本地（诚实提示可能非最新），
  # 否则硬失败。国内网络直连 ghcr 无限停滞是常态，本地镜像是最可靠的兜底。
  if [ -z "$PULLED" ]; then
    if docker image inspect "$IMAGE" >/dev/null 2>&1; then
      echo "[AIBolt] ⚠️ 所有拉取源不可达，降级使用本地镜像（可能与远端不一致）"
    else
      echo "[ERROR] 拉取失败（直连与镜像均不可达，本地亦无镜像）"; exit 1
    fi
  fi
fi

# 版本比对：利用 .env 中的 XBOARD_IMAGE_DIGEST
NEW_DIGEST=$(docker inspect "$IMAGE" --format '{{.Id}}' 2>/dev/null | head -c 71)
# grep 无匹配退出码 1 会穿透 set -e 杀脚本（digest 行被 artisan 安装器重写
# .env 后缺失时实测踩中）——|| true 必须在替换内部
OLD_DIGEST=$(grep "^XBOARD_IMAGE_DIGEST=" .env 2>/dev/null | cut -d= -f2 || true)
if [ -n "$OLD_DIGEST" ] && [ "$OLD_DIGEST" = "$NEW_DIGEST" ]; then
  if [ -n "$PULL_VIA_MIRROR" ]; then
    echo "[AIBolt] ⚠️ digest 与本机一致，但镜像走的是回退源——镜像站同步可能滞后，"
    echo "    若在等待刚发布的版本，请稍后重试或手动: docker pull ${IMAGE}"
  fi
  echo "[AIBolt] 已是最新版本（digest 一致），跳过升级"
  exit 0
fi
echo "[AIBolt] 检测到新版本: ${NEW_DIGEST:0:20}..."

echo "[AIBolt] 切换到新镜像..."
docker compose up -d 2>&1 | tail -2

echo "[AIBolt] 执行数据库迁移（新镜像代码）..."
if ! docker exec aibolt-panel php /www/artisan migrate --force 2>&1 | tail -3; then
  echo "[AIBolt] ⚠️ 迁移失败——面板可能需要手动修复"
  echo "    诊断: docker exec aibolt-panel php /www/artisan migrate --force"
  exit 1
fi

# 等待健康
RETRY=0; MAX=15
while [ $RETRY -lt $MAX ]; do
  PANEL_PORT=$(grep -oP '\"(?:127\.0\.0\.1|0\.0\.0\.0):\K[0-9]+' compose.yaml 2>/dev/null || echo "7001")
HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:${PANEL_PORT}/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && break
  RETRY=$((RETRY+1)); sleep 2
done

if [ "$HTTP" = "200" ]; then
  [ -n "$(tail -c 1 .env)" ] && echo >> .env   # 末行无换行时先补（否则追加粘连）
  grep -q "^XBOARD_IMAGE_DIGEST=" .env || echo "XBOARD_IMAGE_DIGEST=" >> .env
  sed -i "s|^XBOARD_IMAGE_DIGEST=.*|XBOARD_IMAGE_DIGEST=${NEW_DIGEST}|" .env
echo "[AIBolt] ✅ 升级完成，面板正常 (HTTP ${HTTP}) | 版本已记录"
else
  echo "[AIBolt] ⚠️ 面板未就绪，检查: docker logs aibolt-panel"
  exit 1
fi

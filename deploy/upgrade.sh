#!/usr/bin/env bash
# AIBolt 面板升级脚本——在安装目录运行
# 用法: cd /opt/aibolt && bash upgrade.sh [--image IMAGE]
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$INSTALL_DIR"

[ -f compose.yaml ] || { echo "[ERROR] 未找到 compose.yaml——请在安装目录运行此脚本"; exit 1; }

IMAGE=""
if [ "$1" = "--image" ] && [ -n "$2" ]; then
  IMAGE="$2"; shift 2
fi
if [ -z "$IMAGE" ]; then
  IMAGE=$(grep "image:" compose.yaml | head -1 | awk '{print $2}')
fi

echo "[AIBolt] 当前镜像: ${IMAGE}"
echo "[AIBolt] 拉取新镜像..."
docker pull "$IMAGE" || { echo "[ERROR] 拉取失败"; exit 1; }

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
  echo "[AIBolt] ✅ 升级完成，面板正常 (HTTP ${HTTP})"
else
  echo "[AIBolt] ⚠️ 面板未就绪，检查: docker logs aibolt-panel"
  exit 1
fi

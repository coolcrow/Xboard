#!/usr/bin/env bash
# AIBolt 面板升级脚本——在安装目录运行
# 用法: cd /opt/aibolt && bash upgrade.sh [--image IMAGE]
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$INSTALL_DIR"

IMAGE="${2:-}"
if [ -z "$IMAGE" ]; then
  IMAGE=$(grep "image:" compose.yaml | head -1 | awk '{print $2}')
fi

echo "[AIBolt] 当前镜像: ${IMAGE}"
echo "[AIBolt] 拉取新镜像..."
docker pull "$IMAGE" || { echo "[ERROR] 拉取失败"; exit 1; }

echo "[AIBolt] 执行数据库迁移..."
docker exec aibolt-panel php /www/artisan migrate --force 2>&1 | tail -3

echo "[AIBolt] 重启容器..."
docker compose up -d 2>&1 | tail -2

# 等待健康
RETRY=0; MAX=15
while [ $RETRY -lt $MAX ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 "http://127.0.0.1:7001/api/v1/guest/comm/config" 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && break
  RETRY=$((RETRY+1)); sleep 2
done

if [ "$HTTP" = "200" ]; then
  echo "[AIBolt] ✅ 升级完成，面板正常 (HTTP ${HTTP})"
else
  echo "[AIBolt] ⚠️ 面板未就绪，检查: docker logs aibolt-panel"
  exit 1
fi

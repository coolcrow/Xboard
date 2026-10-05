#!/usr/bin/env bash
# AIBolt 面板卸载脚本——停止容器、删除安装目录（可选保留数据）
# 用法: cd /opt/aibolt && bash uninstall.sh [--keep-data]
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
KEEP_DATA=false
[[ "${1:-}" == "--keep-data" ]] && KEEP_DATA=true

echo "[AIBolt] 停止并移除容器..."
cd "$INSTALL_DIR" && docker compose down 2>/dev/null || true
docker rm -f aibolt-panel aibolt-caddy 2>/dev/null || true

echo "[AIBolt] 移除定时任务..."
(crontab -l 2>/dev/null | grep -v "aibolt\|${INSTALL_DIR}" || true) | crontab - 2>/dev/null || true

if [ "$KEEP_DATA" = true ]; then
  # .env 含 APP_KEY——丢失则加密数据不可恢复，必须一起保留
  mkdir -p /tmp/aibolt-data-preserve
  cp -a "${INSTALL_DIR}/.docker/.data" /tmp/aibolt-data-preserve/ 2>/dev/null
  cp -a "${INSTALL_DIR}/.env" /tmp/aibolt-data-preserve/ 2>/dev/null
  echo "[AIBolt] 数据已备份到 /tmp/aibolt-data-preserve/（.docker/.data + .env）"
  echo "[AIBolt] 卸载完成（数据保留在 /tmp/aibolt-data-preserve/）"
else
  if [ -f "${INSTALL_DIR}/compose.yaml" ] || [ -f "${INSTALL_DIR}/.env" ]; then
    echo "[AIBolt] 删除安装目录 ${INSTALL_DIR}..."
    rm -rf "$INSTALL_DIR"
  else
    echo "[AIBolt] ⚠️ ${INSTALL_DIR} 下未发现 compose.yaml/.env，跳过删除（安全检查）"
  fi
  echo "[AIBolt] 卸载完成（全部数据已删除）"
fi
echo "[AIBolt] 如需清除镜像: docker rmi \$(docker images 'ghcr.io/coolcrow/xboard*' -q)"

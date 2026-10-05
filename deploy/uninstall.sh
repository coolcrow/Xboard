#!/usr/bin/env bash
# AIBolt 面板卸载脚本——停止容器、删除安装目录（可选保留数据）
# 用法: cd /opt/aibolt && bash uninstall.sh [--keep-data]
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
KEEP_DATA=false
[[ "${1:-}" == "--keep-data" ]] && KEEP_DATA=true

echo "[AIBolt] 停止并移除容器..."
cd "$INSTALL_DIR" && docker compose down 2>/dev/null || true

echo "[AIBolt] 移除定时任务..."
(crontab -l 2>/dev/null | grep -v "aibolt\|${INSTALL_DIR}" || true) | crontab - 2>/dev/null || true

if [ "$KEEP_DATA" = true ]; then
  echo "[AIBolt] 保留数据目录 ${INSTALL_DIR}/.docker/.data（--keep-data）"
  echo "[AIBolt] 卸载完成（数据保留）"
else
  echo "[AIBolt] 删除安装目录 ${INSTALL_DIR}..."
  rm -rf "$INSTALL_DIR"
  echo "[AIBolt] 卸载完成（全部数据已删除）"
fi
echo "[AIBolt] 如需清除镜像: docker rmi \$(docker images 'ghcr.io/coolcrow/xboard*' -q)"

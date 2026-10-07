#!/usr/bin/env bash
# restore.sh — 从备份文件恢复 AIBolt 面板数据
# 用法: bash restore.sh <备份文件.sqlite.gz>（在安装目录运行）
set -euo pipefail

BACKUP="${1:?用法: $0 <备份文件.sqlite.gz>}"
[ -f "$BACKUP" ] || { echo "[ERROR] 备份文件不存在: $BACKUP"; exit 1; }
[ -f compose.yaml ] || { echo "[ERROR] 请在安装目录（含 compose.yaml）运行"; exit 1; }

CONTAINER="aibolt-panel"
DB_PATH="/www/.docker/.data/database.sqlite"

echo "[AIBolt] 恢复面板数据"
echo "  备份文件: $BACKUP"
echo "  目标数据库: ${DB_PATH}"
echo ""

# 确认
read -p "此操作将覆盖当前数据库，继续？(y/N) " REPLY < /dev/tty
[ "${REPLY:-n}" = "y" ] || { echo "已取消"; exit 0; }

echo ""
echo "[1/4] 解压备份..."
TMP_DB="/tmp/aibolt-restore-$$.sqlite"
gunzip -c "$BACKUP" > "$TMP_DB"
ACTUAL_SIZE=$(stat -c%s "$TMP_DB" 2>/dev/null || echo 0)
[ "$ACTUAL_SIZE" -gt 1024 ] || { echo "[ERROR] 解压后文件异常（${ACTUAL_SIZE} bytes）"; rm -f "$TMP_DB"; exit 1; }
echo "  解压完成: ${ACTUAL_SIZE} bytes"

echo "[2/4] 验证备份完整性..."
# SQLite integrity check
INTEGRITY=$(sqlite3 "$TMP_DB" "PRAGMA integrity_check;" 2>/dev/null || echo "failed")
[ "$INTEGRITY" = "ok" ] || { echo "[ERROR] 数据库完整性检查失败: $INTEGRITY"; rm -f "$TMP_DB"; exit 1; }
USER_COUNT=$(sqlite3 "$TMP_DB" "SELECT COUNT(*) FROM sqlite_master WHERE type='table';" 2>/dev/null || echo 0)
echo "  完整性: ok | 表数量: ${USER_COUNT}"

echo "[3/4] 停止面板 + 替换数据库..."
docker exec $CONTAINER php /www/artisan octane:stop >/dev/null 2>&1 || true
docker stop $CONTAINER >/dev/null 2>&1 || true

# 备份当前数据库（防误恢复）
if docker exec $CONTAINER test -f $DB_PATH 2>/dev/null; then
  CURRENT_BK="./pre-restore-$(date +%Y%m%d%H%M).sqlite"
  docker cp "$CONTAINER:$DB_PATH" "$CURRENT_BK" >/dev/null 2>&1 && echo "  当前数据库已备份到: $CURRENT_BK"
fi

# 恢复
docker cp "$TMP_DB" "$CONTAINER:/tmp/restore.sqlite" >/dev/null 2>&1
docker start $CONTAINER >/dev/null 2>&1
sleep 2
docker exec $CONTAINER sh -c "cp /tmp/restore.sqlite $DB_PATH && rm -f /tmp/restore.sqlite"
rm -f "$TMP_DB"

echo "[4/4] 重启面板..."
docker restart $CONTAINER >/dev/null 2>&1

# 等健康
RETRY=0; MAX=15
while [ $RETRY -lt $MAX ]; do
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 5 http://127.0.0.1:7001/api/v1/guest/comm/config 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && break
  RETRY=$((RETRY+1)); sleep 2
done

if [ "$HTTP" = "200" ]; then
  echo ""
  echo "✅ 恢复完成"
  echo "  恢复前数据库备份: $CURRENT_BK（确认无误后可删除）"
  echo "  建议检查: 管理员登录 + 用户数据 + 订单记录"
else
  echo ""
  echo "⚠️ 面板返回 HTTP ${HTTP}——请检查: docker logs $CONTAINER"
  echo "  如需回退: bash restore.sh $CURRENT_BK"
fi

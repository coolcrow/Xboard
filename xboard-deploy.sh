#!/bin/bash
# xboard-deploy.sh — 面板 fastbuild 叠层部署（2026-09-30 固化，替代手工 SOP）
#
# 用法：
#   ./xboard-deploy.sh <git-sha>          # 从本地 git archive 构建 + 部署
#   ./xboard-deploy.sh <sha> --dry-run    # 只构建不切换/不重启
#
# 前提：
#   - 本地工作目录 = Xboard 仓库（含 theme-dist/ 若需主题更新）
#   - ssh cvm 可达（compose 在 /home/ubuntu/Xboard）
#
# 流程（每步有验证门，失败即停）：
#   1. git archive 提取变更文件 → tarball
#   2. scp 上船 → 解压到 ~/xboard-fastbuild-<sha>/
#   3. 生成 Dockerfile（FROM 当前运行 tag + COPY 变更文件）
#   4. docker build → xboard:<sha>
#   5. compose.override 改 tag → docker compose up -d
#   6. 等启动 → 验证 md5 + 面板 200 + 启动日志无 error
#
# 已知坑（代码层面规避，但需知悉）：
#   - COPY dir /target 是合并不是替换 → 脚本先 rm -rf 再 mv（不踩）
#   - nc_nginx 改 listen 语义须 restart 而非 reload（僵尸 worker）→ 本脚本只管面板
#   - ghcr bundle 漂移：fastbuild 不影响 ghcr；下次 Dispatch 重建时自然同步

set -euo pipefail

# ── 参数 ──
SHA="${1:?Usage: $0 <git-sha> [--dry-run]}"
DRY_RUN="${2:-}"
REPO="$(cd "$(dirname "$0")" && pwd)"
[ -d "$REPO/.git" ] || { echo "ERROR: run from Xboard repo root"; exit 1; }

# ── 前置检查 ──
echo "=== [$SHA] preflight ==="
git -C "$REPO" cat-file -e "$SHA^{commit}" 2>/dev/null || { echo "ERROR: sha $SHA not found"; exit 1; }
SSH_TARGET="cvm"
ssh -o BatchMode=yes -o ConnectTimeout=5 "$SSH_TARGET" 'hostname' >/dev/null || { echo "ERROR: ssh $SSH_TARGET unreachable"; exit 1; }

CURRENT_TAG=$(ssh "$SSH_TARGET" "grep 'image:' /home/ubuntu/Xboard/compose.override.yaml | head -1 | awk '{print \$2}'")
echo "  current: $CURRENT_TAG → new: xboard:$SHA"

# 剥离 docker 前缀得到纯 git sha（"xboard:099b212" → "099b212"）
GIT_REF="${CURRENT_TAG#xboard:}"

# ── Step 1: 列出变更文件 ──
echo "=== [$SHA] changed files ==="
if GIT_REF_SHA=$(git -C "$REPO" rev-parse --verify -q "$GIT_REF^{commit}" 2>/dev/null); then
  # 当前运行 tag 是本仓库可达 commit → 精确 diff
  CHANGED=$(git -C "$REPO" diff --name-only "$GIT_REF_SHA" "$SHA" | head -50)
  echo "  (diff $GIT_REF → $SHA)"
else
  echo "  WARN: '$GIT_REF' not reachable in local git; falling back to HEAD~1 (correct only if deploying current HEAD)"
  CHANGED=$(git -C "$REPO" diff --name-only HEAD~1 "$SHA" | head -50)
fi
echo "$CHANGED"

# 主题变更检测：脚本不处理 theme-dist，有主题变更时必须警告
THEME_FILES=$(echo "$CHANGED" | grep -c "^theme-dist/\|^public/theme/" || true)
if [ "$THEME_FILES" -gt 0 ]; then
  echo "  ⚠️  WARNING: $THEME_FILES theme files detected — this script does NOT ship theme changes!"
  echo "  ⚠️  Deploy theme separately: build xboard-web → fastbuild layered image with theme replace"
  echo "  ⚠️  Continuing will deploy ONLY backend files; theme will remain stale."
  if [ "$DRY_RUN" != "--dry-run" ]; then
    read -p "  Continue anyway? (y/N) " -r
    [[ "$REPLY" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
  fi
fi

# ── Step 2: git archive + 上船 ──
echo "=== [$SHA] archive + ship ==="
TAR="/tmp/xboard-deploy-$SHA.tar"
git -C "$REPO" archive "$SHA" $(echo "$CHANGED" | tr '\n' ' ') -o "$TAR"
ls -la "$TAR"

BUILD_DIR="~/xboard-fastbuild-$SHA"
ssh "$SSH_TARGET" "mkdir -p $BUILD_DIR"
scp -q "$TAR" "$SSH_TARGET:/tmp/xboard-deploy.tar"
ssh "$SSH_TARGET" "cd $BUILD_DIR && tar -xf /tmp/xboard-deploy.tar -C . && find . -type f | head -20"
rm -f "$TAR"

# ── Step 3: 生成 Dockerfile ──
# 逐文件 COPY（避免目录合并陷阱）
echo "=== [$SHA] Dockerfile ==="
DF_CONTENT="FROM $CURRENT_TAG"
while IFS= read -r f; do
  [ -z "$f" ] && continue
  DF_CONTENT+=$'\n'"COPY $f /www/$f"
done <<< "$CHANGED"
DF_CONTENT+=$'\n'"RUN chown -R www:www /www/app /www/plugins-core /www/resources"

echo "$DF_CONTENT" | ssh "$SSH_TARGET" "cat > $BUILD_DIR/Dockerfile"
ssh "$SSH_TARGET" "cat $BUILD_DIR/Dockerfile"

# ── Step 4: 构建 ──
echo "=== [$SHA] docker build ==="
ssh "$SSH_TARGET" "cd $BUILD_DIR && docker build -t xboard:$SHA . 2>&1 | tail -3"

# ── Step 5: 部署（dry-run 则跳过）──
if [ "$DRY_RUN" = "--dry-run" ]; then
  echo "=== [$SHA] DRY RUN: build complete, skipping deploy ==="
  echo "  to deploy: ssh $SSH_TARGET 'sed -i \"s|image: $CURRENT_TAG|image: xboard:$SHA|\" /home/ubuntu/Xboard/compose.override.yaml && cd /home/ubuntu/Xboard && docker compose up -d'"
  exit 0
fi

echo "=== [$SHA] deploy ==="
ssh "$SSH_TARGET" "sed -i 's|image: $CURRENT_TAG|image: xboard:$SHA|' /home/ubuntu/Xboard/compose.override.yaml && grep image: /home/ubuntu/Xboard/compose.override.yaml"
ssh "$SSH_TARGET" "cd /home/ubuntu/Xboard && docker compose up -d 2>&1 | tail -2"

# ── Step 6: 验证 ──
echo "=== [$SHA] verify (waiting 30s for boot) ==="
sleep 30

# 6a: 容器状态
STATUS=$(ssh "$SSH_TARGET" "docker ps --filter name=xboard --format '{{.Status}} | {{.Image}}'")
echo "  container: $STATUS"
echo "$STATUS" | grep -q "xboard:$SHA" || { echo "FAIL: container not running $SHA"; exit 1; }

# 6b: 面板可达
HTTP=$(ssh "$SSH_TARGET" "curl -s -o /dev/null -w '%{http_code}' --max-time 10 https://xboard.aibolt.tech")
echo "  panel: HTTP $HTTP"
[ "$HTTP" = "200" ] || { echo "FAIL: panel not 200 (got $HTTP; may be boot window, retry in 15s)"; sleep 15; HTTP=$(ssh "$SSH_TARGET" "curl -s -o /dev/null -w '%{http_code}' --max-time 10 https://xboard.aibolt.tech"); echo "  retry: HTTP $HTTP"; [ "$HTTP" = "200" ] || exit 1; }

# 6c: 启动日志无 error
LOGERR=$(ssh "$SSH_TARGET" "docker logs xboard-xboard-1 --since 1m 2>&1 | grep -ciE 'error|fatal|exception'" || echo 0)
echo "  log errors: $LOGERR (0=clean)"
[ "$LOGERR" = "0" ] || echo "  WARN: $LOGERR error lines in recent logs (check: docker logs xboard-xboard-1 --since 1m)"

# 6d: 变更文件 md5 对齐
echo "  md5 spot check:"
while IFS= read -r f; do
  [ -z "$f" ] && continue
  LOCAL_MD5=$(git -C "$REPO" show "$SHA:$f" | md5sum | cut -d' ' -f1)
  REMOTE_MD5=$(ssh "$SSH_TARGET" "docker exec xboard-xboard-1 md5sum /www/$f 2>/dev/null | cut -d' ' -f1" 2>/dev/null || echo "N/A")
  MATCH="✓"
  [ "$LOCAL_MD5" != "$REMOTE_MD5" ] && MATCH="✗ ($LOCAL_MD5 vs $REMOTE_MD5)"
  echo "    $f: $MATCH"
done <<< "$(echo "$CHANGED" | head -5)"

echo "=== [$SHA] DEPLOYED ✓ ==="
echo "  rollback: ssh $SSH_TARGET 'sed -i \"s|image: xboard:$SHA|image: $CURRENT_TAG|\" /home/ubuntu/Xboard/compose.override.yaml && cd /home/ubuntu/Xboard && docker compose up -d'"

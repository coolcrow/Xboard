# AIBolt 面板套件部署指南

> 适用：从零部署 AIBolt 商业面板套件（面板 + 自研前端 + 节点 agent）
> 读者：运维工程师。全部命令可直接执行，`<>` 为需替换的占位符
> 组件版本要求见文末「版本兼容矩阵」

---

## 一、套件组成与架构

```
                        ┌──────────────── 面板服务器 ────────────────┐
 用户 ──443──▶ nginx ───┼─ <user.example.com>（用户域）              │
 管理员 ─────▶（总入口） │    └ 代理 → 面板容器（SPA 用户门户）        │
                        ├─ <panel.example.com>（管理域）             │
                        │    └ 代理 → 面板容器（SPA 管理端）          │
                        │                                            │
                        │  面板容器（Docker）：                       │
                        │   Octane ≥2 workers / Horizon 队列 /       │
                        │   WS 推送服务 / SQLite(bind-mount) /       │
                        │   自研前端主题 + agent 发行镜像             │
                        │                                            │
                        │  宿主机 cron：调度任务 + 数据库备份          │
                        │                                            │
                        │  节点 agent（systemd，machine 模式）        │
                        │   └ 与面板 WS 长连接（本机混部示例）         │
                        └────────────────────────────────────────────┘
                                             ▲
  节点服务器（≥1 台） ── WS 出站长连接 ────────┘
   agent (systemd) ← 控制指令（reload/restart/upgrade）
   代理流量 ← 用户客户端

  发布链：Git 仓库 → tag → CI → GitHub Releases（二进制+校验和）
          →（可选）面板自托管镜像源 → agent 升级
```

**组件关系摘要**：

| 关系 | 说明 |
|---|---|
| nginx → 面板 | 两个域名都反代到面板容器 HTTP 端口；法务页可由 nginx 静态直出 |
| 前端主题 → 面板 | 同一 SPA 构建装进面板容器，按域名分流用户门户/管理端 |
| 面板 → agent | agent 主动出站 WS（心跳/上报/同步）；面板经 WS 下发控制指令 |
| 面板 → 发行源 | 管理端读取 GitHub Releases 列表展示版本与哈希 |
| agent → 发行源 | 升级时下载二进制（GitHub 或自托管镜像，可配） |
| cron → 面板 | 每分钟 `schedule:run`（流量重置/统计聚合/佣金结算等定时任务依赖它） |

---

## 二、前置要求

| 项 | 要求 |
|---|---|
| 面板服务器 | 2C/2G 起；Docker + Docker Compose；开放 80/443 |
| 节点服务器 | 每台 1C/1G 起；Linux systemd；只需**出站**访问面板 443（agent 只出不进） |
| 域名 | 两个：用户域（落地/注册/门户）+ 管理域；DNS A 记录指向面板服务器 |
| 证书 | 两域名各一张（nginx 侧 terminate TLS） |
| 工具（构建机） | Node.js ≥18 + pnpm；Go ≥1.25（仅维护者构建 agent 时需要） |
| 网络注意 | 面板服务器需可达 GitHub API（拉发行列表）；**中国大陆服务器无法直连 GitHub Releases 资产下载**——需按 §7.2 配置镜像源 |

---

## 三、部署顺序

```
1. 面板容器（§4）→ 2. 前端主题（§5）→ 3. nginx 双域名（§6）
→ 4. 调度与备份（§8）→ 5. 面板初始化配置（§9）
→ 6. 节点 agent（§10）→ 7.（维护者）发布链（§7）
```

---

## 四、面板部署（Docker Compose）

```bash
# 1. 获取面板代码（fork 仓库）
git clone https://github.com/<org>/Xboard.git ~/Xboard && cd ~/Xboard

# 2. compose 配置：基于样例编写，或使用以下最小骨架
#    compose.yaml 定义服务，compose.override.yaml 承载环境定制：
cat > compose.override.yaml <<'EOF'
services:
  xboard:
    image: <xboard-image-tag>          # 面板镜像
    environment:
      - OCTANE_WORKERS=2               # 必须 ≥2：单 worker 例行回收有 ~2s 502 窗口
      - OCTANE_MAX_REQUESTS=10000
    ports:
      - "127.0.0.1:7001:7001"          # 仅本机暴露，由 nginx 反代；节点 agent 走公网域名
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data:/www/.docker/.data       # SQLite（容器重建不丢）
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
EOF

# 3. 初始化 .env（数据库/应用密钥等，参照仓库 .env.example）
#    关键项：APP_KEY（生成一次长期保存）、DB 为 SQLite

# 4. 启动 + 管理员初始化
docker compose up -d
docker compose exec xboard php artisan migrate --force
docker compose exec xboard php artisan xboard:install   # 按提示创建管理员
```

**数据持久化**：SQLite、日志、主题目录均为 bind-mount——容器重建安全；容器层（含主题副本与 `frontend_theme` 设置）会丢失，重建后按 §12-C 恢复。

---

## 五、前端主题部署（自研 xboard-web）

主题是独立仓库构建的 SPA，安装进面板容器：

```bash
# 构建机
git clone https://github.com/<org>/xboard-web.git && cd xboard-web
pnpm install

# ⚠️ 关键：管理后台 API 前缀 = 面板的 secure_path（部署面板时设定，形如随机串）
#    存放于面板服务器 ~/.xboard-admin-path.txt（权限 600）
ADMIN_PATH=$(ssh <panel-server> cat ~/.xboard-admin-path.txt)

npx tsc --noEmit && pnpm build
VITE_ADMIN_PATH="$ADMIN_PATH" ./scripts/package.sh   # 产出 theme-dist/
tar czf /tmp/theme.tar.gz -C theme-dist <theme-name>
scp /tmp/theme.tar.gz <panel-server>:/tmp/
```

```bash
# 面板服务器：安装并启用
docker cp /tmp/theme.tar.gz <panel-container>:/tmp/
docker exec <panel-container> sh -c \
  "rm -rf /www/theme/<theme-name> /www/public/theme/<theme-name> \
   && tar xzf /tmp/theme.tar.gz -C /www/theme/ \
   && cp -r /www/theme/<theme-name> /www/public/theme/<theme-name>"
docker exec <panel-container> php artisan tinker --execute=\
  "admin_setting([\"frontend_theme\"=>\"<theme-name>\"]); echo \"ok\";"
```

验证：`curl https://<user.example.com>/` 返回 SPA，`/theme/<theme-name>/assets/index.js` 200。

> ⚠️ **忘带 `VITE_ADMIN_PATH` 构建的产物管理端全 404**——这是最常见事故。主题资产为稳定文件名，浏览器可能缓存旧版，验收时硬刷新。

---

## 六、nginx 双域名接入

```nginx
# <user.example.com>（用户域）：SPA 全量代理 + 法务页静态直出
server {
    listen 443 ssl; http2 on;
    server_name <user.example.com>;
    ssl_certificate     <cert>;  ssl_certificate_key <key>;

    location ~ ^/(terms|privacy|refund)\.html$ {         # 可选：法务页静态
        root /var/www/<legal-pages-dir>;
        add_header Cache-Control "no-cache";
    }
    location = /sitemap.xml { root /var/www/<legal-pages-dir>; }

    location / {
        proxy_pass http://127.0.0.1:7001;                # 面板容器
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;          # WS 需要
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 300s;
        client_max_body_size 50m;
    }
}

# <panel.example.com>（管理域）：同样反代 7001，SPA 按域名自动分流到管理端
#    （复制上段 server 块，改 server_name 与证书即可）
```

---

## 七、发布链（维护者操作；运维可跳过）

### 7.1 发版

```bash
cd Xboard-Node
git tag <vX.Y.Z> && git push origin <vX.Y.Z>
# fork 仓库的 push 触发可能被 GitHub 抑制，需要手动派发：
gh workflow run CI --repo <org>/Xboard-Node --ref <vX.Y.Z>
# CI 产出：GitHub Release（amd64/arm64 二进制 + xbctl + SHA256SUMS）
```

### 7.2 面板自托管镜像源（中国大陆节点必需）

GitHub Releases 资产在中国大陆服务器不可直连。把每个版本的 linux 二进制同步到面板：

```bash
# 构建机（有代理）：下载 + 校验
curl -sL --proxy <proxy> -o /tmp/xbn \
  "https://github.com/<org>/Xboard-Node/releases/download/<vX.Y.Z>/xboard-node-linux-amd64"
shasum -a 256 /tmp/xbn   # 与 Release 页 SHA256SUMS 比对
scp /tmp/xbn <panel-server>:/tmp/

# 面板服务器：放入 agent-dist（对外即 https://<panel.example.com>/agent-dist/）
docker exec <panel-container> mkdir -p /www/public/agent-dist/download/<vX.Y.Z>
docker cp /tmp/xbn <panel-container>:/www/public/agent-dist/download/<vX.Y.Z>/xboard-node-linux-amd64
docker exec <panel-container> chmod 755 /www/public/agent-dist/download/<vX.Y.Z>/xboard-node-linux-amd64
```

---

## 八、调度与备份（面板服务器宿主机）

```bash
# 调度：流量月重置 / 统计聚合 / 佣金结算 / 订单超时 全依赖它，缺省=功能停摆
(crontab -l 2>/dev/null; \
 echo "* * * * * docker exec <panel-container> php /www/artisan schedule:run >> ~/panel-schedule.log 2>&1") | crontab -

# 备份：SQLite 在线一致性备份（容器重建安全 ≠ 有备份）
cat > ~/panel-db-backup.sh <<'EOF'
#!/bin/sh
set -e
STAMP=$(date +%Y%m%d-%H%M); DIR=~/panel-db-backups; mkdir -p "$DIR"
docker exec <panel-container> sh -c \
  'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/db.sqlite"'
docker cp <panel-container>:/tmp/db.sqlite "$DIR/db-$STAMP.sqlite"
docker exec <panel-container> rm -f /tmp/db.sqlite
gzip -f "$DIR/db-$STAMP.sqlite"
ls -1t "$DIR"/db-*.sqlite.gz | tail -n +15 | xargs -r rm -f
EOF
chmod +x ~/panel-db-backup.sh && ~/panel-db-backup.sh
(crontab -l; echo "30 4 * * * ~/panel-db-backup.sh >> ~/panel-db-backups/backup.log 2>&1") | crontab -
```

---

## 九、面板初始化配置（管理端 → 系统配置）

部署完成后在 管理域 登录，逐项检查（配置页每项有 `?` 说明气泡）：

| 必查项 | 说明 |
|---|---|
| `app_url` / 站点名称 | 邮件链接、支付回调的基准 |
| `secure_path` | 默认值必须改随机串；**改后前端必须用新值重建重装**（§5） |
| SMTP（邮件组） | 邮箱验证/找回密码/通知依赖；配好后建议开启 `email_verify` |
| 支付渠道 | 支付配置页新增；支持动态配置表单 |
| `register_limit_by_ip_enable` | 建议开启（防批量注册） |
| `node_installer_url` | 一键安装命令用的 install.sh 地址（默认 fork 仓库 main） |
| captcha / 安全模式 | 按《系统配置说明》逐项确认 |

---

## 十、节点 agent 部署（machine 模式）

### 10.1 面板侧：创建机器

管理端 → 机器管理 → 新建机器（获得 machine token 与 machine id）→ 详情页复制**一键安装命令**。

### 10.2 节点机执行

```bash
curl -fsSL https://raw.githubusercontent.com/<org>/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine --panel https://<panel.example.com> \
       --token <machine-token> --machine-id <id>
```

安装内容：二进制（`/usr/local/bin/xboard-node`）+ 配置（`/etc/xboard-node/`）+ systemd（`Restart=always` + 能力沙箱）。心跳上线后面板绑定节点（节点管理）即可承接流量。

**中国大陆节点追加一步**——指定镜像源（改配置后 restart）：

```bash
sudo sed -i 's|^machine:|machine:\n  upgrade_download_base: "https://<panel.example.com>/agent-dist"|' \
  /etc/xboard-node/config.yml
# 同机混部面板的节点可用容器端口提速：http://127.0.0.1:7001/agent-dist
sudo systemctl restart xboard-node
```

### 10.3 agent 升级（日常）

- **面板一键**：机器管理 → 升级 agent → 版本下拉（自动带 SHA256）→ 确认。
  流程：指令 → 下载 → 哈希强制校验 → 原子换核 → 120s 启动看门狗（不健康自动回滚备份）→ 心跳确认新版本
- **应急手动**：下载二进制 → 校验 → 备份旧版 → 替换 → `systemctl restart xboard-node`
- **回滚**：`/usr/local/bin/.xboard-node.pre-upgrade.*` 换回 + restart

> agent 版本必须 ≥ 首个支持 `control.upgrade` 的版本才能面板升级；更早版本走 §10.3 手动路径一次性升级。

---

## 十一、监控要点

| 检查 | 命令/入口 | 健康标准 |
|---|---|---|
| 面板 API | `curl https://<user.example.com>/api/v1/guest/comm/config` | 200 |
| 调度心跳 | `tail ~/panel-schedule.log` | 每分钟有 DONE |
| agent 状态 | 面板机器管理 | `agent_version` 达标、机器在线 |
| 备份 | `ls ~/panel-db-backups/` | 每日一份、≤14 份保留 |
| agent 日志 | 节点机 `journalctl -u xboard-node -f` | 无持续 ERROR |

---

## 十二、Runbook

### A. 前端主题更新
按 §5 全流程执行（构建 → 打包 → 安装 → 启用）。唯一高频动作，注意 `VITE_ADMIN_PATH` 与浏览器缓存。

### B. 面板 PHP 小改热更
```bash
# ⚠️ scp 到 /tmp 的文件名必须区分（同名会互相覆盖——真实事故来源）
scp <Foo.php> <panel-server>:/tmp/<foo-unique>.php
docker cp /tmp/<foo-unique>.php <panel-container>:/www/<path>/Foo.php
docker exec <panel-container> php -l /www/<path>/Foo.php     # 语法检查
docker exec <panel-container> php artisan octane:reload
```

### C. 面板容器重建后恢复
数据/日志/主题目录为 bind-mount 不受影响；需恢复两项：
```bash
# 1) 主题重装（容器层副本被清）——同 §5 安装段
# 2) 主题启用设置被重置：
docker exec <panel-container> php artisan tinker --execute=\
  "admin_setting([\"frontend_theme\"=>\"<theme-name>\"]);"
# secure_path 存于数据库，不受影响；宿主 cron 不受影响
```

### D. 新节点上线
§10 全流程（面板建机器 → 节点执行安装命令 → 绑定节点）。

---

## 十三、注意事项（历史事故提炼，全部真实发生过）

1. 前端构建不带 `VITE_ADMIN_PATH` → 管理端 404
2. scp 同名文件互相覆盖 → 错类写入错路径 → 端点挂起（难以定位）
3. 盲改生产 `config.yml` 破坏 YAML 缩进 → agent 崩溃循环——改前先看原文件
4. 大陆服务器 GitHub 资产下载不通 → 一律走 §7.2 镜像
5. 主题稳定文件名 → 浏览器缓存旧版（验收前硬刷新）
6. fork 仓库 CI push 触发可能被抑制 → 发版必须 workflow dispatch
7. 慢链路下载 60MB+ 二进制可超 3 分钟 → agent 超时已 600s；大陆节点配本机镜像源
8. `OCTANE_WORKERS=1` 的例行回收 = 周期性 2s 502 → 必须 ≥2

---

## 十四、版本兼容矩阵

| 组件 | 最低要求 | 说明 |
|---|---|---|
| 面板（Xboard fork） | 含 machine 管理与控制通道提交 | 提供机器管理/一键安装/远程指令 API |
| 前端（xboard-web） | 与面板同代 | 管理端依赖面板新增端点（升级/发行列表等） |
| agent（Xboard-Node） | ≥ 首个含 `control.upgrade` 的 tag | 更早版本无面板升级能力，需一次手动升级 |
| node / pnpm | ≥18 / ≥8 | 前端构建 |

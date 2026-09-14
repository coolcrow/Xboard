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

## 五、前端主题

主题（自研 SPA）与面板有两种部署形态：

### 5.1 Bundle 镜像（推荐：单一制品）

面板+主题烤入同一镜像（`Dockerfile.bundle`，CI 的 bundle job 构建为
`ghcr.io/<org>/xboard:bundle`）。部署与升级 = 换镜像：

```yaml
# compose.override.yaml
services:
  xboard:
    image: ghcr.io/<org>/xboard:bundle
```

```bash
docker compose pull && docker compose up -d
```

- 容器启动钩子自动确保主题就位并启用 `frontend_theme`——**容器重建零恢复动作**
- 镜像构建不需要任何环境特定参数（见 5.3 运行时注入）
- 中国大陆服务器拉取 ghcr 受限时：构建机 `docker pull` → `docker save | gzip | ssh ... 'gunzip | docker load'`

### 5.2 独立主题安装（无 bundle 镜像时）

```bash
# 构建机（无需任何环境参数）
git clone https://github.com/<org>/xboard-web.git && cd xboard-web
pnpm install && npx tsc --noEmit && pnpm build && ./scripts/package.sh
tar czf /tmp/theme.tar.gz -C theme-dist <theme-name>
scp /tmp/theme.tar.gz <panel-server>:/tmp/ && ssh <panel-server> '
  docker cp /tmp/theme.tar.gz <panel-container>:/tmp/
  docker exec <panel-container> sh -c \
    "rm -rf /www/theme/<theme-name> /www/public/theme/<theme-name> \
     && tar xzf /tmp/theme.tar.gz -C /www/theme/ \
     && cp -r /www/theme/<theme-name> /www/public/theme/<theme-name>"
  docker exec <panel-container> php artisan tinker --execute=\
    "admin_setting([\"frontend_theme\"=>\"<theme-name>\"]); echo \"ok\";"'
```

### 5.3 快速通道：主题目录挂载卷（热更主题不动镜像）

```yaml
# compose.override.yaml 追加——宿主目录覆盖镜像内主题
services:
  xboard:
    volumes:
      - ./theme-hot/<theme-name>:/www/public/theme/<theme-name>
```

更新主题 = 把新构建的 theme-dist 内容放到宿主 `./theme-hot/<theme-name>/`，
无需触碰容器（浏览器硬刷新生效）。移除挂载即回退镜像内置版本。

### 5.4 运行时配置注入（构建与环境解耦的关键）

SPA 壳由面板 blade 渲染时注入 `window.__XW_RUNTIME__`：

- `user_domain` / `admin_domain`：域名分流（面板配置项，均公开信息）
- `admin_path`：**仅在管理域主机头下注入**——用户域页面源码不暴露管理路径；
  未配置 `admin_domain` 时视为单域部署，全域注入

因此主题构建**不需要** `VITE_ADMIN_PATH` 等环境参数；构建期 env 仅作开发兜底。

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

### 7.2 面板自托管镜像源（中国大陆节点用）

GitHub Releases 资产在中国大陆服务器不可直连。镜像目录**已 bind-mount 到宿主**
（`~/Xboard/agent-dist`，compose.override.yaml 声明，容器重建不丢），**CI 发版后自动同步**：

- **tag 推送** → CI `mirror` job 自动下载 release 资产 rsync 到镜像（跨太平洋上传约 25-30 分钟）
- **手动补同步**：Actions → CI → Run workflow → 填 tag 参数
- 认证：专用 deploy key（repo secret `CVM_SSH_KEY`，服务器侧 `command=rrsync -wo` 限制只能写该目录）

**海外节点不要配镜像源**——agent 不配 `upgrade_download_base` 时默认 GitHub 直连
（US→US 实测 8.8s 完成 90MB 下载+换核；走中国镜像要 3.5 分钟）。

仅中国大陆节点需要（§10.2）。镜像内文件可用 SHA256SUMS 自校验：

```bash
# 验证镜像完整性
cd ~/Xboard/agent-dist/download/<vX.Y.Z>/ && sha256sum -c SHA256SUMS
```

---

## 八、调度与备份（面板服务器宿主机）

```bash
# 调度：流量月重置 / 统计聚合 / 佣金结算 / 订单超时 全依赖它，缺省=功能停摆
(crontab -l 2>/dev/null; \
 echo "* * * * * docker exec <panel-container> php /www/artisan schedule:run >> ~/xboard-schedule.log 2>&1") | crontab -

# 备份：SQLite 在线一致性备份（容器重建安全 ≠ 有备份）
cat > ~/xboard-backup.sh <<'EOF'
#!/bin/sh
set -e
STAMP=$(date +%Y%m%d-%H%M); DIR=~/xboard-backups; mkdir -p "$DIR"
docker exec <panel-container> sh -c \
  'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/db.sqlite"'
docker cp <panel-container>:/tmp/db.sqlite "$DIR/db-$STAMP.sqlite"
docker exec <panel-container> rm -f /tmp/db.sqlite
gzip -f "$DIR/db-$STAMP.sqlite"
ls -1t "$DIR"/db-*.sqlite.gz | tail -n +15 | xargs -r rm -f
EOF
chmod +x ~/xboard-backup.sh && ~/xboard-backup.sh
(crontab -l; echo "30 4 * * * ~/xboard-backup.sh >> ~/xboard-backups/backup.log 2>&1") | crontab -
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

**中国大陆节点追加一步**——指定镜像源（改配置后 restart）；海外节点不要配，默认 GitHub 直连更快：

```bash
sudo sed -i 's|^machine:|machine:\n  upgrade_download_base: "https://<panel.example.com>/agent-dist"|' \
  /etc/xboard-node/config.yml
# 同机混部面板的节点可用容器端口提速：http://127.0.0.1:7001/agent-dist
sudo systemctl restart xboard-node
```

### 10.3 agent 升级（日常）

- **面板一键**：机器管理 → 升级 agent → 版本下拉（自动带 SHA256）→ 确认。
  流程：指令 → 下载 → 哈希强制校验 → 原子换核 → 120s 启动看门狗（不健康自动回滚备份）→ 心跳确认新版本
  - 海外节点：GitHub 直连，秒级下载（实测 8.8s 全程）
  - 大陆节点：走面板镜像（§7.2，CI 发版后约 30 分钟同步完成——刚发的版本别急着升，先确认镜像里有了）
- **应急手动**：下载二进制 → 校验 → 备份旧版 → 替换 → `systemctl restart xboard-node`
- **回滚**：`/usr/local/bin/.xboard-node.pre-upgrade.*` 换回 + restart

> agent 版本必须 ≥ 首个支持 `control.upgrade` 的版本才能面板升级；更早版本走 §10.3 手动路径一次性升级。
> 版本下拉数据来自 GitHub API（仓库需公开可读，或后续接入 token）。

---

## 十一、监控要点

| 检查 | 命令/入口 | 健康标准 |
|---|---|---|
| 面板 API | `curl https://<user.example.com>/api/v1/guest/comm/config` | 200 |
| 调度心跳 | `tail ~/xboard-schedule.log` | 每分钟有 DONE |
| agent 状态 | 面板机器管理 | `agent_version` 达标、机器在线 |
| 备份 | `ls ~/xboard-backups/` | 每日一份、≤14 份保留 |
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
§10 全流程（面板建机器 → 节点执行安装命令 → 绑定节点）。之后三步必做：

1. **补全节点 protocol_settings**（管理端 → 节点编辑）——必须是完整嵌套结构，trojan 示例：
   `tls=1` + `tls_settings={server_name:<域名>, allow_insecure:true}`（自签证书必须 allow_insecure）。
   平铺/残缺结构会导致 agent 侧 buildNodeConfig 崩溃（hysteria 分支直接解引用嵌套键），
   且订阅生成缺 sni/skip-cert-verify → 客户端 `x509: no IP SANs` 拒连。
   生成器已加回退链（tls_settings → 顶层 allow_insecure → cert_config.cert_domain，
   commit 847f2bd），但规范结构仍是首选。
2. **节点机安全加固**：`PasswordAuthentication no` + `PermitRootLogin prohibit-password`
   （改后先用另一会话验证密钥登录仍通）；防火墙仅留 `ssh + <节点端口>/tcp|udp`；
   `/etc/sysctl.d/99-security.conf` 加 SYN cookies / rp_filter / 禁 ICMP 重定向。
3. **E2E 冒烟**：按 §12.E 跑 mihomo 过流量，确认连通 + 面板计量。

### E. 新节点 E2E 冒烟测试（mihomo，在面板服务器上执行）
> 家宽跨境链路可能被 QoS（实测 TLS 握手 23s vs 服务器侧 0.5s），验证一律在服务器侧做。

```bash
mkdir -p /tmp/mihomo-e2e && cd /tmp/mihomo-e2e
curl -sL -o mihomo.gz https://github.com/MetaCubeX/mihomo/releases/download/v1.19.30/mihomo-linux-amd64-v1.19.30.gz
gunzip mihomo.gz && chmod +x mihomo

# 拉订阅取目标节点行（flag=meta 输出 Clash YAML）
curl -s -H "User-Agent: clash-meta/v1.19.0" \
  "https://<panel.example.com>/api/v1/client/subscribe?token=<user-token>&flag=meta" | grep <节点名>

# 原样填入（一行不改，验的就是订阅本身）：
cat > config.yaml <<'EOF'
mode: rule
mixed-port: 7899
rules:
  - MATCH,<节点名>
proxies:
  - { <订阅里的节点行> }
EOF
setsid nohup ./mihomo -f config.yaml -d $PWD > run.log 2>&1 &
sleep 3
curl -s -x http://127.0.0.1:7899 -o /dev/null -w "204测试: %{http_code}\n" https://www.gstatic.com/generate_204   # 期望 204
curl -s -x http://127.0.0.1:7899 https://api.ip.sb/ip                                                    # 期望节点 IP
curl -s -x http://127.0.0.1:7899 -o /dev/null -w "下载: %{size_download}B\n" "https://speed.cloudflare.com/__down?bytes=52428800"

# ≤60s 后面板 tinker 验计量：User u+d 增量 ≈ 下载字节（含 TLS 开销 ±0.5%）
docker exec -i <panel-container> php /www/artisan tinker \
  <<< '$u=App\Models\User::find(<id>);echo ($u->u+$u->d)/1048576,"MB\n";'
pkill -x mihomo
```

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
9. 节点 protocol_settings 残缺（无嵌套 tls_settings）→ 订阅缺 sni/skip-cert-verify → 客户端 `x509: no IP SANs` 拒连（生成器已加回退链，规范结构仍是首选）
10. Hysteria2 冷启动证书加载晚于内核 1ms → `TLS required` 崩溃——agent v1.0.6-fork1 起内置证书就绪等待（startKernel 轮询 ≤2s）
11. 面板 `:7001` 直连被云安全组拦截——agent 与客户端统一走 443 域名（nginx 反代）；订阅 URL 即 `https://<域名>/api/v1/client/subscribe?token=xxx&flag=meta`

---

## 十四、版本兼容矩阵

| 组件 | 最低要求 | 说明 |
|---|---|---|
| 面板（Xboard fork） | 含 machine 管理与控制通道提交 | 提供机器管理/一键安装/远程指令 API |
| 前端（xboard-web） | 与面板同代 | 管理端依赖面板新增端点（升级/发行列表等） |
| agent（Xboard-Node） | ≥ 首个含 `control.upgrade` 的 tag | 更早版本无面板升级能力，需一次手动升级 |
| node / pnpm | ≥18 / ≥8 | 前端构建 |

---

## 十五、许可与分发格局

| 资产 | 许可/可见性 | 说明 |
|---|---|---|
| Xboard（面板后端） | MIT · 公开仓 | 上游传承，可自由使用 |
| Xboard-Node（agent） | 源码 MPL-2.0 · 二进制 GPL-3.0（链接 sing-box）· 公开仓 | 开源分发 |
| xboard-web（前端主题） | 免费使用 · 源码仓可见性待定 | 镜像内分发 |
| ghcr bundle 镜像 | 公开包（含烤入主题） | 免登录拉取 |

客户部署链：compose 直接拉镜像起面板 → 公开 install.sh 加节点（见 INSTALL-CUSTOMER.md）。

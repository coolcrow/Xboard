# AIBolt Panel (Xboard Fork)

基于 [Xboard](https://github.com/cedar2025/Xboard) 的面板分支，包含自研前端主题、节点 Agent 管理与远程升级。

## 一行命令安装（推荐）

```bash
# 交互式安装（自动装 Docker/生成密钥/启动容器/创建管理员/注册定时任务）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash

# 无人值守（自动化部署）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash -s -- \
  --unattended --admin-email admin@example.com --admin-password Secret123 --port 7001

# 中国镜像加速（ghcr 直连慢/不通时）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash -s -- \
  --mirror https://mirror.ghproxy.com

# 离线安装（下载 [Release](https://github.com/coolcrow/Xboard/releases) 离线包，解压后运行）
sudo bash install.sh
```

安装脚本自动完成：Docker 检测/安装 → 镜像拉取 → APP_KEY 生成 → 容器启动 → 管理员创建 →
安全基线应用（IP 限流/邮箱验证/HTTPS）→ 定时任务注册（调度/备份/健康检查）→ 结果验证。

> 安装后配置反向代理、节点接入、支付等见下方 **安装后配置清单**。
> 升级/卸载：`bash upgrade.sh` / `bash uninstall.sh`（在安装目录运行，随 install.sh 一起部署）。

---

## 手动部署（compose 方式）

一台装有 Docker 的服务器，复制粘贴即可跑起面板 + 内置主题：

```bash
mkdir -p aibolt && cd aibolt

cat > compose.yaml << 'EOF'
services:
  xboard:
    image: ghcr.io/coolcrow/xboard:bundle
    restart: always
    ports: ["127.0.0.1:7001:7001"]  # 仅本机，生产由 nginx 反代；直开 7001 会暴露明文 HTTP
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
    environment:
      - RESOURCE_PROFILE=balanced
      - OCTANE_WORKERS=2
EOF

echo "APP_KEY=base64:$(openssl rand -base64 32)
APP_ENV=production" > .env

docker compose up -d
sleep 15
docker compose exec -e ADMIN_ACCOUNT=admin@example.com xboard php artisan xboard:install
# 安装完成后会打印自动生成的管理员密码
# 同时自动应用推荐基线配置（IP 注册限流/邮箱验证/HTTPS/文档中心/WS/试用/提醒）

docker compose restart
# ↑ 必须：安装以 root 建库，重启触发属主修复（www 用户可写）
# 之后浏览器打开 http://<服务器IP>:7001 登录 → 自动进入管理端
# （省略 -e ADMIN_ACCOUNT 则进入交互式安装）
```

> 没装 Docker？先执行 `curl -fsSL https://get.docker.com | sh`
>
> 之后的域名接入、支付/邮件配置、节点接入见下方 **安装后配置清单** 与 [完整部署文档](./docs/DEPLOYMENT.md)；面向最终用户的安装手册见 [docs/INSTALL-CUSTOMER.md](./docs/INSTALL-CUSTOMER.md)。

## 套件组成

| 组件 | 仓库 | 说明 |
|---|---|---|
| 面板（本文档） | `coolcrow/Xboard` | Laravel + Octane，SQLite |
| 前端主题 | `coolcrow/xboard-web` | React SPA（用户门户 + 管理端 + 落地页 + 帮助文档） |
| 节点 Agent | `coolcrow/Xboard-Node` | Go，sing-box/xray 双内核，远程升级 |

## 部署方式

> 镜像与仓库均公开，无需任何认证即可拉取。

### 方式 A：Bundle 镜像（推荐）

面板 + 前端主题合一镜像，部署后零额外安装。**上方「快速开始」即此方式的完整流程**，生产环境差异仅两点：

1. 端口绑回 `127.0.0.1:7001:7001`，由 nginx 双域名反代（配置见 [docs/DEPLOYMENT.md §6](./docs/DEPLOYMENT.md)）
2. 按需挂载 `./agent-dist:/www/public/agent-dist` 用作大陆节点的 agent 下载镜像源（可选，见 §7.2）

```bash
docker pull ghcr.io/coolcrow/xboard:bundle
# compose 编写与初始化同「快速开始」
```

**主题已内置**——启动钩子自动确保主题就位并启用，容器重建零恢复动作。

### 方式 B：从源码构建（需要定制面板时）

```bash
git clone https://github.com/coolcrow/Xboard.git ~/Xboard
cd ~/Xboard

# 构建含主题的 bundle 镜像（webtoken = 可读 xboard-web 私有仓库的 PAT）
docker build -t xboard-custom . -f Dockerfile.bundle \
  --secret id=webtoken,src=<PAT文件路径>

# 后续同方式 A（compose 里 image 改为 xboard-custom）
```

### 方式 C：从现有服务器冷迁移

```bash
# 旧服务器
docker save ghcr.io/coolcrow/xboard:bundle | gzip > panel-image.tar.gz
cd ~/Xboard && tar czf panel-data.tar.gz .env .docker/.data storage/

# 新服务器
docker load < panel-image.tar.gz
tar xzf panel-data.tar.gz -C ~/Xboard/
docker compose up -d
```

## 安装后配置清单

| # | 配置项 | 位置 | 说明 |
|---|---|---|---|
| 1 | secure_path | 系统配置 → 安全 | 改随机串（前端运行时注入，无需重建） |
| 2 | app_url | 系统配置 → 基础 | `https://<面板域名>` |
| 3 | user_domain / admin_domain | 系统配置 → 节点与通讯 | SPA 按域名分流 |
| 4 | SMTP | 系统配置 → 邮件 | 邮箱验证/找回密码/通知 |
| 5 | 支付渠道 | 支付管理 | 配置后即可收款 |
| 6 | 套餐 | 套餐管理 | 至少 1 个可售套餐 |
| 7 | 调度 cron | 服务器 crontab | 见下方 |
| 8 | 备份 cron | 服务器 | 见 docs/DEPLOYMENT.md §8 |
| 9 | ~~注册限流~~ | — | ✅ 安装时已自动配置（3 次/60 分钟/IP） |
| 10 | 告警通道 | 系统配置 → 节点与通讯 | Telegram / 邮箱 |

**已内置的登录安全防护**（安装即生效，无需配置）：

| 防护 | 机制 |
|---|---|
| IP 级限流 | 同一 IP 每分钟最多 10 次登录/注册/找回密码 |
| 账户锁定 | 同一邮箱连续 5 次密码错误 → 锁定 60 分钟 |
| 失败留痕 | 每次失败登录记录邮箱+原因+IP（管理员可查） |
| TG 撞库告警 | 锁定触发时推送 Telegram（需配置 Bot，10 分钟冷却） |
| 时序防护 | 未知邮箱也执行 dummy bcrypt，防响应时间枚举 |
| 人机验证 | 支持 Cloudflare Turnstile（需自配 Key，国内网络慎用） |

### 调度 cron（必须配置）

```bash
(crontab -l 2>/dev/null; \
 echo "* * * * * docker exec $(basename ~/Xboard | tr '[:upper:]' '[:lower:]')-xboard-1 php /www/artisan schedule:run >> ~/xboard-schedule.log 2>&1") | crontab -
```

流量月重置、统计聚合、佣金结算、订单超时**全部依赖此 cron**，缺省 = 功能停摆。

## 节点接入（面板装好后 2 分钟/台）

### 第一步：面板创建机器

登录管理端 → **机器管理** → **新建机器** → 复制生成的一键安装命令。

### 第二步：节点服务器执行

```bash
# 从面板复制的命令长这样：
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <面板生成的token> --machine-id <面板分配的ID>

# 中国大陆节点（agent-dist 镜像加速 + 固定版本）：
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token TOKEN --machine-id 1 \
       --mirror https://panel.yourdomain.com/agent-dist --version v1.0.6
```

Agent 安装后自动通过出站 WSS 连接面板（节点机零入站管理端口）。

### 第三步：面板绑定节点

**节点管理** → **新建节点** → 选择关联机器、协议（hysteria2/trojan）、端口 → 保存后用户订阅立即可见。

### 可选：中转保护架构（推荐 ≥30 用户时）

用户直连中转入口，落地 IP 不暴露给 GFW（被墙率趋近零）：

```bash
# 在中转机上（如香港三网优化 VPS）
git clone https://github.com/coolcrow/Xboard-Node.git /tmp/bn
sudo bash /tmp/bn/tools-relay/relay-setup.sh --landing <落地IP> --ports 443,18443

# 面板节点 server 字段填中转 IP（勿填落地 IP）
```

详细架构说明、带宽规划、换落地操作见 [Xboard-Node tools-relay/README.md](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)。

## 完整部署文档

详细的架构说明、nginx 配置、镜像源搭建、故障排查等见：

**[docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md)**

## 技术栈

- Backend: Laravel 11 + Octane (2 workers) + Horizon
- Frontend: React 18 + TypeScript + Tailwind CSS（自研主题）
- Agent: Go + sing-box / xray-core
- DB: SQLite (bind-mount) + Redis
- Deploy: Docker Compose + GHCR

## ⚠️ Disclaimer

Based on [Xboard](https://github.com/cedar2025/Xboard) (MIT License). For educational and commercial use within the terms of the original license.

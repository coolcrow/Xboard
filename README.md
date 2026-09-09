# AIBolt Panel (Xboard Fork)

基于 [Xboard](https://github.com/cedar2025/Xboard) 的商业面板分支，包含自研前端主题、节点 Agent 管理与远程升级。

## 套件组成

| 组件 | 仓库 | 说明 |
|---|---|---|
| 面板（本文档） | `coolcrow/Xboard` | Laravel + Octane，SQLite |
| 前端主题 | `coolcrow/xboard-web` | React SPA（用户门户 + 管理端 + 落地页 + 帮助文档） |
| 节点 Agent | `coolcrow/Xboard-Node` | Go，sing-box/xray 双内核，远程升级 |

## 部署方式

> 本仓库为**私有仓库**——所有拉取操作需先完成 GitHub 认证。

### 方式 A：Bundle 镜像（推荐）

面板 + 前端主题合一镜像，部署后零额外安装。

```bash
# 1. GHCR 认证（私有镜像需要 PAT）
echo "<你的GitHub PAT>" | docker login ghcr.io -u coolcrow --password-stdin

# 2. 拉取镜像
docker pull ghcr.io/coolcrow/xboard:bundle

# 3. 编写 compose
mkdir -p ~/Xboard && cd ~/Xboard
cat > compose.yaml << 'EOF'
services:
  xboard:
    image: ghcr.io/coolcrow/xboard:bundle
    ports:
      - "127.0.0.1:7001:7001"
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
    restart: always
EOF

# 4. 环境变量
cat > .env << 'EOF'
APP_KEY=<openssl rand -base64 32 生成>
APP_ENV=production
DB_CONNECTION=sqlite
EOF

# 5. 启动 + 初始化
docker compose up -d
docker compose exec xboard php artisan migrate --force
docker compose exec xboard php artisan xboard:install
# 按提示创建管理员

# 6. nginx 反代（两个域名 → 127.0.0.1:7001）
#    完整配置见 docs/DEPLOYMENT.md §6
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
| 9 | 注册限流 | 系统配置 → 安全 | 开启 IP 注册限制 |
| 10 | 告警通道 | 系统配置 → 节点与通讯 | Telegram / 邮箱 |

### 调度 cron（必须配置）

```bash
(crontab -l 2>/dev/null; \
 echo "* * * * * docker exec $(basename ~/Xboard | tr '[:upper:]' '[:lower:]')-xboard-1 php /www/artisan schedule:run >> ~/panel-schedule.log 2>&1") | crontab -
```

流量月重置、统计聚合、佣金结算、订单超时**全部依赖此 cron**，缺省 = 功能停摆。

## 节点 Agent 接入

面板初始化后，进入 **管理端 → 机器管理 → 新建机器**，复制一键安装命令到节点服务器执行。

> ⚠️ Agent 仓库同为私有——一键安装命令需确认 `node_installer_url` 指向可访问的 install.sh 地址（可在系统配置 → 节点与通讯 中修改）。

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

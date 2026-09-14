# AIBolt Panel (Xboard Fork)

基于 [Xboard](https://github.com/cedar2025/Xboard) 的面板分支，包含自研前端主题、节点 Agent 管理与远程升级。

## 快速开始（约 5 分钟）

一台装有 Docker 的服务器，复制粘贴即可跑起面板 + 内置主题：

```bash
mkdir -p aibolt && cd aibolt

cat > compose.yaml << 'EOF'
services:
  xboard:
    image: ghcr.io/coolcrow/xboard:bundle
    restart: always
    ports: ["7001:7001"]        # 生产环境建议改 127.0.0.1:7001:7001 并由 nginx 反代
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
# 安装完成后会打印自动生成的管理员密码，用它在 http://<服务器IP>:7001 登录管理端
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
| 9 | 注册限流 | 系统配置 → 安全 | 开启 IP 注册限制 |
| 10 | 告警通道 | 系统配置 → 节点与通讯 | Telegram / 邮箱 |

### 调度 cron（必须配置）

```bash
(crontab -l 2>/dev/null; \
 echo "* * * * * docker exec $(basename ~/Xboard | tr '[:upper:]' '[:lower:]')-xboard-1 php /www/artisan schedule:run >> ~/xboard-schedule.log 2>&1") | crontab -
```

流量月重置、统计聚合、佣金结算、订单超时**全部依赖此 cron**，缺省 = 功能停摆。

## 节点 Agent 接入

面板初始化后，进入 **管理端 → 机器管理 → 新建机器**，复制一键安装命令到节点服务器执行（agent 仓库与发行均公开，无需凭证）。

> 中国大陆节点装不了时，可为 agent 配置面板镜像源加速，见 [docs/DEPLOYMENT.md §7.2 / §10.2](./docs/DEPLOYMENT.md)。

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

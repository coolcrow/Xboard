# AIBolt 自部署安装手册（客户版）

从一台空服务器到可售卖的加速服务订阅平台。全程约 40 分钟（不含域名 DNS 生效等待）。

> 本手册面向**购买整套系统自运营**的客户。技术细节文档（运维 runbook）见 `DEPLOYMENT.md`。

---

## 你将获得

| 组件 | 说明 |
|---|---|
| 订阅面板 | 用户注册/登录/套餐订购/流量计量/工单，含管理后台 |
| 前端站点 | 落地页 + 用户面板 + 帮助文档中心（可关） |
| 节点 agent | 一条命令装到任意海外 VPS，自动注册回面板 |
| 双协议 | Hysteria2（UDP）+ Trojan（TCP） |

**你需要自备**：一台面板服务器（1C1G 起）、至少一台海外节点 VPS、两个域名（或一个域名的两个子域）。

---

## 第一步：面板服务器准备（10 分钟）

```bash
# 1. 安装 Docker + Compose 插件（Ubuntu 为例）
curl -fsSL https://get.docker.com | sh

# 2. 建立工作目录
mkdir -p ~/aibolt && cd ~/aibolt
```

创建 `compose.yaml`：

```yaml
services:
  xboard:
    image: ghcr.io/coolcrow/xboard:bundle
    restart: always
    ports:
      - "7001:7001"
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./agent-dist:/www/public/agent-dist
    environment:
      - RESOURCE_PROFILE=balanced
      - OCTANE_WORKERS=2
```

创建 `.env`（`APP_KEY` 生成一次后长期保存，勿丢失）：

```bash
cat > .env <<EOF
APP_KEY=base64:$(openssl rand -base64 32)
APP_ENV=production
DB_DATABASE=.docker/.data/database.sqlite
EOF
```

启动并初始化管理员：

```bash
docker compose up -d
sleep 15
docker compose exec xboard php artisan xboard:install \
  --admin_email=you@example.com --admin_password=你的管理员密码
```

> 初始化命令的具体参数以镜像内 `php artisan xboard:install --help` 为准。

**自检**：`curl http://127.0.0.1:7001/api/v1/guest/comm/config` 返回 JSON 即面板就绪。

---

## 第二步：域名接入（10 分钟 + DNS 等待）

两个子域指向面板服务器 IP：

- `www.yourdomain.com` → 用户站点（落地页/登录/面板）
- `panel.yourdomain.com` → 管理后台（独立域名隔离）

Nginx 反代示例（两个 server 块，均反代 127.0.0.1:7001）：

```nginx
server {
    listen 443 ssl;
    server_name www.yourdomain.com;   # 另一块改成 panel.yourdomain.com
    ssl_certificate     /path/fullchain.pem;
    ssl_certificate_key /path/privkey.pem;
    location / {
        proxy_pass http://127.0.0.1:7001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;     # 节点 WS 需要
        proxy_set_header Connection "upgrade";
    }
}
```

> 管理后台路径是随机生成的安全路径，仅在管理域名登录后可见。

然后在管理后台（`panel.yourdomain.com`）→ 系统配置：

| 必配项 | 值 |
|---|---|
| 站点名称 | 你的品牌（用户侧全部品牌位自动跟随） |
| 站点 URL | `https://panel.yourdomain.com` |
| 用户域名 | `https://www.yourdomain.com` |
| 管理域名 | `https://panel.yourdomain.com` |
| 订阅 URL | `https://www.yourdomain.com`（用户订阅链接用这个域名） |

---

## 第三步：内容定制（10 分钟）

管理后台 → 系统配置 → 前端：

| 设置 | 说明 |
|---|---|
| **落地页实测数据**（JSON） | 你自己节点的实测数字，留空则落地页隐藏整个数据面板。格式：`{"probe":"华东参考点 · ICMP","date":"2026-10","latency":"155","jitter":"±2.1","throughput":"2.0-3.5"}` |
| **帮助文档中心** | 默认关闭。开启后显示内置教程中心（内容为通用加速器使用教程）；关掉后所有文档入口消失 |
| **管理端品牌名** | 管理后台顶栏显示的品牌 |

⚠️ **实测数据必须是你自己的测量值**——写别人的数字等于对用户撒谎，出了工单说不清。

## 第四步：套餐与支付

1. 管理后台 → 套餐管理：创建你的套餐（流量/带宽/设备数/各周期价格）
2. 系统配置 → 支付：接入支付宝当面付 / Stripe / BTCPay 等
3. 系统配置 → 邮件：配置 SMTP（注册验证、找回密码都依赖它）

## 第五步：添加节点（5 分钟/台）

1. 管理后台 → 机器管理 → 新建机器 → 复制**一键安装命令**
2. SSH 到你的海外节点 VPS，粘贴执行（需要 root）
3. 等约 30 秒，机器页显示心跳在线
4. 节点管理 → 添加节点并绑定这台机器：

| 协议 | 推荐配置 |
|---|---|
| Trojan | TCP，端口自选（如 18443），证书选"自签"并填你的域名 |
| Hysteria2 | UDP 443，同上 |

5. 防火墙放行对应端口
6. **验证**：注册一个测试账号 → 绑套餐 → 仪表盘复制订阅链接 → 导入 Clash 系客户端 → 能上网、流量数字在涨，即全链路就绪

---

## 日常运维速查

| 操作 | 命令/入口 |
|---|---|
| 面板升级 | `docker compose pull && docker compose up -d` |
| 节点 agent 升级 | 管理后台 → 机器管理 → 升级 agent（选版本，自动校验+回滚） |
| 数据备份 | SQLite 在 `./.docker/.data/`，整目录打包即可 |
| 日志 | `docker compose exec xboard tail -f storage/logs/laravel.log` |

## 常见问题

**订阅 403？** 账号无有效套餐或流量耗尽——设计行为，先检查套餐绑定。

**节点装完不上线？** 检查节点机到面板 443 的连通性（`curl https://panel.yourdomain.com`），以及 machine token 是否复制完整。

**前端白屏？** `docker compose exec xboard ls /www/public/theme/` 应有主题目录；缺失则重建容器。

---

*本项目免费分发。技术支持渠道见项目主页说明。*

---

## 软件构成与许可（技术性说明）

| 组件 | 许可 | 你获得的权利 |
|---|---|---|
| 前端主题（含落地页/用户面板/管理端 UI） | 免费使用（源码不公开） | 自由部署使用 |
| 面板后端 | MIT（上游 Xboard 传承） | 使用、修改，保留版权声明 |
| 节点 agent | 源码 MPL-2.0 / 二进制 GPL-3.0（含 sing-box） | 使用、修改、再分发（遵循相应开源条款） |
| 镜像 `ghcr.io/coolcrow/xboard` | 公开拉取 | 自由使用 |

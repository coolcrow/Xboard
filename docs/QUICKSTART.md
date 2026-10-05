# AIBolt 快速开始

> 5 分钟从零到可用的代理面板

## 前置要求

| 项 | 要求 |
|---|---|
| 面板服务器 | 2C/2G+ Linux（Ubuntu/Debian/CentOS），Docker 自动安装 |
| 节点服务器 | 1C/1G+ Linux，仅出站 443（agent 主动连面板） |
| 域名 | 可选（有域名可上 HTTPS + 自动续期） |

## 一、安装面板（1 分钟）

```bash
# 交互式安装（推荐首次使用）
curl -fsSL https://install.aibolt.tech | sudo bash

# 或无人值守（适合自动化部署）
curl -fsSL https://install.aibolt.tech | sudo bash -s -- \
  --unattended \
  --admin-email admin@yourdomain.com \
  --admin-password YourSecurePass123 \
  --port 7001
```

安装脚本自动完成：
- ✅ Docker + Docker Compose 检测/安装
- ✅ 镜像拉取（支持中国镜像加速 `--mirror`）
- ✅ 随机 APP_KEY + .env 生成
- ✅ SQLite 数据库 + 内置 Redis（零外部依赖）
- ✅ 管理员账号创建 + 安全基线自动应用
- ✅ 定时任务注册（调度/备份/健康检查）
- ✅ 健康检查 + 自动重启

安装完成后会输出管理面板地址和初始凭证。

## 二、配置反向代理（可选，有域名时）

面板监听 `127.0.0.1:7001`，由 nginx/Caddy 转发：

```nginx
server {
    listen 443 ssl http2;
    server_name panel.yourdomain.com;

    ssl_certificate     /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:7001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 300s;
        client_max_body_size 50m;
    }
}
```

配置好后登录管理后台 → 系统配置 → 将 `app_url` 设为 `https://panel.yourdomain.com`。

## 三、添加节点服务器（1 分钟/台）

1. 登录管理后台 → **机器管理** → **新建机器**
2. 复制生成的**一键安装命令**
3. 在节点服务器上执行：

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <面板生成的token> \
       --machine-id <面板分配的ID>
```

节点 agent 自动安装并连接面板（出站 WSS 长连接，节点机零入站端口）。

> 中国大陆节点机追加镜像加速：
> ```bash
> sudo sed -i 's|^machine:|machine:\n  upgrade_download_base: "https://panel.yourdomain.com/agent-dist"|' \
>   /etc/xboard-node/config.yml
> sudo systemctl restart xboard-node
> ```

## 四、创建节点（30 秒）

1. 管理后台 → **节点管理** → **新建节点**
2. 选择关联机器、协议（hysteria2/trojan）、端口
3. 保存后节点自动同步到 agent → 用户订阅立即可见

## 五、用户使用流程

```
用户注册（邮箱验证码）→ 选择套餐 → 支付宝扫码 → 获取订阅链接 → 导入客户端 → 开始使用
```

## 日常运维

```bash
cd /opt/aibolt

# 升级面板
bash upgrade.sh

# 查看日志
docker logs -f aibolt-panel

# 手动备份
docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/bk.sqlite"' \
  && docker cp aibolt-panel:/tmp/bk.sqlite ./backup-$(date +%Y%m%d).sqlite

# 卸载（--keep-data 保留数据）
bash uninstall.sh [--keep-data]
```

## FAQ

<details>
<summary>面板启动后无法访问？</summary>

```bash
docker logs aibolt-panel          # 查看启动日志
curl http://127.0.0.1:7001        # 本地测试
ss -tln | grep 7001               # 确认监听
```
</details>

<details>
<summary>如何修改管理员密码？</summary>

登录用户中心 → 个人设置 → 修改密码。或通过管理员邮箱重置。
</details>

<details>
<summary>节点连不上面板？</summary>

1. 确认面板域名/端口从节点机可达：`curl https://panel.yourdomain.com`
2. 检查机器 token 是否正确（面板机器管理页查看）
3. 节点机日志：`journalctl -u xboard-node -f`
</details>

<details>
<summary>如何开启人机验证（Turnstile）？</summary>

管理后台 → 系统配置 → 安全 → 人机验证：
1. 到 [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) 创建站点
2. 将 Site Key 和 Secret Key 填入面板配置
3. 开启验证开关

安装脚本已预设推荐安全基线（登录限流/注册限制/邮箱验证），Turnstile 需手动配置 key。
</details>

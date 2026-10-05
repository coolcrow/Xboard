# AIBolt 快速开始

> 一行命令，5 分钟从零到可用的代理面板

## 前置要求

| 项 | 要求 |
|---|---|
| 面板服务器 | 2C/2G+ Linux，网络可达 |
| 域名 | 可选（有域名 → 自动 HTTPS） |
| 节点服务器 | 1C/1G+ Linux，只需出站访问面板 443 |

## 一、安装面板（1 分钟）

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash
```

脚本会问你两个问题（域名和管理员邮箱），其余全部自动。

| 自动完成 | 说明 |
|---|---|
| Docker | 未装则自动安装 |
| 镜像 | 自动探测最优路径（直连 / 中国镜像 / 离线包） |
| HTTPS | 有域名 → 内置 Caddy 自动 Let's Encrypt；无域名 → IP 直达 |
| 管理员 | 邮箱 + 密码（自动生成或指定） |
| 安全基线 | IP 限流、邮箱验证、登录锁定 |
| 定时任务 | 调度(每分钟) + 备份(每日) + 健康检查(每5分钟) |

也可全部通过参数指定（零交互）：

```bash
curl -fsSL ... | sudo bash -s -- --domain panel.example.com --email admin@x.com
```

安装完成直接输出面板地址、管理员账号密码——浏览器打开即用。

## 二、添加节点服务器（1 分钟/台）

1. 登录管理后台 → **机器管理** → **新建机器**
2. 复制生成的**一键安装命令**到节点服务器执行：

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <面板生成的token> --machine-id <面板分配的ID>
```

Agent 自动通过出站 WSS 连接面板（节点机零入站管理端口）。

> 中国大陆节点追加镜像加速：
> ```bash
> sudo bash install.sh --mode machine --panel https://... --token T --machine-id 1 \
>      --mirror https://panel.yourdomain.com/agent-dist --version v1.0.6
> ```

## 三、创建节点（30 秒）

管理后台 → **节点管理** → **新建节点** → 选择关联机器、协议（hysteria2/trojan）、端口 → 保存后用户订阅立即可见。

## 日常运维

```bash
cd /opt/aibolt

# 升级面板
bash upgrade.sh

# 查看日志
docker logs -f aibolt-panel

# 手动备份
docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/bk"' \
  && docker cp aibolt-panel:/tmp/bk ./backup-$(date +%Y%m%d).sqlite

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

无域名模式：确认云安全组已放行面板端口（默认 7001）。
有域名模式：确认 DNS A 记录已指向服务器 IP，且 80/443 未被占用。
</details>

<details>
<summary>如何修改管理员密码？</summary>

登录用户中心 → 个人设置 → 修改密码。或通过管理员邮箱重置。
</details>

<details>
<summary>节点连不上面板？</summary>

1. 确认面板域名从节点机可达：`curl https://panel.yourdomain.com`
2. 检查机器 token 是否正确（面板机器管理页查看）
3. 节点机日志：`journalctl -u xboard-node -f`
</details>

<details>
<summary>如何开启人机验证（Turnstile）？</summary>

管理后台 → 系统配置 → 安全 → 人机验证：
1. 到 [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) 创建站点
2. 将 Site Key 和 Secret Key 填入面板配置
3. 开启验证开关

安装脚本已预设推荐安全基线，Turnstile 需手动配置 key。
</details>

<details>
<summary>可选：中转保护架构（推荐 ≥30 用户）</summary>

```bash
# 在中转机上（如香港三网优化 VPS）
git clone https://github.com/coolcrow/Xboard-Node.git /tmp/bn
sudo bash /tmp/bn/tools-relay/relay-setup.sh --landing <落地IP> --ports 443,18443
```

用户只见中转入口，落地 IP 不暴露给 GFW。详见 [tools-relay/README.md](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)。
</details>

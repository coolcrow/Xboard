# AIBolt 快速开始

> [English Version](./QUICKSTART-EN.md) | [中文版](./QUICKSTART.md)

> 一行命令，5 分钟从零到可用

## 架构概览

系统分两条路径——**管理面**（用户注册/购买/后台管理）和**数据面**（用户实际走代理流量）：

```
─── 管理面（HTTPS，低带宽）───────────────────────────────────────

用户/管理员 ────▶ 面板服务器（你的主服务器）
                   · 注册 / 登录 / 购买套餐 / 获取订阅链接
                   · 管理后台（机器 / 节点 / 套餐 / 支付）
                   · 自动 HTTPS（Caddy + Let's Encrypt）
                   · 数据库（SQLite）+ 每日备份
                   · 支付回调（支付宝异步通知到面板）

面板 ◄─── agent 出站 WSS 长连接（接入节点和落地节点都装 agent）
         · 心跳 / 资源上报 / 远程指令（重启/升级/重载）

─── 数据面（加密隧道，高带宽）────────────────────────────────────

用户客户端 ────加密隧道────▶ 接入节点 ────▶ 互联网
  ·                     （起步：同一台机器，直连出网）
  · 订阅链接里的地址       │
  · 从面板获取             │ realm L4 转发（可选，30+ 用户后）
  · 直连接入节点           ▼
  · 流量不经过面板      落地节点 ────▶ 互联网
                          · IP 不暴露给用户/GFW
                          · 普通线路即可（便宜大流量）
```

| 角色 | 数量 | 管理面作用 | 数据面作用 | 线路要求 |
|---|---|---|---|---|
| **用户** | N 人 | 注册/购买/取订阅链接 | 客户端直连接入节点 | 无 |
| **面板** | 1 台 | 全部管理+交易+订阅下发 | 不参与（流量不过面板） | 无特殊要求 |
| **接入节点** | 1 台起 | agent（零节点，仅监控+管理）+ realm 转发 | 用户代理流量的入口 | ⭐ 三网优化 |
| **落地节点** | 1 台起 | agent + 代理内核（认证/计费/配置） | 流量出口 | 普通即可 |

> **起步最简部署**：面板 1 台 + 接入节点 1 台 = 可上线收费。
> 接入和落地可以是同一台机器（无需 realm），用户增多后再拆分。
> 用户流量**不经过面板**——面板只发订阅链接和管理指令，数据面完全独立。

---

## 前置要求

| 项 | 要求 | 推荐供应商 |
|---|---|---|
| 面板服务器 | 2C/2G+ Linux，网络可达 | 任意云（腾讯/阿里/AWS/Vultr） |
| 域名 | **必填**（自动 HTTPS） | [Namesilo](https://namesilo.com)（~\$9/年）/ [Cloudflare](https://cloudflare.com)（成本价） |
| SMTP 邮件 | 可选（有 → 注册收验证码） | 见下方 SMTP 供应商表 |
| 节点服务器 | 1C/1G+ Linux，仅出站 443 | 见 [服务器选型指南](#服务器选型参考) |

---

## 一、安装面板（2 分钟）

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash
```

脚本会问你：

| 问题 | 说明 | 建议 |
|---|---|---|
| 管理员邮箱 | 登录面板用 | 用你常用的邮箱 |
| 域名 | **必填**——自动配置 HTTPS | 需先做 DNS 解析（见下方） |
| SMTP | 可选——不填则关闭验证码 | 建议配置（见下方供应商表） |

安装完成自动输出：**面板地址 + 管理员账号密码** → 浏览器打开即用。

也可全参数化（零交互）：

```bash
curl -fsSL ... | sudo bash -s -- \
  --domain panel.example.com \
  --email admin@example.com \
  --mirror https://mirror.ghproxy.com
```

### 域名配置（安装前必须完成）

| 步骤 | 操作 |
|---|---|
| 1 | 在域名注册商处添加 A 记录：`panel.example.com` → 你的服务器 IP |
| 2 | 等待 DNS 生效（`dig panel.example.com` 返回服务器 IP） |
| 3 | 安装时填写域名 → Caddy 自动获取 Let's Encrypt 证书并续期 |

**域名注册商推荐**：

| 供应商 | 价格 | 特点 |
|---|---|---|
| [Namesilo](https://namesilo.com) | ~$9/年 | 便宜、免费隐私保护、支付宝 |
| [Cloudflare](https://cloudflare.com) | 成本价 | 免费 DNS + CDN + DDoS 防护 |
| [DNSPod](https://dnspod.cn) | 免费 | 腾讯旗下，国内解析快 |

---

## 二、SMTP 邮件配置（2 分钟）

### SMTP 供应商推荐

| 供应商 | 免费额度 | 配置方式 | 推荐场景 |
|---|---|---|---|
| **Resend** | 3,000 封/月 | [resend.com](https://resend.com) → API Key → SMTP | ⭐ 推荐：简单、送达率好 |
| **Brevo** | 300 封/天 | [brevo.com](https://brevo.com) → SMTP 设置 | 够用、送达率好 |
| **Mailgun** | 5,000 封/月（3个月） | [mailgun.com](https://mailgun.com) → SMTP | 大量发送 |
| **Amazon SES** | 62,000 封/月（EC2 内） | [aws.amazon.com/ses](https://aws.amazon.com/ses) | 已有 AWS |
| **Gmail SMTP** | 500 封/天 | Gmail → 应用专用密码 | 个人测试够用 |

> ⚠️ **避免使用国内邮件供应商**（阿里云邮件推送/腾讯云 SES 等）：面板部署在境外服务器，
> 国内供应商通常拒绝境外 IP 发信，且存在内容审查风险。

### 配置步骤（以 Resend 为例）

1. 注册 [resend.com](https://resend.com) → API Keys → 创建 Key
2. 安装时填入：
   ```
   SMTP 服务器: smtp.resend.com
   SMTP 端口: 465
   SMTP 用户名: resend
   SMTP 密码: re_xxxxxxxxxxxx（你的 API Key）
   发件人: noreply@yourdomain.com（需在 Resend 验证域名）
   ```
3. 安装器自动应用 → 面板发测试邮件确认

> **跳过 SMTP 也可以**：安装器自动关闭邮箱验证，用户直接注册不收验证码。
> 后续在管理后台 → 系统配置 → 邮件 中随时开启。

### 安装后配置 SMTP（如果安装时跳过了）

| 步骤 | 操作 |
|---|---|
| 1 | 管理后台 → 系统配置 → 邮件 |
| 2 | 填写 SMTP 服务器 / 端口 / 用户名 / 密码 / 发件人 |
| 3 | 开启「邮箱验证」→ 新用户注册需验证码 |
| 4 | 点击「发送测试邮件」确认可用 |

---

## 三、添加节点服务器（2 分钟/台）

### 线路知识（30 秒读懂）

| 线路 | 运营商 | 等级 | 晚高峰表现 |
|---|---|---|---|
| **CN2 GIA** | 电信 | 🏆 最高级 | 零丢包（贵但值） |
| **CMIN2** | 移动 | 🏆 最高级 | 零丢包 |
| **AS9929** | 联通 | 🏆 最高级 | 零丢包 |
| 普通 163/4837/CMI | 各家 | 标准级 | 晚高峰丢包 10%+ |

> **三网精品** = 一台机器同时接入电信 CN2 GIA + 联通 9929 + 移动 CMIN2，三类用户都不卡。

### 接入节点（用户直接连接——线路质量决定用户体验）

| 供应商 | 线路 | 配置 | 价格 | 支付 | 说明 |
|---|---|---|---|---|---|
| **搬瓦工 GIA-E** | 三网精品 | 2C/1G/1TB | $169.99/年 | 支付宝 | ⭐ 首选：晚高峰零丢包、库存充足 |
| 搬瓦工 GIA-E 升级档 | 三网精品 | 3C/2G/2TB | $299.99/年 | 支付宝 | 1TB 不够时升级 |
| 搬瓦工 大阪软银 | 软银三网 | 2C/2G/500GB | $49.99/月 | 支付宝 | 联通用户最优（低延迟），GIA-E 免费迁移 |
| **DMIT LAX Pro** | 三网 CN2 GIA | 1C/2G/1TB | $88.88/年 | 信用卡/加密 | 备选：达量限速不停机、15 天免费换 IP |
| **DMIT LAX.EB** | CMIN2+9929 | 1C/1G/500GB | $6.9/月 | 信用卡/加密 | 移动用户专项 |
| **VMISS** | 多线路可切 | 1C/1G/300GB | ~CA$10/月 | 支付宝 | 可在 CMIN2/9929/GIA 间切换测试 |

### 落地节点（流量出口——用户增多后部署，IP 藏在接入后面）

| 供应商 | 线路 | 配置 | 价格 | 支付 | 说明 |
|---|---|---|---|---|---|
| **RackNerd** | 普通 | 1C/1G/2TB | ~$14/年 | 支付宝 | ⭐ 便宜大流量（IP 池较脏，但落地不怕） |
| **CloudCone** | 普通 | 1C/1G/2TB | $15-40/年 | 支付宝 | 年付特价 |
| **Vultr** | 普通 | 1C/1G/1TB | $6/月 | 信用卡 | 按小时计费、销毁重建换 IP |
| **OuluCloud** | CMIN2 | 1C/1G/不限流量 | $9.99/月 | 支付宝/USDT | 不限流量（50Mbps） |

### 流量估算

| 套餐流量 | 1TB 月流量可承载 | 2TB 可承载 |
|---|---|---|
| 30GB/用户/月 | ~33 人 | ~66 人 |
| 100GB/用户/月 | ~10 人 | ~20 人 |
| 300GB/用户/月 | ~3 人 | ~6 人 |

> 计算方式：月流量 ÷ 用户平均用量 = 最大用户数。超出则升级套餐或加落地节点分流。

### IP 质量参考

| 池子干净度 | 供应商 | 说明 |
|---|---|---|
| ✅ 较干净 | 搬瓦工（GIA 线）/ DMIT / VMISS | 被墙概率低，换 IP 便宜 |
| ⚠️ 较脏 | RackNerd / CloudCone / Vultr | 新 IP 可能预封；但作落地不影响（GFW 只见中转 IP） |

> **起步只需要 1 台接入节点**（搬瓦工 GIA-E 即可上线运营）。
> 用户超过 ~30 后再考虑加落地：在接入机上跑 [realm 转发工具](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)将流量转到落地 IP，落地从此不被墙。
>
> 采购前测 IP：`ping.pe` 或 `ip.check.place`。

### 添加节点

#### 落地节点（必须装 agent）

1. 登录管理后台 → **机器管理** → **新建机器** → 复制一键安装命令
2. 在**落地节点服务器**上执行：

```bash
# 标准（海外节点）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <面板生成的token> --machine-id <面板分配的ID>

# 中国大陆节点（镜像加速）
curl ... | sudo bash -s -- --mode machine --panel https://... \
     --token T --machine-id 1 \
     --mirror https://panel.yourdomain.com/agent-dist --version v1.0.6
```

3. **节点管理** → **新建节点** → 选机器 + 协议（hysteria2/trojan）+ 端口 → 用户订阅立即可见

> Agent 的日常管理（`xbctl list/status/restart`）、配置参考、Docker 部署等
> 详细文档见 [Xboard README](https://github.com/coolcrow/Xboard-Node/blob/main/README.md)。

#### 接入节点（agent 零节点模式 + realm 转发）

接入节点也装 agent，但**不绑任何代理节点**——agent 只用于监控和管理：

**第一步：面板创建机器**（与落地节点相同）
管理后台 → 机器管理 → 新建机器 → 复制一键安装命令

**第二步：接入节点安装**（两个组件）
```bash
# 1. 装 agent（与落地节点相同的一键命令，面板生成）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <token> --machine-id <id>

# 2. 装 realm 转发（指向落地节点）
git clone https://github.com/coolcrow/Xboard-Node.git /tmp/bn
sudo bash /tmp/bn/tools-relay/relay-setup.sh --landing <落地IP> --ports 443,18443
```

**不要给这台机器绑定任何代理节点**——agent 零节点运行，只做监控和管理。

| 对比 | 落地节点 | 接入节点 |
|---|---|---|
| agent | ✅ 绑定代理节点 | ✅ 零节点（仅监控） |
| 代理内核 | ✅ sing-box | ❌ 无 |
| realm 转发 | ❌ 无 | ✅ 有 |
| 认证/计费 | ✅ 在这里 | ❌ 不涉及 |
| 面板心跳/资源 | ✅ | ✅（agent 上报） |
| 远程重启/升级 | ✅ | ✅（agent 支持） |
| 换落地节点 | 面板删机器重建 | `switch-landing.sh` 10 秒 |

### 使用中转架构时的节点配置

采用中转架构（接入 + 落地）时，面板需要创建**两个节点**（绑定同一台落地机器）：

| 节点 | server 字段填什么 | show 开关 | 用户是否可见 |
|---|---|---|---|
| **接入节点** | 中转机 IP（用户连接入口） | ✅ 展示 | ✅ 看到并连接 |
| **落地节点** | 落地机 IP（直连备用路径） | ❌ **隐藏** | ❌ 看不到 |

> **落地节点必须设为隐藏（show=0）**——否则用户会在订阅里看到落地 IP，
> 中转架构的 IP 保护就失效了。隐藏后内核正常运行（中转流量照常到达），
> 只是不出现在用户节点列表里。

---

## 四、配置支付（2 分钟）

管理后台 → **支付管理** → **新建支付**：

| 支付方式 | 前置条件 | 配置项 |
|---|---|---|
| **支付宝当面付** | [支付宝开放平台](https://open.alipay.com) 企业/个人开发者 | APPID + 应用私钥 + 支付宝公钥 |
| **Stripe** | [stripe.com](https://stripe.com) 账户 | Secret Key |
| **BTCPay** | 自建 BTCPay Server | URL + API Key |

> 支付宝当面付个人可申请（需实名认证），适合国内用户。

---

## 服务器安全加固

每种角色的防护面不同——以下命令可直接复制执行。

### 所有服务器通用

```bash
# 1. SSH 加固：禁密码登录（仅密钥）
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart sshd

# 2. 开启 BBR（显著提升 TCP 吞吐——代理服务器必开）
echo "net.core.default_qdisc=fq" >> /etc/sysctl.conf
echo "net.ipv4.tcp_congestion_control=bbr" >> /etc/sysctl.conf
sysctl -p

# 3. 安装 fail2ban（自动封禁暴力破解 IP）
apt-get install -y fail2ban  # 或 yum install -y epel-release fail2ban
systemctl enable --now fail2ban

# 4. 自动安全更新
apt-get install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
```

### 面板服务器

```bash
# 云安全组仅开放：SSH(22) + 面板端口(7001 或 80/443)
# 面板内置安全（安装器自动配置）：
#   ✅ IP 级限流（登录/注册 ≤10 次/分钟/IP）
#   ✅ 账户锁定（5 次密码错误锁 60 分钟）
#   ✅ 审计日志脱敏（支付密钥不入库）
#   ✅ 管理路径随机化（secure_path）
#   可选：Cloudflare Turnstile 人机验证
```

### 接入节点

```bash
# 防火墙：仅开 SSH + 节点端口
ufw default deny incoming
ufw allow 22/tcp
ufw allow 443/tcp          # hysteria2
ufw allow 443/udp          # hysteria2 (UDP)
ufw allow 18443/tcp        # trojan（按实际端口）
ufw enable

# 云安全组同步放行以上端口
```

### 落地节点（防护最严——IP 是核心资产）

```bash
# 防火墙：仅开 SSH + 接入节点 IP 的转发端口
ufw default deny incoming
ufw allow 22/tcp
ufw allow from <接入节点IP> to any port 443 proto any    # 仅允许接入节点连
ufw allow from <接入节点IP> to any port 18443 proto tcp  # 仅允许接入节点连
ufw enable

# ⚠️ 落地端口不对公网开放——只有接入节点能连，GFW 探测不到
```

---

## 日常运维

```bash
cd /opt/aibolt

# 升级面板（自动比对版本，已是最新则跳过）
bash upgrade.sh

# 查看日志
docker logs -f aibolt-panel

# 手动备份
docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/bk"' \
  && docker cp aibolt-panel:/tmp/bk ./backup-$(date +%Y%m%d).sqlite

# 从备份恢复（自动验证 + 备份当前库 + 健康检查）
bash restore.sh backup-20261005.sqlite

# 卸载（--keep-data 保留数据）
bash uninstall.sh [--keep-data]
```

## 版本管理

安装器在 `.env` 中记录 `XBOARD_IMAGE_DIGEST`（镜像唯一标识）。
升级时 `upgrade.sh` 自动比对——相同=已最新，不同=拉新镜像+迁移。
GitHub Release 页面可下载历史版本的离线安装包（不可变 tag）。

## 可选：中转保护架构（推荐 ≥30 用户）

用户直连中转入口，落地 IP 不暴露给 GFW（被墙率趋近零）：

```bash
# 在中转机上（如香港三网优化 VPS）
git clone https://github.com/coolcrow/Xboard-Node.git /tmp/bn
sudo bash /tmp/bn/tools-relay/relay-setup.sh --landing <落地IP> --ports 443,18443

# 面板节点 server 字段填中转 IP（勿填落地 IP）
```

详细架构、带宽规划、成本模型见 [tools-relay/README.md](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)。

---

## FAQ

<details>
<summary>面板启动后无法访问？</summary>

```bash
docker logs aibolt-panel          # 查看启动日志
curl http://127.0.0.1:7001        # 本地测试
ss -tln | grep 7001               # 确认监听
```

- 确认 DNS A 记录已指向服务器 IP：`dig panel.example.com`
- 确认 80/443 端口未被占用：`ss -tln | grep -E ":80 |:443 "`
- 查看 Caddy 日志：`docker logs aibolt-caddy`
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

1. 到 [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) 创建站点（免费）
2. 拿到 Site Key + Secret Key
3. 管理后台 → 系统配置 → 安全 → 人机验证 → 填入并开启

安装脚本已预设推荐安全基线（IP 限流、登录锁定等），Turnstile 需手动配置 key。
</details>

<details>
<summary>IP 被墙了怎么办？</summary>

| 供应商 | 换 IP 方法 | 费用 |
|---|---|---|
| 搬瓦工 | KiwiVM 面板 → Assign New IP | $8.79/次 |
| DMIT | 自助（每 15 天免费） | 免费 |
| Vultr | 销毁重建 | 免费 |
| RackNerd | 工单（首次免费） | 免费 |

换 IP 后在面板节点管理中更新节点 IP → 用户订阅自动刷新。
</details>

<details>
<summary>如何从备份恢复？</summary>

```bash
cd /opt/aibolt
ls bk-*.sqlite.gz          # 查看可用备份
bash restore.sh bk-20261005.sqlite.gz
```

恢复脚本自动：解压 → 完整性校验 → 备份当前库 → 替换 → 重启 → 健康验证。
</details>

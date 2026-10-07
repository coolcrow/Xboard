# AIBolt 快速开始

> 一行命令，5 分钟从零到可用

## 前置要求

| 项 | 要求 | 推荐供应商 |
|---|---|---|
| 面板服务器 | 2C/2G+ Linux，网络可达 | 任意云（腾讯/阿里/AWS/Vultr） |
| 域名 | 可选（有 → 自动 HTTPS） | [DNSPod](https://dnspod.cn)（国内）/ [Cloudflare](https://cloudflare.com)（免费） |
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
| 域名 | 可选——有则自动 HTTPS | 填了需要先做 DNS 解析（见下方） |
| SMTP | 可选——不填则关闭验证码 | 建议配置（见下方供应商表） |

安装完成自动输出：**面板地址 + 管理员账号密码** → 浏览器打开即用。

也可全参数化（零交互）：

```bash
curl -fsSL ... | sudo bash -s -- \
  --domain panel.example.com \
  --email admin@example.com \
  --mirror https://mirror.ghproxy.com
```

### 域名配置（如需 HTTPS）

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

### 服务器选型：接入节点 vs 落地节点

选服务器之前先理解两种角色：

| | 接入节点（用户直连） | 落地节点（中转后面） |
|---|---|---|
| **用户是否直连** | ✅ 是（IP 暴露给用户） | ❌ 否（IP 隐藏在中转后面） |
| **线路要求** | ⭐ 高（三网优化，晚高峰零丢包） | 低（普通线路即可，中转扛体验） |
| **IP 被墙风险** | 高（直连=被探测） | 极低（GFW 只见中转 IP） |
| **成本** | 高（GIA 线路贵） | 低（普通线路便宜大流量） |
| **适用阶段** | 起步（<30 用户） | 上量后（>30 用户） |

#### 接入节点（用户直连，需优质线路）

| 供应商 | 线路 | 配置 | 价格 | 说明 |
|---|---|---|---|---|
| **搬瓦工 GIA-E** | 三网 CN2 GIA | 2C/1G/1TB | $169.99/年 | ⭐ 首选：晚高峰零丢包、库存充足、支付宝 |
| **DMIT LAX Pro** | 三网 CN2 GIA | 1C/2G/1TB | $88.88/年 | 备选：达量限速不停机、免费换 IP |
| **DMIT LAX.EB** | CMIN2+9929 | 1C/1G/500GB | $6.9/月 | 移动用户专项 |

#### 落地节点（中转后面，普通线路即可）

| 供应商 | 线路 | 配置 | 价格 | 说明 |
|---|---|---|---|---|
| **RackNerd** | 普通 | 1C/1G/2TB | ~$14/年 | ⭐ 便宜大流量 |
| **CloudCone** | 普通 | 1C/1G/2TB | $15-40/年 | 同上，年付特价 |
| **Vultr** | 普通 | 1C/1G/1TB | $6/月 | 按小时计费、销毁重建换 IP |

#### 中转节点（连接接入和落地，需大带宽）

| 供应商 | 要求 | 月成本 | 说明 |
|---|---|---|---|
| **港三网优化中转** | 100-200Mbps | ¥150-400 | 泛联类按带宽买，或云轻量自建 |
| **腾讯云轻量 HK** | 30Mbps | ~¥30 | 低流量够用，带宽上限低 |

> **推荐架构**：起步用 1 台接入节点（搬瓦工 GIA-E）→ 30 用户后加港中转 + 廉价落地 → 落地 IP 永不暴露。
>
> 采购前测 IP：`ping.pe` 或 `ip.check.place`。
> 详细的三阶段架构规划见 [tools-relay/README.md](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)。

### 添加节点

1. 登录管理后台 → **机器管理** → **新建机器** → 复制一键安装命令
2. 在节点服务器上执行：

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

- 无域名模式：确认云安全组已放行面板端口（默认 7001）
- 有域名模式：确认 DNS A 记录已指向服务器 IP，且 80/443 未被占用
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

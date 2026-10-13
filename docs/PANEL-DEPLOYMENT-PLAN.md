# 面板部署方案：RackNerd + Cloudflare 橙云（零实名）

> 面板机选型定稿（2026-10-11，v2 修订版）。适用于全新部署：目标是**免实名、
> 支付宝可付、防墙防 DDoS、年成本 ~$30**。节点机选型见
> [QUICKSTART 选型指南](./QUICKSTART.md#服务器选型参考)。

## 一、方案总览

```
管理员/用户 ──▶ Cloudflare 边缘（橙云代理）──▶ RackNerd 洛杉矶 源站
                 · 免费 CDN + DDoS 防护          · 面板（2C/2G 档）
                 · 真实 IP 对外隐藏               · 不参与代理流量
                                                 · 防火墙仅放行 CF 回源段

节点机（GIA-E / RackNerd 等）── A 记录灰云（DNS only），直连，绝不过 CF
```

**核心逻辑**：源站套上 CF 橙云后，IP 质量 / 被墙风险 / 机房线路对访问质量的影响
基本消失（访问质量由 CF 边缘决定）——因此源站可以买最便宜的年付机，
把预算留给真正决定用户体验的节点机。

## 二、选型对比（为什么是 RackNerd）

| 方案 | 实名 | 支付 | 价格 | 大陆访问 | 防墙 |
|---|---|---|---|---|---|
| **RackNerd LA** ⭐ | 无 | 支付宝 | **~$14-25/年** | 经 CF 边缘，与源站无关 | 橙云防护 + 源站防火墙 |
| Vultr 东京 | 无 | 卡/PayPal | $6/月 | 较好（直连形态） | 需销毁重建自愈 |
| 腾讯轻量香港（国内站） | **要**（大陆证件最顺） | 支付宝 | ~¥288/年 | 最好 | 无 |
| 腾讯国际站 Lighthouse HK | 无 | 卡 | ~$10+/月 | 最好 | 无 |
| CloudCone LA | 无 | 支付宝 | ~$18-25/年 | 同上（2G 特价常更便宜） | 同上 |

> **首选理由（RackNerd）**：供应商记录干净（CloudCone 2019 年出过勒索软件全量数据损毁，
> 面板存用户/订单数据，此差距值钱）+ 已有真实使用验证 + 支付宝直付。CN 直连的
> 端口级黑洞在橙云架构下无关紧要（面板走 CF 边缘、SSH 走 cloudflared tunnel）。
> CloudCone 仅在 2G 特价明显更便宜时（差 $5+/年）作为价格备选。
>
> 结论：**追求零实名 + 支付宝 + 最低成本** → RackNerd + CF 橙云；
> 追求裸连极致体验且接受实名 → 腾讯轻量香港国内站。本方案取前者。

## 三、采购清单

| 项 | 规格 | 渠道 | 价格 | 实名 |
|---|---|---|---|---|
| 面板源站 | **2C/2G**/2TB 流量，Debian 12 或 Ubuntu 22.04 | RackNerd 年付（2G 档） | ~$20-25/年 | 无 |
| 域名 | 任意（NS 托管 Cloudflare） | 见下方支付说明 | ~$9/年 | 无 |

**规格红线**：内存选 **2G 档**，不要 1G——面板是 MySQL + Redis + Octane(Caddy)
全家桶，1G 冷启动就接近 OOM 边缘，省这几美元不值。

**域名支付现实**：支持支付宝的注册商极少——Namesilo / Cloudflare Registrar /
Porkbun 都要卡或 PayPal；纯支付宝路线可试 Spaceship（以结账页为准）或加密货币
注册商。域名这步大概率需要一张卡/PayPal/加密货币，这是全链路里唯一绕不开的。

**价格说明**：表中价格为 2026-10 撰写时行情（~ 为约数），下单以官网实时价为准。

**下单前**：RackNerd 选 Los Angeles 数据中心、2G 内存档；若当时 CloudCone 的
2G 特价便宜 $5+/年 可换（规则完全相同）。注意**年付特价续费常恢复原价**——
下单时看清 renewal 价格。

## 四、Cloudflare 配置（成败在此，顺序不能错）

1. **NS 托管**：域名注册商处把 NS 改为 Cloudflare 分配的两条（free plan）
2. **先灰云签证书**：`panel.你的域名 → RackNerd IP`，Proxy = ⚪ DNS only，
   再到源站执行安装。机理：橙云下 CF 终结 TLS，TLS-ALPN 挑战不可用；Caddy
   会自动改走 HTTP-01（经 CF 的 80 端口转发），多数情况也能签成——但先灰云
   安装消除全部边角，是最稳路径（本方案按此顺序）
3. **签完再开橙云**：证书就绪后切 🟠 Proxied，SSL/TLS 模式设 **Full (strict)**
4. **建议关闭 "Always Use HTTPS"**（或对 `/.well-known/acme-challenge/*` 加
   跳过规则）。LE 验证器会跟随 http→https 重定向，续期多数情况不受影响；
   但 CF 强跳后的回源行为存在边角，关闭它是零成本加固，可避免证书失效
   引发的 526（Full strict 下源站证书无效即 526）
5. **节点记录一律灰云**：未来 GIA-E / RackNerd 的 A 记录 = DNS only（⚪）
   ——代理流量过 CF 违反其 ToS 且严重降速，**这是硬规则**

## 五、部署步骤

```bash
# 0. 前置：DNS 灰云记录已生效（此步必须先于安装）
dig panel.你的域名 +short   # 应返回 RackNerd 源站 IP

# 1. 源站上一条命令装面板（CF 回源 GitHub 慢时带 --mirror）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash \
  -s -- --domain panel.你的域名 --email admin@你的域名

# 2. 源站防火墙收紧：80/443 仅放行 Cloudflare 回源段——具体命令见附录 A2

# 3. TrustProxies 检查/补加 CF 回源段——检查与修改命令见附录 A3

# 4. 回到 Cloudflare：面板记录切 🟠 橙云 + SSL=Full(strict)
#    （若第 2 步防火墙已收紧，此步前 CF 才能回源成功——顺序：先放行再切橙云）

# 5.（后置）agent wss 验证——节点机存在后才适用；节点/agent 安装不在本文档
#    范围（见 QUICKSTART §三）。agent 上线后观察 WS mux ≥10 分钟
#    · CF 免费版支持 WebSocket，但空闲连接会被掐——agent 的 60s 心跳/report
#      足够保活；即便 WS 断，机器通道自动降级 REST 轮询（pull_interval=60s），
#      功能无损、实时性略降——这是设计内的降级路径，不是故障

# 6. 备份：本地每日备份安装器自带（04:30，保留 15 份——见附录 A5）；
#    当天要做的是运维机上的异机拉取 cron

# 7. 管理通道：大陆直连美西廉价机的 SSH 常有端口级丢包（ICMP 通、22 黑洞），
#    装 cloudflared tunnel 让 SSH 走 CF 隧道——完整 8 步见附录 A4
```

**续期预案**（橙云下的长期风险点）：保持第 4 条配置则 HTTP-01 续期可过；
若续期仍失败（526 报错前兆），临时把记录切灰云 → `docker restart aibolt-caddy`
→ 续期成功后切回橙云。彻底解法是换带 cloudflare DNS 插件的自编译 Caddy 走
DNS-01（进阶，需要时再做）。

## 六、验证清单

- [ ] `dig` 面板域名返回 **CF 边缘 IP**（非源站 IP）
- [ ] 浏览器打开面板 HTTPS 正常，证书为 CF 签发的边缘证书
- [ ] 管理后台登录、套餐保存正常
- [ ] **改一个节点配置 → 订阅内容 60s 内反映**（确认 CF 边缘没有缓存订阅响应）
- [ ] 节点机 agent 上线且 wss 稳定 ≥10 分钟（或确认已降级 REST 仍正常工作）
- [ ] 订阅 URL 经 CF 拉取正常（`curl -L 订阅链接`）
- [ ] **直连源站 IP:443 被拒**（防火墙只认 CF 回源段——注意：CF 本身不会拦截
      直连，隐藏靠的是"IP 不泄漏 + 防火墙只放行 CF"，两者缺一不可）
- [ ] 源站 SSH 仅高位端口 + 密钥

## 七、成本汇总

| 项 | 成本 |
|---|---|
| 面板源站（RackNerd 2C/2G） | ~$20-25/年 |
| 域名 | ~$9/年 |
| Cloudflare | $0（free plan） |
| **合计** | **~$30/年 ≈ ¥18/月** |

节点机（接入/落地）成本另见 QUICKSTART 分期方案，与本方案无关。

## 八、已知边界与残余风险

| 风险 | 说明 | 缓解 |
|---|---|---|
| CF 美西边缘延迟 | 免费版大陆流量走美西，后台 1-2s 打开 | 管理场景可接受 |
| **SNI 级域名阻断** | 橙云防的是 IP 层；面板域名本身若被 GFW SNI 阻断，CF 也救不了 | 新域名概率低；真发生时换子域/备用域名即恢复（源站不动） |
| 100MB 请求体上限 | CF 免费版限制 | 大备份导入走源站本地/SSH |
| 预算机房稳定性 | RackNerd/CloudCone 偶有抖动 | **面板宕机不影响在网用户**（agent/内核独立运行），只影响新订阅与管理 |
| 年付特价续费涨价 | 首年促销价 ≠ 续费价 | 下单时看清 renewal；到期前比价另一家特价 |
| 源站 IP 泄漏 | 任何出站邮件/历史 DNS 记录都可能带出 IP | 防火墙只放行 CF 段后，泄漏也不可被利用 |

## 九、可选加固（不阻塞上线）

- **Cloudflare Access**（Zero Trust 免费档）套在管理后台路径前 = 多一层 MFA
- 面板注册开关：初期关闭自由注册（管理后台可配），杜绝扫描器批量注册

## 附录 A：执行手册（运维独立操作用）

> 本方案中的可核事实均已核验：CF IP 段与官方 ips-v4 清单逐条比对一致；
> 安装器行为（安装路径 /opt/aibolt、交互问题、备份 cron、容器名）核自
> deploy/install.sh 源码；restore/uninstall 用法核自脚本源码；CN→美西
> 端口黑洞为实测。价格为约数。

### A0 前置清单

- **技能假设**：会 SSH / 基础 Linux 命令 / 能照界面操作 Cloudflare 控制台
- **账号三样**：RackNerd（root SSH）+ 域名注册商 + Cloudflare 免费账号
- **预计时长**：面板 30 分钟 + SSH 隧道 20 分钟
- 安装器交互只有 4 问：管理员邮箱 / 域名 / SMTP（可回车跳过）/ DNS 确认

### A1 Cloudflare 控制台路径速查

| 操作 | 位置 |
|---|---|
| 添加站点（选 Free） | Dashboard → Add a domain |
| 换 NS（注册商操作） | Add site 完成页会给两条 NS，到注册商替换，<10 分钟生效 |
| 橙云/灰云开关 | DNS → Records → 编辑记录 → Proxy status（🟠=Proxied / ⚪=DNS only） |
| SSL 模式 Full(strict) | SSL/TLS → Overview |
| 关 Always Use HTTPS | SSL/TLS → Edge Certificates |

### A2 源站防火墙（二选一，照抄）

```bash
# ── ufw 版（Debian/Ubuntu 默认常见）──
ufw default deny incoming && ufw default allow outgoing
for ip in $(curl -fsS https://www.cloudflare.com/ips-v4); do
  ufw allow from "$ip" to any port 80,443 proto tcp
done
ufw limit ssh && ufw enable

# ── firewalld 版（CentOS/Rocky 系）──
for ip in $(curl -fsS https://www.cloudflare.com/ips-v4); do
  firewall-cmd --permanent --add-rich-rule="rule family=ipv4 source address=$ip port port=80 protocol=tcp accept"
  firewall-cmd --permanent --add-rich-rule="rule family=ipv4 source address=$ip port port=443 protocol=tcp accept"
done
firewall-cmd --reload
```

> 注意先跑上面的放行，再回 CF 切橙云（顺序反了 CF 回源会被防火墙拒绝）。

### A3 TrustProxies 检查与修改

```bash
grep TRUSTED_PROXIES /opt/aibolt/.env    # 查看当前值
# 安装器不设置此项（已核源码）——需手动添加。编辑 /opt/aibolt/.env 写入：
# TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22
docker restart aibolt-panel
```

### A4 cloudflared SSH 隧道（8 步）

```bash
# ── 源站上（步骤 1-6）──
# 1. 装 cloudflared
curl -fsSL https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared
chmod +x /usr/local/bin/cloudflared
# 2. 授权（会打印一个 URL，浏览器打开并选择你的域名）
cloudflared tunnel login
# 3. 建隧道（记下输出的 TUNNEL-UUID）
cloudflared tunnel create panel-ssh
# 4. 写配置（两处 <TUNNEL-UUID> 替换）
mkdir -p /etc/cloudflared
cat > /etc/cloudflared/config.yml <<'EOF'
tunnel: <TUNNEL-UUID>
credentials-file: /root/.cloudflared/<TUNNEL-UUID>.json
ingress:
  - hostname: ssh.你的域名
    service: ssh://localhost:22
  - service: http_status:404
EOF
# 5. 绑域名记录（自动建灰云 CNAME）
cloudflared tunnel route dns panel-ssh ssh.你的域名
# 6. 装成系统服务
cloudflared service install && systemctl enable --now cloudflared

# ── 运维本地电脑（步骤 7-8）──
# 7. ~/.ssh/config 追加（本地也要装一份 cloudflared）
#    Host panel-box
#      HostName ssh.你的域名
#      User root
#      ProxyCommand cloudflared access ssh --hostname %h
# 8. 连接：ssh panel-box
```

### A5 备份（安装器已自带）

- 安装完成即有**每日 04:30 自动备份**：`/opt/aibolt/bk-*.sqlite.gz`，保留 15 份
- 异机拉取（建议）：运维机 cron `scp root@源站:/opt/aibolt/bk-$(date +%Y%m%d).sqlite.gz ./`
- 恢复：`cd /opt/aibolt && bash restore.sh bk-YYYYMMDD.sqlite.gz`（必须指定备份文件，覆盖前有确认提示）

### A6 故障排查表

| 症状 | 原因 | 处置 |
|---|---|---|
| 面板 526 | 源站证书过期/续期失败 | 正文"续期预案"：切灰云 → `docker restart aibolt-caddy` → 切回 |
| 面板 502/521 | 源站防火墙没放行 CF 段 | 重跑附录 A2 |
| 安装时证书一直失败 | 橙云没先关 | §四第 2 条：先灰云再装 |
| dig 返回源站 IP | 橙云没开成功 | DNS 页确认 Proxied 状态 |
| agent 不上线 | wss 被 CF 掐 | 等 60s 观察 REST 降级是否工作；面板机器页看心跳 |
| 面板打不开但节点正常 | CF 或源站故障——**不影响在网用户** | 查 CF status page；源站 `docker ps` 看 aibolt-panel/caddy |

### A7 回滚

```bash
cd /opt/aibolt && bash uninstall.sh            # 完整卸载
cd /opt/aibolt && bash uninstall.sh --keep-data # 卸载但保留数据
```

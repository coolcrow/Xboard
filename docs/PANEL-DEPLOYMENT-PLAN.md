# 面板部署方案：CloudCone + Cloudflare 橙云（零实名）

> 面板机选型定稿（2026-10-11）。适用于全新部署：目标是**免实名、支付宝可付、
> 防墙防 DDoS、年成本 ~$25**。节点机选型见 [QUICKSTART 选型指南](./QUICKSTART.md#服务器选型参考)。

## 一、方案总览

```
管理员/用户 ──▶ Cloudflare 边缘（橙云代理）──▶ CloudCone 洛杉矶 源站
                 · 免费 CDN + DDoS 防护          · 面板（1C/1G 够用）
                 · 真实 IP 永不暴露               · 不参与代理流量

节点机（GIA-E / RackNerd 等）── A 记录灰云（DNS only），直连，绝不过 CF
```

**核心逻辑**：源站套上 CF 橙云后，IP 质量 / 被墙风险 / 机房线路对访问质量的影响
全部消失（访问质量由 CF 边缘决定）——因此源站可以买最便宜的年付机，
把预算留给真正决定用户体验的节点机。

## 二、选型对比（为什么是 CloudCone）

| 方案 | 实名 | 支付 | 价格 | 大陆访问 | 防墙 |
|---|---|---|---|---|---|
| **CloudCone LA** ⭐ | 无 | 支付宝 | **~$15-18/年** | 经 CF 边缘，与源站无关 | 橙云全防 |
| Vultr 东京 | 无 | 卡/PayPal | $6/月 | 较好（直连形态） | 需销毁重建自愈 |
| 腾讯轻量香港（国内站） | **要**（大陆证件最顺） | 支付宝 | ~¥288/年 | 最好 | 无 |
| 腾讯国际站 Lighthouse HK | 无 | 卡 | ~$10+/月 | 最好 | 无 |
| RackNerd LA | 无 | 支付宝 | ~$14/年 | 同 CloudCone 逻辑可套 CF | 橙云全防 |

> 结论：**追求零实名 + 支付宝 + 最低成本** → CloudCone（或 RackNerd）+ CF 橙云；
> 追求裸连极致体验且接受实名 → 腾讯轻量香港国内站。本方案取前者。

## 三、采购清单

| 项 | 规格 | 渠道 | 价格 | 实名 |
|---|---|---|---|---|
| 面板源站 | 1C/1G/2TB，Debian 12 或 Ubuntu 22.04 | CloudCone 年付特价 | ~$15-18/年 | 无 |
| 域名 | 任意（NS 托管 Cloudflare） | Namesilo ~$9/年 或 CF Registrar 成本价 | ~$9/年 | 无 |

**下单前**：CloudCone 选数据中心 Los Angeles；年付特价档库存波动，缺货可用
RackNerd 同档替代（$12-14/年，规则相同）。

## 四、Cloudflare 配置（成败在此）

1. **NS 托管**：域名注册商处把 NS 改为 Cloudflare 分配的两条（free plan）
2. **只有面板记录开橙云**：`panel.你的域名 → CloudCone IP`，Proxy status = 🟠 Proxied
3. **节点记录一律灰云**：未来 GIA-E / RackNerd 的 A 记录 = DNS only（⚪）
   ——代理流量过 CF 违反其 ToS 且严重降速，**这是硬规则**
4. SSL/TLS 模式：Full (strict)——面板源站会自配 Let's Encrypt 证书

## 五、部署步骤

```bash
# 1. 验证 DNS（橙云生效后 dig 返回的是 CF 边缘 IP）
dig panel.你的域名 +short

# 2. 一条命令装面板（源站上执行；CF 回源慢时带 --mirror）
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash \
  -s -- --domain panel.你的域名 --email admin@你的域名

# 3. TrustProxies 加 Cloudflare 回源段（拿真实客户端 IP；安装器已内置则跳过）
#    TRUSTED_PROXIES 含 173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,
#    103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,
#    188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,
#    104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22

# 4. agent wss 过 CF 验证：节点机装 agent 后观察 WS mux 是否稳定
#    （CF 免费版支持 WebSocket；若空闲断连，检查 agent keepalive 间隔）
```

## 六、验证清单

- [ ] `dig` 面板域名返回 CF 边缘 IP（非源站 IP）
- [ ] 浏览器打开面板 HTTPS 正常，证书为 CF 签发边缘证书
- [ ] 管理后台登录、套餐保存正常
- [ ] 节点机 agent 上线且 wss 长连接 >10 分钟不断
- [ ] 订阅 URL 经 CF 拉取正常（`curl -L 订阅链接`）
- [ ] 直连源站 IP:443 应无法访问（CF 挡回源）——证明源站已隐藏

## 七、成本汇总

| 项 | 成本 |
|---|---|
| 面板源站（CloudCone） | ~$15-18/年 |
| 域名 | ~$9/年 |
| Cloudflare | $0（free plan） |
| **合计** | **~$25/年 ≈ ¥15/月** |

节点机（接入/落地）成本另见 QUICKSTART 分期方案，与本方案无关。

## 八、已知边界

- CF 免费版大陆访问走美西边缘，后台打开有 1-2 秒延迟——管理场景可接受
- 上传大文件（如备份导入）受 CF 免费版 100MB 请求体限制
- 橙云回源要求源站 443 对 CF 开放——CloudCone 默认防火墙需放行（安装时注意）

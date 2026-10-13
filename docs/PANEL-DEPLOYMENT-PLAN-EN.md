# Panel Deployment Plan: RackNerd + Cloudflare Proxied (Zero-KYC)

> Finalized panel-hosting decision (2026-10-11, rev v2). For fresh deployments where the
> goals are: **no identity verification, Alipay-friendly payment, DDoS/GFW resilience,
> ~$30/year total**. For node-server selection, see the
> [QUICKSTART procurement guide](./QUICKSTART-EN.md).

## 1. Architecture Overview

```
Admin/User ──▶ Cloudflare edge (orange-cloud proxy) ──▶ RackNerd LA origin
                · Free CDN + DDoS protection              · Panel (2C/2G tier)
                · Real IP hidden from public               · Carries no proxy traffic
                                                            · Firewall: CF origin
                                                              ranges only

Node servers (GIA-E / RackNerd, etc.) ── A records are grey-cloud (DNS only),
                                        direct connections — NEVER through CF
```

**Core logic**: once the origin sits behind the CF proxy, the origin's IP quality,
block risk, and datacenter routing no longer affect user experience (access quality
is decided by the CF edge). The origin can therefore be the cheapest annual-plan
box, leaving budget for the node servers that actually determine user experience.

## 2. Option Comparison (Why RackNerd)

| Option | KYC | Payment | Price | CN access | Block-proof |
|---|---|---|---|---|---|
| **RackNerd LA** ⭐ | None | Alipay | **~$14-25/yr** | Via CF edge (origin-agnostic) | CF shield + origin firewall |
| Vultr Tokyo | None | Card/PayPal | $6/mo | Good (direct) | Self-heals via redeploy |
| Tencent Lighthouse HK (CN site) | **Required** (mainland ID easiest) | Alipay | ~¥288/yr | Best | None |
| Tencent Cloud Intl. Lighthouse HK | None | Card | ~$10+/mo | Best | None |
| CloudCone LA | None | Alipay | ~$18-25/yr | Same (2GB specials often cheaper) | Same |

> **Why RackNerd first**: clean vendor track record (CloudCone suffered a 2019
> ransomware incident that wiped customer data — the panel stores user/order data,
> so this gap matters), already validated by real production use, direct Alipay
> payment. The CN-to-origin port blackholing is irrelevant under orange cloud
> (panel via CF edge, SSH via cloudflared tunnel). CloudCone is the price alternate
> only when its 2GB special is $5+/yr cheaper.
>
> Conclusion: **zero-KYC + Alipay + lowest cost** → RackNerd + CF orange cloud;
> bare-connection quality with KYC accepted → Tencent Lighthouse HK (CN site).

## 3. Procurement List

| Item | Spec | Vendor | Price | KYC |
|---|---|---|---|---|
| Panel origin | **2C/2G** / 2TB transfer, Debian 12 or Ubuntu 22.04 | RackNerd annual (2GB tier) | ~$20-25/yr | None |
| Domain | Any (NS hosted on Cloudflare) | See payment note below | ~$9/yr | None |

**Spec red line**: pick the **2 GB RAM** tier, never 1 GB — the panel runs the full
MySQL + Redis + Octane (Caddy) stack; 1 GB runs close to OOM right at cold start.
Not worth the few dollars saved.

**Domain payment reality**: registrars that accept Alipay are rare — Namesilo /
Cloudflare Registrar / Porkbun all want a card or PayPal; a pure-Alipay path could
try Spaceship (verify at checkout) or crypto registrars. Expect the domain to be the
one step in this chain that needs a card / PayPal / crypto.

**Before ordering**: pick the Los Angeles datacenter at RackNerd, 2GB RAM tier;
switch to a CloudCone 2GB special only if it is $5+/yr cheaper at purchase time
(rules are identical). Watch the **renewal price** — first-year promos often renew
at full price.

## 4. Cloudflare Configuration (This Makes or Breaks It — Order Matters)

1. **NS hosting**: point the domain's nameservers to the pair Cloudflare assigns (free plan)
2. **Issue the cert under grey cloud first**: `panel.your-domain → RackNerd IP`,
   Proxy = ⚪ DNS only, THEN run the installer on the origin. Caddy defaults to
   TLS-ALPN/HTTP-01 — **TLS-ALPN fails with orange cloud on** (CF intercepts the
   port-443 handshake)
3. **Then switch to orange**: once the cert is live, set 🟠 Proxied and SSL/TLS mode
   to **Full (strict)**
4. **Disable "Always Use HTTPS"** (or add a bypass rule for `/.well-known/acme-challenge/*`)
   — otherwise CF's forced redirect breaks Caddy's HTTP-01 renewals, and after the
   cert expires the whole panel serves 526 errors
5. **Grey cloud for every node record**: future GIA-E / RackNerd A records = DNS only (⚪).
   Proxy traffic through CF violates their ToS and destroys throughput — **hard rule**

## 5. Deployment Steps

```bash
# 0. Precondition: the grey-cloud DNS record is live (must precede installation)
dig panel.your-domain +short   # must return the RackNerd origin IP

# 1. One command to install the panel (run on the origin; add --mirror if CF
#    egress to GitHub is slow)
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash \
  -s -- --domain panel.your-domain --email admin@your-domain

# 2. Tighten the origin firewall: 80/443 allowed ONLY from Cloudflare origin ranges
#    (an openly exposed origin IP bypasses every CF protection; move SSH to a
#    high port + key-only auth)
#    CF ranges: https://www.cloudflare.com/ips/ (same list as TrustProxies)

# 3. Add Cloudflare origin IP ranges to TrustProxies (for real client IPs;
#    skip if the installer already includes them):
#    173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,
#    141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,
#    197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,
#    104.24.0.0/14,172.64.0.0/13,131.0.72.0/22

# 4. Back at Cloudflare: flip the panel record to 🟠 Proxied + SSL=Full(strict)
#    (with step 2's firewall already allowing CF ranges — order: allow first,
#    then flip orange)

# 5. Validate agent wss: watch the WS mux for ≥10 minutes after agents come online
#    · CF free supports WebSocket but drops idle connections — the agent's 60s
#      heartbeat/report keeps it alive; even if WS breaks, the machine channel
#      degrades to REST polling (pull_interval=60s): full functionality, slightly
#      worse freshness — a designed degradation path, not an outage

# 6. Backups: set up on day one (cron daily dump + off-box pull), not after
#    the first user arrives

# 7. Management path: mainland-to-US-budget SSH often suffers port-level
#    blackholing (ICMP passes, TCP:22 drops — observed in production), and an
#    origin with a tightened firewall shouldn't expose 22 anyway — install a
#    cloudflared tunnel so SSH rides the CF edge: no IP exposure, no line
#    interference, zero cost
```

**Renewal playbook** (the long-term risk under orange cloud): with rule 4 in place,
HTTP-01 renewals pass. If renewal still fails (early sign: 526 errors), temporarily
flip the record to grey cloud → `docker restart aibolt-caddy` → flip back after
renewal succeeds. The definitive fix is a custom Caddy build with the Cloudflare
DNS plugin doing DNS-01 (advanced; do it when needed).

## 6. Verification Checklist

- [ ] `dig` on the panel domain returns **CF edge IPs** (not the origin IP)
- [ ] Panel loads over HTTPS with a CF-issued edge certificate
- [ ] Admin login and plan edits work
- [ ] **Edit one node setting → subscription reflects it within 60s** (confirms CF
      does not cache subscription responses)
- [ ] Node agents stay online with wss stable ≥10 minutes (or REST degradation
      confirmed still working)
- [ ] Subscription URLs fetch correctly through CF (`curl -L <sub url>`)
- [ ] **Direct hit on the origin IP:443 is refused** (firewall honors CF ranges
      only — note: CF itself does not block direct-to-origin traffic; hiding works
      only via "IP not leaked + firewall allows CF only", both required)
- [ ] SSH on the origin: high port + key-only

## 7. Cost Summary

| Item | Cost |
|---|---|
| Panel origin (RackNerd 2C/2G) | ~$20-25/yr |
| Domain | ~$9/yr |
| Cloudflare | $0 (free plan) |
| **Total** | **~$30/yr** |

Node-server (access/landing) costs are covered separately in the QUICKSTART phased
plan and are independent of this one.

## 8. Known Limitations & Residual Risks

| Risk | Detail | Mitigation |
|---|---|---|
| CF US-West edge latency | Free plan routes mainland users to US-West; 1-2s panel opens | Acceptable for admin workflows |
| **SNI-level domain blocking** | Orange cloud protects the IP layer; if the panel domain itself gets SNI-blocked by the GFW, CF cannot help | Low odds for a fresh domain; if it happens, a new subdomain/domain restores service (origin untouched) |
| 100MB request-body cap | CF free plan limit | Large backup imports go via origin-local/SSH |
| Budget-host stability | RackNerd/CloudCone occasional wobble | **Panel downtime does not affect online users** (agents/kernels run independently); it only blocks new subscriptions and management |
| First-year promo pricing | Promo ≠ renewal price | Check renewal at checkout; compare the other vendor before renewal |
| Origin IP leakage | Outbound mail / historical DNS records can leak the IP | With the firewall allowing CF ranges only, a leaked IP cannot be exploited |

## 9. Optional Hardening (Not Launch-Blocking)

- **Cloudflare Access** (free Zero Trust tier) in front of the admin path = an extra MFA layer
- Registration toggle: keep self-registration off initially (configurable in the
  admin panel) to stop scanner-driven signups

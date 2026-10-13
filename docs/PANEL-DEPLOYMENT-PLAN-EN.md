# Panel Deployment Plan: CloudCone + Cloudflare Proxied (Zero-KYC)

> Finalized panel-hosting decision (2026-10-11). For fresh deployments where the goals are:
> **no identity verification, Alipay-friendly payment, DDoS/GFW resilience, ~$25/year total**.
> For node-server selection, see the [QUICKSTART procurement guide](./QUICKSTART-EN.md).

## 1. Architecture Overview

```
Admin/User ──▶ Cloudflare edge (orange-cloud proxy) ──▶ CloudCone LA origin
                · Free CDN + DDoS protection              · Panel (1C/1G is enough)
                · Real IP never exposed                    · Carries no proxy traffic

Node servers (GIA-E / RackNerd, etc.) ── A records are grey-cloud (DNS only),
                                        direct connections — NEVER through CF
```

**Core logic**: once the origin sits behind the CF proxy, the origin's IP quality,
block risk, and datacenter routing no longer affect user experience (access quality
is decided by the CF edge). The origin can therefore be the cheapest annual-plan
box, leaving budget for the node servers that actually determine user experience.

## 2. Option Comparison (Why CloudCone)

| Option | KYC | Payment | Price | CN access | Block-proof |
|---|---|---|---|---|---|
| **CloudCone LA** ⭐ | None | Alipay | **~$15-18/yr** | Via CF edge (origin-agnostic) | Fully shielded |
| Vultr Tokyo | None | Card/PayPal | $6/mo | Good (direct) | Self-heals via redeploy |
| Tencent Lighthouse HK (CN site) | **Required** (mainland ID easiest) | Alipay | ~¥288/yr | Best | None |
| Tencent Cloud Intl. Lighthouse HK | None | Card | ~$10+/mo | Best | None |
| RackNerd LA | None | Alipay | ~$14/yr | Same CF logic applies | Fully shielded |

> Conclusion: **zero-KYC + Alipay + lowest cost** → CloudCone (or RackNerd) + CF orange
> cloud; bare-connection quality with KYC accepted → Tencent Lighthouse HK (CN site).
> This plan takes the former.

## 3. Procurement List

| Item | Spec | Vendor | Price | KYC |
|---|---|---|---|---|
| Panel origin | 1C/1G/2TB, Debian 12 or Ubuntu 22.04 | CloudCone annual special | ~$15-18/yr | None |
| Domain | Any (NS hosted on Cloudflare) | Namesilo ~$9/yr or CF Registrar at cost | ~$9/yr | None |

**Before ordering**: pick the Los Angeles datacenter at CloudCone. Annual-special stock
fluctuates; if out of stock, RackNerd's same tier (~$12-14/yr) is a drop-in replacement
under identical rules.

## 4. Cloudflare Configuration (This Makes or Breaks It)

1. **NS hosting**: point the domain's nameservers to the pair Cloudflare assigns (free plan)
2. **Orange cloud for the panel record only**: `panel.your-domain → CloudCone IP`,
   Proxy status = 🟠 Proxied
3. **Grey cloud for every node record**: future GIA-E / RackNerd A records = DNS only (⚪).
   Proxy traffic through CF violates their ToS and destroys throughput — **hard rule**
4. SSL/TLS mode: Full (strict) — the panel origin provisions its own Let's Encrypt cert

## 5. Deployment Steps

```bash
# 1. Verify DNS (once orange cloud is live, dig returns CF edge IPs)
dig panel.your-domain +short

# 2. One command to install the panel (run on the origin; add --mirror if CF
#    egress to GitHub is slow)
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash \
  -s -- --domain panel.your-domain --email admin@your-domain

# 3. Add Cloudflare origin IP ranges to TrustProxies (for real client IPs;
#    skip if the installer already includes them):
#    173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,
#    141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,
#    197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,
#    104.24.0.0/14,172.64.0.0/13,131.0.72.0/22

# 4. Validate agent wss through CF: after installing the agent on node servers,
#    watch whether the WS mux stays connected (CF free supports WebSocket; if it
#    drops when idle, check the agent keepalive interval)
```

## 6. Verification Checklist

- [ ] `dig` on the panel domain returns CF edge IPs (not the origin IP)
- [ ] Panel loads over HTTPS with a CF-issued edge certificate
- [ ] Admin login and plan edits work
- [ ] Node agents stay online with wss connections stable for >10 minutes
- [ ] Subscription URLs fetch correctly through CF (`curl -L <sub url>`)
- [ ] Hitting the origin IP directly on :443 fails — proving the origin is hidden

## 7. Cost Summary

| Item | Cost |
|---|---|
| Panel origin (CloudCone) | ~$15-18/yr |
| Domain | ~$9/yr |
| Cloudflare | $0 (free plan) |
| **Total** | **~$25/yr** |

Node-server (access/landing) costs are covered separately in the QUICKSTART phased
plan and are independent of this one.

## 8. Known Limitations

- CF free routes mainland users to US-West edges: expect 1-2s latency when opening
  the admin panel — acceptable for management workflows
- Large uploads (e.g. backup imports) are capped by CF free's 100MB request-body limit
- Orange-cloud egress requires port 443 open to Cloudflare on the origin — mind the
  CloudCone firewall during installation

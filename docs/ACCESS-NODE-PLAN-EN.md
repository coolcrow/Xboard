# Access Node Plan: BandwagonHost GIA-E (Triple-Premium Lines)

> Access-node selection, finalized 2026-10-13. The access node is **the entry point
> users connect to directly** — line quality determines the entire user experience.
> This filter ranks "triple-carrier peak-hour performance" first, price second.
> Companion doc: [Panel Deployment Plan](./PANEL-DEPLOYMENT-PLAN-EN.md)
> (RackNerd + CF proxied).

## 1. Role in the Architecture

```
Route A (single-box direct start):
  User ──▶ GIA-E (access + landing in one, no realm)
Route B (multi-entry, relay-native):
  Telecom user ──▶ Pro entry ──┐
  Mobile/Unicom ──▶ EB entry ──┴─realm─▶ shared landing (RackNerd, IP never exposed)
```

> Route A converts to B later: flip GIA-E to pure access + add a landing;
> same box, configuration only.
> Multi-entry is a first-class citizen of this system: feeder lists are
> derived automatically, the landing firewall allowlist reconciles on its own
> (zero manual work when adding/removing entries), and subscription entries
> coexist as mutual backups.

## 2. Filter Criteria (Six Dimensions)

| Dimension | Weight | Why |
|---|---|---|
| Triple-carrier peak hours | ⭐⭐⭐ | CN2 GIA (Telecom) / 9929-CUP (Unicom) / CMIN2 (Mobile) — miss one and some users crawl at peak |
| Stock availability | ⭐⭐ | The best line is worth zero if unbuyable (DMIT went fully out of stock in May 2026) |
| Payment / KYC | ⭐⭐ | Zero-KYC + Alipay (same constraint chain as the panel plan) |
| Block recovery | ⭐⭐ | The access IP is the only exposed surface in the whole network; self-heal cost must be low |
| Price / transfer | ⭐ | 1TB/mo ≈ 30 light-tier users — matches Phase 1 |
| Ops simplicity | ⭐ | Console maturity, datacenter switching, snapshots |

## 3. Candidate Comparison (prices verified 2026-10)

| Candidate | Lines | Spec | Price | Payment | Stock (verified) | Block recovery |
|---|---|---|---|---|---|---|
| **BandwagonHost GIA-E 20G** ⭐ | Triple (DC6/DC9: GIA+CUP+CMIN2) | 2C/1G/1TB | **$49.99/qtr · $169.99/yr** | Alipay/UnionPay | ✅ In stock (stock monitor, 2026-10-13) | Datacenter switch = free instant new IP |
| BandwagonHost GIA-E 40G | Same | 3C/2G/2TB | $89.99/qtr · $299.99/yr | Alipay | ✅ In stock | Same |
| DMIT LAX.Pro | Triple CN2 GIA | 1C/1G/450GB | $36.9/yr (WEE) | **Alipay**/card/crypto | ⚠️ Volatile (fully sold out May 2026) | Paid / once per 14 days |
| DMIT LAX.EB | CMIN2+9929 (Telecom return also 9929 since 2026-05) | 1C/1G/1TB | $39.9/yr (WEE) | Alipay/card/crypto | ⚠️ Same | Same |
| VMISS | Multi-line switchable | 1C/1G | ~CA$10/mo (**2026 price NOT verified**) | Alipay | Check live | Manual ticket |
| BandwagonHost Osaka SoftBank | SoftBank triple (Unicom 60-80ms) | 2C/2G/500GB | $49.99/mo | Alipay | ✅ | Same as BWH |

**Elimination logic**:
- DMIT's two series are **line-equal and even cheaper**, but the May-2026 full-stockout
  precedent + 1C/1G floor specs + 14-day IP-swap cadence — the access node is the
  network's only exposed surface, so recovery speed is the lifeline → alternate
- VMISS unverified pricing; only valuable for multi-line testing → watchlist
- Osaka SoftBank at ~$600/yr is 3.5× the price for Unicom specialization → Phase 3

## 4. Decision: Two Routes — Pick by "How Many Boxes"

### Route A: Single Flagship (one box, zero fuss)

```
Product   CN2 GIA-E SPECIAL 20G KVM PROMO V5 (E-Commerce series)
Billing   Annual $169.99 (2 months free vs quarterly)
DC        After ordering, switch to DC6 (Telecom/Mobile) or DC9 (EPYC/NVMe)
Payment   Alipay; no KYC
Notes     30-day refund window (transfer used <10%, clean IP)
```

### Route B: Multi-Entry Split (several access boxes + budget) ⭐ Recommended

| Role | Purchase | Lines | Transfer | Price |
|---|---|---|---|---|
| Entry 1 (Telecom) | DMIT LAX.Pro WEE 1C/1G | Triple return CN2 GIA | 450GB/mo | $36.9/yr |
| Entry 2 (Mobile/Unicom) | DMIT LAX.EB WEE 1C/1G | CMIN2 + 9929 (Telecom return 9929) | 1TB/mo | $39.9/yr |
| Shared landing | RackNerd 1C/1G | Ordinary (IP hidden behind entries) | 2TB/mo | ~$14/yr |

```
Multi-entry total ≈ $91/yr — 47% cheaper than one GIA-E, plus:
· Fault tolerance: either entry blocked, the other keeps serving (both live
  in the subscription simultaneously)
· Carrier split: Telecom users hit the Pro entry (GIA); Mobile/Unicom hit EB
· Linear scaling: add another WEE entry at +$40/box when traffic grows —
  never touch the $299 vertical upgrade
· Access boxes in relay form run only realm + agent: 1C/1G is plenty
· New entry onboarding = create machine in panel + install agent, 15 min
  (landing firewall allowlist reconciles automatically)
Aggregate transfer 1.45TB/mo ≈ 45 light-tier users (vs GIA-E 1TB ≈ 30)
```

Costs: ① Telecom entry only 450GB (≈15 heavy Telecom users — add another Pro
WEE when tripped); ② DMIT stock swings (full stockout May 2026) — if sold out
at checkout, bridge with an EB entry + GIA-E and swap later.

**Route choice**: multiple boxes + budget-sensitive + willing to camp stock →
**B**; one box + absolute simplicity → A. Whole-system cost (with panel plan):
A ≈ $192/yr; **B ≈ $113/yr across 4 machines**.

### Upgrade Path (both routes)

Entry nearing its cap → add a WEE entry horizontally (+$37-40/yr, live in 15
min); landing nearing its cap → add another RackNerd (+$14/yr). **Never scale
vertically.**

## 5. Post-Delivery Deployment (Phase 1 Direct Form)

```bash
# 0. IP quality pre-check (the access IP must be clean)
#    Open ping.pe or ip.check.place, enter the new IP:
#    - normal latency/loss on all three carriers, no block flags
# 1. Install the node agent: panel "Nodes" page → copy the install command →
#    SSH into the GIA-E box and run it (details in QUICKSTART §3)
# 2. Create the node in the panel: trojan/hysteria2, host = GIA-E IP, port 443
# 3. DNS: access-domain A record → GIA-E IP (grey cloud / DNS only, never CF)
# 4. Subscription check: import to a client, run one peak-hour speed test,
#    record the baseline
```

## 6. Verification Checklist

- [ ] ping.pe triple-carrier test clean, no block records (**within 30 minutes of
      ordering — stay inside the refund window**)
- [ ] Peak-hour (20:30-23:00 CST) mtr per carrier, packet loss <1%
- [ ] Agent online, heartbeat normal, kernel started
- [ ] Subscription imports; speed ≥70% of plan bandwidth
- [ ] DNS record grey-cloud (direct connection, never through CF — same hard
      rule as the panel plan)

## 7. Boundaries & Risks

| Risk | Detail | Handling |
|---|---|---|
| Access IP blocked | The access IP is the network's only exposed surface | KiwiVM datacenter switch (free, instant new IP) → update DNS → recovered |
| Transfer cap hit | 1TB/mo exhausted | BWH overage bills per GB (expensive); upgrade or split before tripping |
| DC switch wipes disk | Switching = reprovision | Keep the access box stateless (config lives in the panel; a reinstalling agent restores everything) |
| GIA congestion (extreme) | Rare peak incidents | Flip DC6↔DC9; structurally solved by the Phase 2 split |

## 8. Verification Statement

> Fact sources: BandwagonHost price/stock = stock-monitor site verified in-stock
> 2026-10-13, cross-checked across multiple sources ($169.99/yr has been stable for
> years); DMIT pricing/lines = 2026 official snapshots and independent reviews
> (including the 2026-05 EB telecom-return AS9929 upgrade and the May 2026
> full-stockout record); payment methods consistent across sources. VMISS
> unverified, flagged as such. Prices are verification-day snapshots; the live
> vendor page is authoritative at checkout.

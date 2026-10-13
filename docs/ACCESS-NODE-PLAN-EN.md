# Access Node Plan: Low-Cost Per-Carrier Entries (Final v7)

> Requirement in one line: **low cost + Telecom/Mobile/Unicom users each get an
> entry + one landing box**. Companion: [Panel Deployment Plan](./PANEL-DEPLOYMENT-PLAN-EN.md)
> (RackNerd + Cloudflare proxied). Node-install operations: [QUICKSTART §3](./QUICKSTART-EN.md).

## 1. Target Architecture

```
Telecom user     ──▶ Pro entry (CN2 GIA)──────────┐
                                                ├──▶ shared RackNerd landing (IP never exposed)
Mobile/Unicom user ──▶ EB entry (CMIN2 + 9929)───┘

Entry-tier target price: $36.9 + $39.9 = $76.8/yr (two DMIT WEE limited editions)
```

Both entries live in the user subscription simultaneously as mutual backups —
one blocked, the other serves on. Access boxes run only realm + agent; 1C/1G
specs are plenty.

## 2. Why Two Steps: The WEEs Are Limited Editions

The $36.9 Pro.WEE and $39.9 EB.WEE are DMIT **limited annual editions**, not
standing stock (verified 2026-10: the official Pro line starts at TINY
$10.90/mo; the EB line is fully out of stock; WEE editions trade second-hand
at a ¥600-1000 premium on NodeSeek — that's how scarce they are). Hence:
**start today on a standing SKU, switch when the limited editions return —
no architecture change either way.**

⚠️ **Checkout guard**: the standing stock includes a T1.WEE at the same $36.9 —
T1 is the international line with **zero China optimization**; buying it by
mistake is money burned. Verify the series name reads **Pro** or **EB**.

## 3. Step One: Order Today (Get Running)

| Buy | Where | Price | Role |
|---|---|---|---|
| **DMIT Pro.TINY** | dmit.io | $10.90/mo (≈$131/yr) | Sole entry — **its triple-carrier return is all CN2 GIA: one box covers all three carriers** |
| RackNerd 1C/1G | racknerd.com | ~$14/yr | Landing (2TB/mo) |
| RackNerd 2C/2G | racknerd.com | ~$22/yr | Panel (see panel plan) |
| Domain | NS on CF | ~$9/yr | — |

**Total ≈ $176/yr ≈ ¥106/mo.** DMIT takes Alipay; 3-day full refund, 30-day
prorated — test on arrival (§5) and return at no loss if unhappy.

> Entry alternate: if DMIT monthly pricing stings or the 15-day IP-swap cadence
> is too slow, use BandwagonHost GIA-E at $169.99/yr — same triple-carrier
> coverage, never out of stock, **instant** IP swap via datacenter switch when
> blocked. Architecturally identical; swap anytime.

## 4. Step Two: Switch When Limited Editions Return (Cost-Down + Split)

| Camp for | Price | Action | Gain |
|---|---|---|---|
| **EB.WEE** | $39.9/yr | Add as second entry; name the node "Mobile/Unicom preferred" | Mobile/Unicom users get their dedicated line; dual-entry redundancy; aggregate transfer doubles |
| **Pro.WEE** | $36.9/yr | Replace the Pro.TINY (450GB/mo) | Entry tier drops to $76.8/yr; whole-system steady state **≈ $122/yr ≈ ¥73/mo** |

Where to camp: ① DMIT official restocks (last EB.WEE round was Nov 2025);
② NodeSeek second-hand — premium up to ¥600 is fine (still cheaper than a
Pro.TINY year). **Not camping successfully changes nothing** — the Pro.TINY
keeps running; it just costs $94/yr more.

## 5. On-Arrival Verification (within 30 minutes, inside the refund window)

- [ ] Drop the new IP into `ping.pe`: normal latency/loss on all three carriers, no block flags
- [ ] Confirm the series is Pro/EB (not T1) — check the order page
- [ ] Peak-hour (20:30-23:00 CST) mtr once per carrier, loss <1%
- [ ] DNS: entry-domain A record → entry IP, **grey cloud** (DNS only, never CF)
- [ ] Agent online, subscription works, record a peak-hour speed baseline

## 6. Risks & Handling

| Risk | Handling |
|---|---|
| Buying the T1 series by mistake (same price, no China routing) | §5 check #2; 3-day refund as backstop |
| Entry IP blocked (the network's only exposed surface) | DMIT: one free IP swap per 15 days; dual-entry mutual backup after step two |
| Landing SPOF (landing down = whole system down; panel unaffected) | Add a second landing at +$14/yr once revenue exists (firewall allowlist adapts automatically) |
| Limited editions never restock | Pro.TINY runs indefinitely; NodeSeek second-hand as backstop |
| Entry nearing its 1TB/mo cap | Add another entry horizontally (+$40-class, live in 15 min); never trip per-GB overage |

## 7. Verification Statement

> BandwagonHost price/stock = stock monitor verified 2026-10-13; DMIT
> pricing/lines/limited-edition status = 2026 official restock snapshots +
> independent reviews + NodeSeek market, three-way cross-checked;
> Alipay/refund policy consistent across sources. Prices are verification-day
> snapshots; the live vendor page is authoritative at checkout.

# Access Node Plan: Low-Cost Per-Carrier Entries (Final v7)

> Requirement in one line: **low-cost entries, horizontally scalable in count,
> per-carrier access, one shared landing box**. Companion: [Panel Deployment Plan](./PANEL-DEPLOYMENT-PLAN-EN.md)
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

## 4. Step Two: Accumulate Limited Editions — Entry Count Grows with Users

**Scaling formula**: entries = users ÷ per-entry capacity (0.45-1TB ≈ 15-30
light-tier users). $37-40/yr per box, horizontal only, never vertical.
Triple-carrier coverage comes from mixing lines: grab GIA editions
(Pro.WEE / BiggerBox) when Telecom-heavy, EB editions when Mobile/Unicom-heavy.

**Cheap entries worth camping (two sources — don't depend on DMIT alone):**

| Edition | Price | Lines | Transfer | Source |
|---|---|---|---|---|
| DMIT Pro.WEE | $36.9/yr | Triple CN2 GIA | 450GB/mo | DMIT restocks + NodeSeek second-hand |
| DMIT EB.WEE | $39.9/yr | CMIN2+9929 | 1TB/mo | Same (out of stock 2026-10) |
| **BandwagonHost BiggerBox Pro #156** | $39/yr | LA CN2 GIA/CMIN2 | **1TB/mo** | BWH limited-series drops |

Cadence: grab 1-2 per restock window (last EB.WEE round Nov 2025; watch BWH
via stock.bwg.net or restock TG channels). **Once 2 cheap boxes are in,
retire the Pro.TINY** (monthly, cancellable anytime) — entry cost locks at
$37-40/box.

```
Example evolution (user growth view):
  Launch      Pro.TINY ×1              $131/yr  1TB    30 users
  +restocks   BiggerBox $39 + EB.WEE $39.9 → retire TINY
  Steady      2 limited editions       $79/yr   2TB    60 users
  Growth      +Pro.WEE ×1              +$37/yr  2.45TB 75 users …
```

Not camping successfully changes nothing — the Pro.TINY runs on; it just
costs $94/yr more.

### Addendum: Domain-Based Entries (Borrowed from the Airport Playbook)

> One takeaway adopted after dissecting a commercial airport's mihomo
> subscription: **the panel node's host field gets a domain, never a bare IP.**

```
How:    node host = gz1.your-domain (grey-cloud A record → entry IP,
        TTL 60-300s)
Block:  entry IP banned → change one A record to the new IP → every client
        switches within TTL — no subscription re-push, users never notice,
        no waiting on IP-swap windows
Why it  realm is an L4 relay; TLS terminates at the landing kernel (cert/SNI
works: use the landing domain) — the entry domain only replaces "where to
        connect", never touches the certificate chain. Adoption cost: zero.
Mirror: the airport's 22 nodes share one entry domain and survive blocks the
        same way (DNS edit) — industry-proven. But their single entry is a
        SPOF; our multi-entry mutual backup is strictly stronger
```

**Two things NOT adopted**: ① the single shared entry (all 22 nodes live and
die together — that night's "transient outage" was the shared entry
jittering, where url-test node-switching is useless; our multi-entry design
is immune by construction); ② DNS round-robin multi-entry (soft HA, unreliable
— subscription-level multi-node + client url-test is deterministic).

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
| Entry IP blocked (the network's only exposed surface) | **First move: edit the entry-domain A record (1 minute, user-invisible — §4 addendum)**; then DMIT's 15-day free swap / BWH DC switch; multi-entry backup |
| Landing SPOF (landing down = whole system down; panel unaffected) | Add a second landing at +$14/yr once revenue exists (firewall allowlist adapts automatically) |
| Limited editions never restock | Pro.TINY runs indefinitely; NodeSeek second-hand as backstop |
| Entry nearing its 1TB/mo cap | Add another entry horizontally (+$40-class, live in 15 min); never trip per-GB overage |

## 7. Verification Statement

> BandwagonHost price/stock = stock monitor verified 2026-10-13; DMIT
> pricing/lines/limited-edition status = 2026 official restock snapshots +
> independent reviews + NodeSeek market, three-way cross-checked;
> Alipay/refund policy consistent across sources. Prices are verification-day
> snapshots; the live vendor page is authoritative at checkout.

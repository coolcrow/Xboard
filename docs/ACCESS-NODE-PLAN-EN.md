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

## 3. Step One: Order Today (Check BiggerBox-Pro Stock First)

**30 seconds before ordering**: check stock.bwg.net (or kucun.banwagong.net)
for BiggerBox-Pro:

**Path A (in stock, ⭐ preferred)**:

| Buy | Price | Role |
|---|---|---|
| **BandwagonHost BiggerBox-Pro ×1-2** | $39/box/yr (codes `NODESEEK2026` / `BWHCGLUKKB`, 6.58% recurring → **$36.36/box**) | Entry — Telecom CN2 GIA + Mobile CMIN2 + Unicom optimized (DC1); 1TB/box; 30-day refund **even if the IP is already blocked** |
| RackNerd 1C/1G | ~$14/yr | Landing |
| RackNerd 2C/2G | ~$22/yr | Panel |
| Domain | ~$9/yr | — |

One box ≈ **$137/yr ($134 with code)**; two boxes (dual-entry + 2TB) ≈ **$176/yr
($170 with code)**. **Locked to DC1, no DC switching** — block recovery relies on
the domain-based entry switch (§4 addendum 1) to the other box, or grabbing
another at the next restock.

**Path B (bridge when BiggerBox is out of stock)**:

| Buy | Price | Role |
|---|---|---|
| DMIT Pro.TINY | $10.90/mo (≈$131/yr) | Temporary sole entry (triple-carrier GIA return in one box); 3-day full refund; retire it the moment a limited edition lands |
| RackNerd ×2 + domain | ~$45/yr | Landing + panel + domain |

Total ≈ $176/yr ≈ ¥106/mo.

> High-spec alternate: BandwagonHost MEGABOX-PRO $49/yr (2C/2G/2TB, DC1 CN2
> GIA) — camp it as the flagship entry; one box equals two BiggerBoxes of
> capacity; limited stock too.

## 4. Step Two: Accumulate Limited Editions — Entry Count Grows with Users

**Scaling formula**: entries = users ÷ per-entry capacity (0.45-1TB ≈ 15-30
light-tier users). $37-40/yr per box, horizontal only, never vertical.
Triple-carrier coverage comes from mixing lines: grab GIA editions
(Pro.WEE / BiggerBox) when Telecom-heavy, EB editions when Mobile/Unicom-heavy.

**Cheap entries worth camping (two sources — don't depend on DMIT alone):**

| Edition | Price | Lines | Transfer | Source |
|---|---|---|---|---|
| BandwagonHost BiggerBox-Pro | $39/yr ($36.36 w/ code) | Telecom GIA + Mobile CMIN2 + Unicom optimized | 1TB/mo | **Restocks often** (Mar/Jul/Dec 2025, Sep 2026 all documented) |
| BandwagonHost MEGABOX-PRO | $49/yr | DC1 CN2 GIA | 2TB/mo | Camp (benchmarks the $299.99 regular tier) |
| DMIT Pro.WEE | $36.9/yr | Triple CN2 GIA | 450GB/mo | DMIT restocks + NodeSeek second-hand |
| DMIT EB.WEE | $39.9/yr | CMIN2+9929 | 1TB/mo | Same (out of stock 2026-10) |

> Watch channels: kucun.banwagong.net (live stock) / TG @BandwagonHostNews
> (restock push) / buhuo.bwg.net (email). **Pre-fund the BWH account balance**
> so restock windows convert to instant checkouts.

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

### Addendum 2: The Procurement View (How Airports Buy)

| What the airport does | What it means for us |
|---|---|
| Buys quality and capacity separately (one HK relay entry + cheap Enzu exits) | ✅ Aligned: expensive entries ($37-131/yr) + cheap landing ($14/yr) is exactly this split |
| "28 nodes" = really 2-4 machines + port/instance multiplication | ⚠️ **Count entries as machines** — port multiplication inflates node count, not capacity; don't let the subscription's node count fool you |
| Sources from wholesale IDC/relay vendors (HK-optimized BGP/IPLC, ¥150-400/mo), not retail annuals | 📌 Two-tier procurement: **retail camping now ($37-40/box) → wholesale at scale** (>100 users or peak-hour quality complaints; the QUICKSTART Phase-2 "HK triple-carrier box" is that channel — ¥150-400/mo per box but 100-200Mbps equals a dozen retail entries) |
| Direct family coexists with relay family as mutual fallback | ✅ Aligned: GIA entry + EB entry multi-line coexistence is the same idea |

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

## Appendix A: Execution Handbook (For Standalone Ops Use)

### A0 Prerequisites

- Three accounts: BandwagonHost (bandwagonhost.com, email signup) / DMIT (dmit.io) / panel admin
- Skills: SSH, panel UI clicking, CF DNS editing
- Time: 20 min procurement + 40 min deployment

### A1 Ordering (BandwagonHost example)

1. Register → **pre-fund the balance first** (Services → Add Funds, Alipay) — restock windows are flash sales; paying on the spot loses
2. Pick BiggerBox-Pro → enter `NODESEEK2026` (or `BWHCGLUKKB`) in the checkout **Promotional Code** box → confirm the 6.58% recurring discount applies → pay with Alipay
3. On delivery: KiwiVM panel → Install new OS → **Debian 12** (note the root password)
4. Stock watching: kucun.banwagong.net / TG @BandwagonHostNews / buhuo.bwg.net email alerts

### A2 Deployment (seven steps, bare metal to paying users)

```bash
# 1. After OS install, verify the line FIRST (within 30 min, inside the
#    30-day refund window): ping.pe with the entry IP — all three carriers
#    normal, no block flags

# 2. Panel "Machines" page → add machine (role = access) → copy the install
#    command → SSH into the BiggerBox and run it (Debian 12, one command)

# 3. Same for the landing box (RackNerd 1C/1G, Debian 12), role = landing

# 4. Machine detail page (access machine) → relay forwarding config:
#    landing = the RackNerd just registered; ports = 443/udp,18443:443/tcp
#    (on save, the agent auto-deploys realm and the landing firewall
#     allowlist reconciles automatically)

# 5. Panel "Nodes" page — create two entry nodes (pure coordinates, no
#    machine binding):
#    GZ1-Trojan    proto trojan    host=gz1.your-domain  port=18443  entry_machine_id=access
#    GZ1-Hysteria2 proto hysteria2 host=gz1.your-domain  port=443    entry_machine_id=access
#    ⚠️ host takes the DOMAIN, never the IP (see A3); assign to a group
#    (empty-group saves raise an amber warning)

# 6. Cloudflare DNS records (grey cloud):
#    gz1.your-domain  A  → entry IP    Proxy=⚪DNS only  TTL=5 min
#    la1.your-domain  A  → landing IP  Proxy=⚪DNS only  (cert domain)

# 7. Run the §5 verification checklist → users' subscriptions show both GZ1 nodes
```

### A3 Domain-Based Entry Essentials (Block Recovery = 1 Minute)

- Panel node host is always `gz1.your-domain`; cert/SNI use the landing domain
  (la1) — realm moves bytes only, TLS untouched
- CF DNS TTL: edit record → change TTL from Auto to **5 minutes** — when blocked,
  edit the A record and every client switches within 5 min
- **Recovery drill (run once on launch day)**: point gz1's A record at the
  landing IP temporarily → dig to confirm propagation → revert. Prove you can

### A4 Troubleshooting Matrix

| Symptom | Fix |
|---|---|
| Agent installed, stays offline | On the entry box `journalctl -u xboard-node -f`; verify the token on the panel machines page |
| Users can't connect, subscription fine | `dig gz1.your-domain` — still the entry IP? Then ping.pe the entry IP for block flags → A3 switch |
| Slow at peak hours | ping.pe loss per carrier; Telecom bad → add a GIA-edition entry later; Mobile bad → add an EB edition |
| Landing kernel down | Heartbeat on the panel machines page; on the landing box `journalctl -u xboard-node \| grep -i error`, send back last-failed-config.json |
| Entry nearing its cap | Traffic graphs on the nodes page; >80% → camp the next box per §4 |

### A5 Refunds / Retirement

- BiggerBox refunds within 30 days (even with a blocked IP): KiwiVM → Request Cancellation
- Retiring an entry: delete node + machine records in the panel → delete the DNS A record → cancel with the vendor

# AIBolt Quick Start

> One command, 5 minutes from zero to operational

## Architecture Overview

The system has two separate paths — **Control Plane** (registration/billing/admin) and **Data Plane** (actual proxy traffic):

```
─── Control Plane (HTTPS, low bandwidth) ─────────────────────

Users/Admin ───▶ Panel Server (your main server)
                   · Register / login / purchase / get subscription
                   · Admin dashboard (machines / nodes / plans / payments)
                   · Auto HTTPS (Caddy + Let's Encrypt)
                   · Database (SQLite) + daily backups
                   · Payment callbacks (Alipay async notify)

Panel ◄── Agent outbound WSS (both access and landing nodes run agent)
         · Heartbeat / config push / user list sync / traffic reporting

─── Data Plane (encrypted tunnel, high bandwidth) ────────────

User client ───encrypted tunnel───▶ Access Node ───▶ Internet
  ·                          (early stage: same machine, direct)
  · Subscription URL             │
  · obtained from panel          │ realm L4 forward (optional, 30+ users)
  · connects directly            ▼
  · to access node          Landing Node ───▶ Internet
  · Traffic NEVER               · IP hidden from users/GFW
    passes through panel         · Standard line (cheap, high volume)
```

| Role | Count | Control Plane | Data Plane | Line Requirement |
|---|---|---|---|---|
| **Users** | N | Register/buy/get sub URL | Connect directly to access node | None |
| **Panel** | 1 | All management + billing + sub | Not involved (traffic bypasses panel) | None |
| **Access Node** | 1+ | Agent (zero nodes, monitoring only) + realm | Entry point for proxy traffic | ⭐ Premium |
| **Landing Node** | 1+ | Agent + proxy kernel (auth/billing/config) | Traffic exit | Standard |

> **Minimum deployment**: 1 panel + 1 access node = ready to sell.
> Access and landing can be the same machine (no forwarding needed); split when you scale.
> User traffic **never passes through the panel** — panel only issues subscription URLs and management commands; data plane is fully independent.

---

## Prerequisites

| Item | Requirement | Recommended Provider |
|---|---|---|
| Panel server | 2C/2G+ Linux, network accessible | Any cloud (AWS/Vultr/DigitalOcean) |
| Domain | **Required** (auto HTTPS) | [Namesilo](https://namesilo.com) (~$9/yr) / [Cloudflare](https://cloudflare.com) (at cost) |
| SMTP email | Optional (enables email verification) | See SMTP provider table below |
| Node server | 1C/1G+ Linux, outbound 443 only | See [Server Selection](#server-selection) |

---

## 1. Install Panel (2 min)

```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard/master/deploy/install.sh | sudo bash
```

The script will ask:

| Question | Notes |
|---|---|
| Admin email | Used to log in to the panel |
| Domain | **Required** — DNS A record must point to this server first |
| SMTP (optional) | Skip = email verification disabled (users register directly) |

Installation automatically completes: Docker → image pull (with China mirror fallback) → Caddy (auto HTTPS via Let's Encrypt) → admin creation → security baseline → cron jobs.

Or fully parameterized (zero interaction):

```bash
curl -fsSL ... | sudo bash -s -- \
  --domain panel.example.com \
  --email admin@example.com \
  --mirror https://mirror.ghproxy.com
```

### Domain Setup (must complete before install)

| Step | Action |
|---|---|
| 1 | Add A record at your domain registrar: `panel.example.com` → your server IP |
| 2 | Wait for DNS propagation (`dig panel.example.com` returns server IP) |
| 3 | Enter domain during install → Caddy auto-obtains and renews Let's Encrypt certificate |

---

## 2. SMTP Email Setup (2 min)

### Recommended SMTP Providers

| Provider | Free Tier | Signup | Notes |
|---|---|---|---|
| **Resend** | 3,000/mo | [resend.com](https://resend.com) | ⭐ Recommended: simple, good deliverability |
| **Brevo** | 300/day | [brevo.com](https://brevo.com) | Good deliverability |
| **Mailgun** | 5,000/mo (3 months) | [mailgun.com](https://mailgun.com) | High volume |
| **Amazon SES** | 62,000/mo (within EC2) | [aws.amazon.com/ses](https://aws.amazon.com/ses) | If you already use AWS |
| **Gmail SMTP** | 500/day | Gmail → App Password | For testing only |

> ⚠️ **Avoid China-based email providers** (Aliyun DirectMail, Tencent SES): they typically reject sending from overseas IPs and pose content review risks.

### Configuration (using Resend as example)

1. Sign up at [resend.com](https://resend.com) → API Keys → Create Key
2. Enter during installation:
   ```
   SMTP Host: smtp.resend.com
   SMTP Port: 465
   SMTP Username: resend
   SMTP Password: re_xxxxxxxxxxxx (your API Key)
   Sender: noreply@yourdomain.com (verify domain in Resend first)
   ```
3. Installer applies automatically → panel sends test email

> **Skipping SMTP is fine**: installer disables email verification — users register without verification codes. You can enable it later in Admin → System Settings → Email.

---

## 3. Add Node Server (2 min each)

### Server Selection

#### Access Node (users connect directly — line quality determines experience)

| Provider | Route | Specs | Price | Notes |
|---|---|---|---|---|
| **BandwagonHost GIA-E** | CN2 GIA (all 3 carriers) | 2C/1G/1TB | $169.99/yr | ⭐ Best: zero packet loss at peak, Alipay |
| **DMIT LAX Pro** | CN2 GIA (all 3 carriers) | 1C/2G/1TB | $88.88/yr | Alt: throttle-not-kill, free IP swap |
| **DMIT LAX.EB** | CMIN2 + AS9929 | 1C/1G/500GB | $6.9/mo | Mobile-optimized |

#### Landing Node (traffic exit — deploy as you scale, IP hidden behind access node)

| Provider | Route | Specs | Price | Notes |
|---|---|---|---|---|
| **RackNerd** | Standard | 1C/1G/2TB | ~$14/yr | ⭐ Cheap high-volume |
| **CloudCone** | Standard | 1C/1G/2TB | $15-40/yr | Annual deals |
| **Vultr** | Standard | 1C/1G/1TB | $6/mo | Hourly billing, easy IP swap |

> **Start with just 1 access node** (BandwagonHost GIA-E above is enough to launch).
> Add landing nodes at ~30+ users: pick the landing node in the panel forwarding card (agent installs realm automatically) — landing IPs never get blocked.
>
> Test IPs before purchase: `ping.pe` or `ip.check.place`.

### Add a Node

#### Landing Node (agent required)

1. Log in to Admin → **Machines** → **New Machine** → copy one-line install command
2. Run on the **landing node server**:

```bash
# Standard (overseas node)
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <token-from-panel> --machine-id <id>

# China mainland node (mirror acceleration)
curl ... | sudo bash -s -- --mode machine --panel https://... \
     --token T --machine-id 1 \
     --mirror https://panel.yourdomain.com/agent-dist --version v1.0.6
```

3. **Node Management** → **New Node** → select machine + protocol (hysteria2/trojan) + port → visible in user subscriptions immediately

> Agent daily management (`xbctl list/status/restart`), configuration reference, Docker deployment — see [Xboard-Node README](https://github.com/coolcrow/Xboard-Node/blob/main/README.md).

#### Access Node (agent in zero-node mode + realm forwarding)

The access node also runs the agent, but with **zero proxy nodes assigned** — agent is for monitoring and management only:

**Step 1: Create machine in panel** (same as landing node)
Admin → Machines → New Machine → copy one-line install command

**Step 2: Install agent on access node** (the only component, same one-line command as landing)
```bash
curl -fsSL https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh | \
  sudo bash -s -- --mode machine \
       --panel https://panel.yourdomain.com \
       --token <token> --machine-id <id>
```

**Step 3: Configure forwarding in the panel** (no SSH needed — agent handles the entire realm setup)

Open **Machine Detail → Forwarding card** on this machine:

1. Landing node: pick a node from the landing machine (dropdown)
2. Ports: e.g. `443,18443` (optional `/tcp` `/udp` suffix, dual-stack by default)
3. Click **[Save & Deploy]**

The agent automatically downloads realm (v2.9.6, sha256-pinned) → writes config →
starts the service. Within ~30s the **Relay status** panel below shows
`realm-relay@dual · Running`.

**Switch landing**: pick another node in the same card → Save & Deploy — agent
rewrites config and restarts in seconds.

> Fallback when the agent is unavailable: [manual relay-setup.sh](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md)
> (byte-compatible config format — the two can take over from each other).

**Do NOT assign any proxy nodes to this machine** — agent runs in zero-node mode, monitoring only.

| | Landing Node | Access Node |
|---|---|---|
| Agent | ✅ with proxy nodes | ✅ zero nodes (monitoring only) |
| Proxy kernel | ✅ sing-box | ❌ none |
| Realm forwarding | ❌ none | ✅ yes |
| Auth / billing | ✅ here | ❌ not involved |
| Panel heartbeat/resources | ✅ | ✅ (agent reports) |
| Remote restart/upgrade | ✅ | ✅ (agent supports) |
| To change | Delete & recreate in panel | panel dropdown, seconds |

### Access Architecture Node Configuration

When using the access architecture (access + landing machines), create **two nodes** in the panel (bound to the same landing machine):

| Node | server field | show toggle | User sees? |
|---|---|---|---|
| **Access node** | Relay IP (user entry point) | ✅ Visible | ✅ Connect here |
| **Landing node** | Landing IP (direct fallback) | ❌ **Hidden** | ❌ Not visible |

> **Landing node MUST be hidden (show=0)** — otherwise the landing IP appears in user subscriptions, defeating the IP protection of the access architecture. The kernel runs normally (forwarded traffic reaches it), it just doesn't appear in the node list.

**Port offset for two nodes on one machine (server_port)**: both kernels bind on the
same landing machine — same protocol stack + same port = bind conflict. Set
`server_port` (actual kernel listen port) on the **access node**; the port users see
stays unchanged:

| Node | server | port (user-facing) | server_port (kernel) |
|---|---|---|---|
| Access·Hysteria2 | access IP | 443 | **14443** |
| Landing·Hysteria2 (hidden) | landing IP | 443 | 443 (default) |
| Access·Trojan | access IP | 443 | **24443** |
| Landing·Trojan (hidden) | landing IP | 443 | 443 (default) |

> **Forwarding auto-aligns**: the panel maps entry ports to kernel ports
> (`443→14443`) when deploying — enter only user-facing ports in the machine
> forwarding card. Open these kernel ports on the landing firewall for the access IP.

**Machine type**: set landing machines to **[Landing]** in machine detail (shows node
management only); access machines are auto-tagged **[Access]** when saving
forwarding config.

---

## 4. Payment Setup (2 min)

Admin → **Payment Management** → **New Payment**:

| Method | Prerequisite | Configuration |
|---|---|---|
| **Alipay F2F** | [Alipay Open Platform](https://open.alipay.com) developer account | APP ID + Private Key + Alipay Public Key |
| **Stripe** | [stripe.com](https://stripe.com) account | Secret Key |
| **BTCPay** | Self-hosted BTCPay Server | URL + API Key |

---

## Server Security Hardening

### All servers

```bash
# 1. SSH: key-only auth
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart sshd

# 2. Enable BBR (TCP congestion control — essential for proxy servers)
echo "net.core.default_qdisc=fq" >> /etc/sysctl.conf
echo "net.ipv4.tcp_congestion_control=bbr" >> /etc/sysctl.conf
sysctl -p

# 3. Install fail2ban
apt-get install -y fail2ban  # or: yum install -y epel-release fail2ban
systemctl enable --now fail2ban

# 4. Automatic security updates
apt-get install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
```

### Panel server

```bash
# Cloud firewall: only allow SSH(22) + 80/443
# Panel built-in security (auto-configured by installer):
#   ✅ IP rate limiting (login/register ≤10/min/IP)
#   ✅ Account lockout (5 failed attempts → 60min lock)
#   ✅ Audit log redaction (payment keys never stored in plaintext)
#   ✅ Randomized admin path (secure_path)
#   Optional: Cloudflare Turnstile CAPTCHA
```

### Access node

```bash
ufw default deny incoming
ufw allow 22/tcp
ufw allow 443/tcp          # hysteria2
ufw allow 443/udp          # hysteria2 (UDP)
ufw allow 18443/tcp        # trojan (adjust to actual port)
ufw enable
```

### Landing node (most restrictive — IP is the core asset)

```bash
ufw default deny incoming
ufw allow 22/tcp
ufw allow from <access-node-IP> to any port 443 proto any     # direct node kernel
ufw allow from <access-node-IP> to any port 14443 proto any   # access node kernel (server_port)
ufw allow from <access-node-IP> to any port 24443 proto tcp   # adjust to actual offsets
ufw enable
# ⚠️ Landing ports NOT open to public — only the access node can connect
# ⚠️ Kernel port list = union of every node's port AND server_port (they differ in access architectures)
```

---

## Daily Operations

```bash
cd /opt/aibolt

# Upgrade panel (auto version check, skips if already latest)
bash upgrade.sh

# View logs
docker logs -f aibolt-panel

# Manual backup
docker exec aibolt-panel sh -c 'sqlite3 /www/.docker/.data/database.sqlite ".backup /tmp/bk"' \
  && docker cp aibolt-panel:/tmp/bk ./backup-$(date +%Y%m%d).sqlite

# Restore from backup (auto verify + backup current + health check)
bash restore.sh backup-20261005.sqlite

# Uninstall (--keep-data to preserve data)
bash uninstall.sh [--keep-data]
```

## Version Management

The installer records `XBOARD_IMAGE_DIGEST` in `.env` (unique image identifier).
`upgrade.sh` compares digests — same = up to date, different = pull new image + migrate.
Historical offline installers available on the [GitHub Releases](https://github.com/coolcrow/Xboard/releases) page (immutable tags).

## Optional: Relay Architecture (recommended at 30+ users)

Users connect only to the access node; landing IPs stay hidden from GFW:

After the agent is installed on the access server (three steps above), open
Machine Detail → Forwarding in the panel → pick landing node + ports →
**[Save & Deploy]** — realm is provisioned automatically.

Architecture details, bandwidth planning, and cost models: [tools-relay/README.md](https://github.com/coolcrow/Xboard-Node/blob/main/tools-relay/README.md).

---

## FAQ

<details>
<summary>Panel not accessible after install?</summary>

```bash
docker logs aibolt-panel          # application logs
docker logs aibolt-caddy          # HTTPS proxy logs
curl http://127.0.0.1:7001        # local test
ss -tln | grep -E ":80 |:443 "   # port check
```

- Verify DNS: `dig panel.example.com` returns server IP
- Verify ports 80/443 not occupied: `ss -tln | grep -E ":80 |:443 "`
</details>

<details>
<summary>How to change admin password?</summary>

User Center → Profile → Change Password. Or reset via admin email.
</details>

<details>
<summary>Node can't connect to panel?</summary>

1. Verify panel reachable from node: `curl https://panel.yourdomain.com`
2. Check machine token (Admin → Machines page)
3. Node logs: `journalctl -u xboard-node -f`
</details>

<details>
<summary>How to enable CAPTCHA (Turnstile)?</summary>

1. Create a site at [Cloudflare Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) (free)
2. Get Site Key + Secret Key
3. Admin → System Settings → Security → CAPTCHA → enter keys and enable

Installer pre-configures security baseline (rate limiting, lockout, etc.); Turnstile requires manual key setup.
</details>

<details>
<summary>IP got blocked. What now?</summary>

| Provider | Method | Cost |
|---|---|---|
| BandwagonHost | KiwiVM → Assign New IP | $8.79 |
| DMIT | Self-service (free every 15 days) | Free |
| Vultr | Destroy & recreate | Free |
| RackNerd | Support ticket (first free) | Free |

After getting a new IP, update the node's IP in Admin → Node Management → user subscriptions auto-refresh.
</details>

<details>
<summary>How to restore from backup?</summary>

```bash
cd /opt/aibolt
ls bk-*.sqlite.gz          # list available backups
bash restore.sh bk-20261005.sqlite.gz
```

Restore automatically: decompress → integrity check → backup current DB → replace → restart → health verify.
</details>

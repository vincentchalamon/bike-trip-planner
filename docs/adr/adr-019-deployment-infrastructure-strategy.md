# ADR-019: Deployment Infrastructure Strategy

- **Status:** Partially superseded - the Oracle Cloud target stands; the Coolify control plane is replaced by [ADR-061](adr-061-deployment-ansible-gha-ssh-traefik-tunnel.md) and the Ollama service is gone ([ADR-052](adr-052-remove-ai-support.md)); budget amended by [ADR-039](adr-039-beta-right-sizing-free-tier.md)
- **Date:** 2026-03-04
- **Depends on:** ADR-016 (performance optimization), ADR-017 (Valhalla + Overpass), ADR-018 (Garmin export)
- **Amended by:** [ADR-039](adr-039-beta-right-sizing-free-tier.md) (beta right-sizing + corrected budget); [ADR-061](adr-061-deployment-ansible-gha-ssh-traefik-tunnel.md) (Coolify removed — control plane = Ansible + GitHub Actions SSH + Traefik + Cloudflare Tunnel)

> **Budget correction (2026-06-01).** The RAM budget below was wrong: it counts a local Overpass server that was since removed (ADR-025, public API instead), and it omits the host OS + Docker daemon, the self-hosted observability/analytics stacks (GlitchTip ADR-031, Plausible/ClickHouse ADR-034) and Uptime Kuma, while under-counting Coolify. The "~9.5 GB margin" was illusory: a full self-hosted stack with both LLaMA models resident lands around **17-18 GB**, not 14.5 GB. More importantly, the binding constraint is **CPU** (CPU-only LLaMA inference on 4 cores), not RAM. The corrected RAM/CPU/disk budget and the deployed beta profile (single 3B on demand, split `async`/`llm` workers, SaaS observability, deferred analytics, France-only OSM) are formalised in [ADR-039](adr-039-beta-right-sizing-free-tier.md).

## Context and Problem Statement

The project is currently developed exclusively locally via Docker Compose. Several planned features require a production deployment:

- **OAuth 2.0 PKCE (ADR-018 Phase 2)**: a public HTTPS callback URL is mandatory for the Garmin Connect integration
- **DB persistence**: durable storage of OAuth tokens and potentially of trips
- **External accessibility**: allow the planner to be used from any device
- **Itinerary sharing**: allow a user to share their trip via a (read-only) link with other people (co-riders, relatives)
- **Authentication**: exposing the application publicly requires an authentication layer to protect access to user data, prevent abuse (compute, storage) and isolate trips between users
- **Mobile application**: a public URL opens the possibility of a PWA or a native mobile application consuming the existing API

### Required infrastructure (full stack)

| Service | Role | Estimated RAM |
|---------|------|-------------|
| Caddy | Reverse proxy, TLS | ~50 MB |
| PHP (API Platform) | Stateless backend | ~500 MB |
| Next.js | SSR frontend | ~600 MB |
| Redis | Cache + message queue | ~100 MB |
| Mercure | SSE (real time) | ~50 MB |
| Workers (×5) | Async processing | ~1.5 GB |
| PostgreSQL | Persistence (planned) | ~200 MB |
| Valhalla (ADR-017) | Routing engine | ~1-2 GB |
| ~~Overpass (ADR-017)~~ | ~~POI discovery~~ — removed (ADR-025), public API | ~~2-3 GB~~ |
| **Ollama (ADR-028)** | **LLaMA 8B + 3B inference** | **~6-8 GB** |
| **Total** | | see corrected budget (ADR-039) |

Disk storage: ~6 GB (Geofabrik France PBF + Valhalla tiles) + ~10 GB (Ollama models llama3.1:8b + llama3.2:3b). The corrected RAM/CPU/disk budget is in [ADR-039](adr-039-beta-right-sizing-free-tier.md); this table keeps the initial estimate (before Overpass was removed) for the record.

### Constraints

- **Budget**: free or nearly free (personal project)
- **Domain name**: a subdomain provided by the host is acceptable (e.g. `*.fly.dev`)
- **HTTPS**: mandatory (OAuth callback, security, authentication)
- **Location**: France or Europe preferred (latency, GDPR)
- **Architecture**: existing Docker Compose, ideally reusable as-is
- **Authentication**: mandatory from the moment the app goes to production (see the dedicated section below)

### Mandatory authentication

Deploying to production turns the project from a single-user local tool into an application exposed on the Internet. Without authentication, the application is open to everyone without any constraint, which exposes it to:

- **Abuse of computational resources**: anyone can launch expensive computations (Valhalla routing, Overpass queries, weather APIs, async workers) → CPU/RAM exhaustion on resource-limited infrastructure
- **Storage explosion**: unlimited creation of trips in the DB → PostgreSQL disk saturation
- **Attacks**: brute force on the computation endpoints, scraping, application-level DDoS through legitimate but massive requests
- **No data isolation**: no separation between users, no notion of ownership of a trip

Authentication is a **mandatory security prerequisite before any production release**, independently of any other feature. It is also required for:

- Storing Garmin OAuth tokens (ADR-018 Phase 2), which are by nature tied to a user
- Persisting trips in the DB, which requires a `user → trip` relation
- Per-user rate limiting (and not only per IP)

The implementation details (authentication strategy, provider, sessions) will be covered by a dedicated ADR.

### Itinerary sharing

A distinct feature made possible by the public deployment. Once authentication and DB persistence are in place, sharing becomes possible via a link such as `/<trip-id>/share?token=<random>` (read-only, no authentication required for the recipient). This makes it possible to share a roadbook with co-riders or relatives without forcing them to create an account. The implementation details (signed token, expiry, permissions) will be covered by a dedicated ADR.

### Mobile application

The public deployment makes the backend API reachable from any client, paving the way for a mobile application. Two possible approaches:

- **PWA (Progressive Web App)**: Next.js natively supports PWA mode (Service Worker, manifest, installation on the home screen). Minimal effort — the existing frontend becomes installable on mobile as-is. Works offline for viewing roadbooks already loaded.
- **Native application** (React Native, Flutter): consumes API Platform (OpenAPI) and Mercure SSE events directly. Significant effort but gives access to the phone's native features (real-time GPS, push notifications, advanced offline mode).

The decoupled architecture (stateless API + separate frontend) makes it easy to add a mobile client without changing the backend. The details will be covered by a dedicated ADR if needed.

## Considered Options

### Option A: Oracle Cloud Always Free + Coolify + FreeDNS

An ARM Ampere A1 VM on Oracle Cloud Infrastructure's permanent free tier, with Coolify (a self-hosted open-source PaaS) for container management, and FreeDNS for the free domain name.

#### Oracle Cloud Always Free — Detailed resources

The OCI free tier is permanent (not a time-limited trial). The "Always Free" resources remain available indefinitely after the trial credits ($300/30 days) expire.

| Resource | Always Free limit |
|-----------|-------------------|
| **Compute ARM (Ampere A1)** | 4 OCPUs + 24 GB RAM (`VM.Standard.A1.Flex`). Freely divisible (1×4/24 or 2×2/12, etc.) |
| **Compute AMD (Micro)** | 2 `VM.Standard.E2.1.Micro` VMs (1/8 OCPU, 1 GB RAM each) |
| **Block volume** | 200 GB combined (boot + data) |
| **Object Storage** | 20 GB |
| **Outbound bandwidth** | 10 TB/month |
| **Reserved public IP** | 1 (permanent) |
| **Load Balancer** | 1 flexible (10 Mbps) |
| **Backups** | 5 snapshots (boot + block) |

For our project: **a single ARM VM with 4 OCPUs / 24 GB RAM** and a 150 GB boot volume (OS + Docker + Valhalla tiles + Overpass DB + PostgreSQL + margin).

**Europe regions**: Amsterdam, Frankfurt, Madrid, Milan, Marseille, Paris, Stockholm, Zurich.

**Reclaim policy**: Oracle may reclaim idle instances if, over 7 days, all 3 criteria are met simultaneously: CPU < 20% (p95), network < 20%, memory < 20%. With 5 async workers + Valhalla + Overpass in memory, this threshold should not be reached.

**"Out of capacity"**: ARM instances are in high demand in popular regions (Frankfurt, Amsterdam). [Automatic retry scripts](https://github.com/hitrov/oci-arm-host-capacity) keep retrying until capacity frees up. Prefer less saturated regions (Marseille, Madrid, Milan).

#### Coolify — Self-hosted PaaS

[Coolify](https://coolify.io/) is an open-source PaaS (a free alternative to Heroku/Vercel) that installs on any server over SSH and provides a web UI to manage deployments.

| Capability | Detail |
|----------|--------|
| Docker Compose deployment | The existing `compose.yaml` is the source of truth. Coolify deploys it as-is with persistent volumes |
| Reverse proxy | Traefik configured automatically (routing, load balancing) |
| Automatic HTTPS | Let's Encrypt certificates generated and renewed without intervention. Wildcard support via DNS challenge |
| Git integration | GitHub/GitLab webhook → every push triggers build + deploy |
| Monitoring | Web terminal, real-time logs, alerts (Discord, Telegram, email) |
| Rollback | Back to a previous version in 1 click |
| Zero vendor lock-in | Containers and configs stay on the server if we leave Coolify |

Overhead: ~500 MB of RAM (Traefik + API + dashboard), negligible on 24 GB.

#### FreeDNS — Free domain name

[FreeDNS](https://freedns.afraid.org) (freedns.afraid.org) is a free DNS service providing subdomains on shared public domains. No need to buy a domain name.

| Capability | Detail |
|----------|--------|
| Free subdomain | Choice among thousands of shared domains (e.g. `biketrip.mooo.com`, `biketrip.us.to`) |
| Configuration | A record pointing to the Oracle Cloud public IP |
| Dynamic DNS | Automatic IP update via a cron job (`curl`) |
| Let's Encrypt compatible | FreeDNS subdomains validate without problems for SSL certificates |
| OAuth callback compatible | Stable public HTTPS URL, usable for the Garmin Connect callback |

**Limits**: shared domains are sometimes whimsical; no control over the parent domain. Alternative: a `.fr` domain costs ~6€/year (OVH, Gandi) with full control.

#### Option A summary

| Criterion | Assessment |
|---------|------------|
| Resources | 4 ARM OCPUs, **24 GB RAM**, 200 GB storage — free for life |
| Europe regions | Amsterdam, Frankfurt, Madrid, Milan, Marseille, Paris, Stockholm, Zurich |
| Subdomain | Free FreeDNS (e.g. `biketrip.mooo.com`) or own domain (~6€/year) |
| Full stack | **Yes** — the only option able to run Valhalla + Overpass + the whole stack |
| HTTPS | Automatic Let's Encrypt via Coolify/Traefik |
| Deployment | Coolify (web UI, Git webhooks, rollback) |
| Total cost | **0€** (or ~6€/year with an own domain) |
| Limits | ARM architecture (`linux/arm64`). Availability varies by region. Reclaim policy on idle instances |

### Option B: Fly.io (pay-as-you-go)

Firecracker VMs with a monthly credit (~$5), deployed via the `flyctl` CLI.

| Criterion | Assessment |
|---------|------------|
| Resources | ~$5/month of credit, VMs from 256 MB to 2 GB RAM |
| Europe regions | Amsterdam, Paris, Stockholm, London, Frankfurt, Warsaw |
| Subdomain | `<app>.fly.dev` |
| Full stack | **No** — not enough RAM for Valhalla + Overpass |
| Base stack | Yes (PHP + Next.js + Redis + Mercure + workers) within the credit limit |
| PostgreSQL | Fly Postgres included (free up to 1 GB) |
| Limits | Credit card required. No official free plan since October 2024. The $5 credit does not cover the full stack |

### Option C: Render (free tier)

Managed PaaS with free instances that spin down after inactivity.

| Criterion | Assessment |
|---------|------------|
| Resources | 750h/month of Starter instances (512 MB RAM), 100 GB bandwidth |
| Europe regions | Frankfurt (paid only) — free tier restricted to the US (Oregon) |
| Subdomain | `<app>.onrender.com` |
| Full stack | **No** |
| Base stack | **No** — spin-down after 15 min of inactivity (10-30s cold start), incompatible with Mercure SSE and permanent workers |
| PostgreSQL | 1 GB free, deleted after 90 days |
| Redis | 25 MB ephemeral |
| Limits | 512 MB/service max. Free tier in the US only. Spin-down kills SSE connections |

### Option D: Koyeb (free tier)

PaaS with a data center in Paris, permanent but very limited free tier.

| Criterion | Assessment |
|---------|------------|
| Resources | 1 service, 512 MB RAM, 0.1 vCPU |
| Europe regions | **Paris**, Frankfurt |
| Subdomain | `<app>.koyeb.app` |
| Full stack | **No** |
| Base stack | **No** — only 1 service allowed on the free tier, can host only one container |
| PostgreSQL | 1 free database included |
| Limits | Too constrained for a multi-service project. Only useful for an isolated microservice |

### Option E: Railway ($5/month)

Managed PaaS with native PostgreSQL/Redis integration. No permanent free tier (30-day trial with $5 of credit).

| Criterion | Assessment |
|---------|------------|
| Resources | Hobby plan $5/month with $5 of credit included, 8 GB RAM max |
| Europe regions | Europe-West |
| Subdomain | `<app>.up.railway.app` |
| Full stack | **Partial** — 8 GB RAM and 5 services max are not enough for Valhalla + Overpass |
| Base stack | **Yes** — PHP + Next.js + Redis + PostgreSQL + Mercure + workers (if grouped) |
| Limits | Not free ($5/month). Limit of 5 services per project, the full stack needs 8+ |

### Rejected option: Strava as a deployment gateway to Garmin

Not relevant to infrastructure, but documented here for completeness: the Strava v3 API is read-only for routes (no `POST /routes` endpoint). Sending planned itineraries via Strava is technically impossible. See ADR-018.

## Decision Outcome

**Chosen: Option A (Oracle Cloud Always Free + Coolify + FreeDNS) as the production target.**

It is the only free infrastructure offering enough resources (24 GB RAM, 200 GB storage) for the full stack including Valhalla and Overpass.

**Fallback option: Option B (Fly.io) for an intermediate deployment** without Valhalla/Overpass, with the public Overpass API as a fallback (higher latency, see ADR-017).

### Target infrastructure architecture

```text
                        ┌─────────────────────────────────────┐
                        │           FreeDNS / Domain           │
                        │     biketrip.mooo.com (A record)     │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │    Oracle Cloud Always Free (ARM)    │
                        │  4 OCPUs · 24 GB RAM · 200 GB disk   │
                        │  Reserved public IP · 10 TB/month    │
                        │  Region: Marseille / Paris / Madrid   │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │        Coolify (self-hosted PaaS)    │
                        │  Web UI · Git webhooks · Rollback    │
                        │  ~500 MB RAM                         │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │     Traefik (reverse proxy + TLS)    │
                        │  Let's Encrypt auto · Port 443/80    │
                        └───┬──────┬──────┬──────┬────────────┘
                            │      │      │      │
              ┌─────────────▼┐ ┌───▼────┐ ┌▼─────▼──────────┐
              │   Next.js    │ │  PHP   │ │    Mercure       │
              │  (frontend)  │ │ (API)  │ │    (SSE)         │
              │  Port 3000   │ │  8000  │ │   Port 3001      │
              │  ~600 MB     │ │ ~500MB │ │   ~50 MB         │
              └──────────────┘ └───┬────┘ └──────────────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    │              │              │
              ┌─────▼────┐  ┌─────▼────┐  ┌──────▼─────┐
              │  Redis   │  │PostgreSQL│  │  Workers   │
              │ (cache + │  │  (DB)    │  │   (×5)     │
              │  queue)  │  │  ~200 MB │  │  ~1.5 GB   │
              │  ~100 MB │  └──────────┘  └──────┬─────┘
              └──────────┘                       │
                                   ┌─────────────┼─────────────┐
                                   │             │             │
                            ┌──────▼───┐  ┌──────▼───┐  ┌─────▼──────┐
                            │ Valhalla │  │ Overpass │  │  Ext. APIs  │
                            │ (routing)│  │  (POI)   │  │ OpenMeteo   │
                            │  ~1.5 GB │  │  ~2.5 GB │  │ Komoot etc. │
                            │ Port 8002│  │ Port 8003│  └────────────┘
                            └──────────┘  └──────────┘

                        ┌─────────────────────────────────────┐
                        │  RAM estimate (CORRECTED — ADR-039)  │
                        │  Full self-hosted, both models hot   │
                        │                                       │
                        │  OS + Docker daemon       ~800 MB    │
                        │  Coolify + Traefik        ~700 MB    │
                        │  Next.js                  ~600 MB    │
                        │  PHP (FrankenPHP, API+SSE) ~600 MB   │
                        │  Redis                    ~150 MB    │
                        │  PostgreSQL               ~250 MB    │
                        │  Workers async + llm     ~1000 MB    │
                        │  Valhalla                ~1500 MB    │
                        │  Ollama (LLaMA 8B+3B)    ~7000 MB    │
                        │  GlitchTip (ADR-031)     ~1000 MB    │
                        │  Plausible + ClickHouse  ~2000 MB    │
                        │  Uptime Kuma              ~150 MB    │
                        │  ─────────────────────────────────   │
                        │  Total full self-hosted ~17-18 GB    │
                        │  Available               24.0 GB     │
                        │  Actual margin           ~6-7 GB     │
                        │  Limiting factor = CPU (4 cores)     │
                        └─────────────────────────────────────┘
```

Local Overpass is removed (ADR-025): POIs come from the public API. The "~9.5 GB" margin of the first draft was wrong (see the correction note at the top). The profile actually deployed in beta (<10 users, single 3B model on demand, separate `async`/`llm` workers, SaaS observability, deferred analytics) fits in about ~9-9.5 GB and is detailed in [ADR-039](adr-039-beta-right-sizing-free-tier.md).

### Progressive deployment strategy

1. **Immediate phase**: continue in local development (Docker Compose)
2. **Pre-production phase**: implement authentication (mandatory prerequisite before any public exposure) and DB persistence (PostgreSQL + Doctrine)
3. **Intermediate phase**: deploy the base stack on Fly.io or Railway to validate the Garmin OAuth flow (ADR-018 Phase 2) and itinerary sharing
4. **Target phase**: migrate to Oracle Cloud + Coolify for the full stack with Valhalla + Overpass

## Consequences

### Positive

- **Zero cost**: the Oracle Cloud free tier is permanent and sufficient for a personal project
- **Full stack**: 24 GB RAM makes it possible to run every service, including the most resource-hungry ones (Valhalla, Overpass)
- **Sovereignty**: self-hosted, data under control
- **Itinerary sharing**: public exposure enables sharing roadbooks via a simple (read-only) link
- **Mobile application**: the decoupled architecture (stateless API + separate frontend) makes it possible to add a mobile client (PWA or native) without changing the backend

### Negative

- **ARM architecture**: some Docker images (notably Valhalla, Overpass) may need a rebuild for `linux/arm64`. Official images generally support ARM, but it is a compatibility risk
- **Operational maintenance**: no managed PaaS — updates, backups and monitoring are on us
- **Oracle reliability**: Oracle may reclaim idle instances. The free tier may change without notice
- **No native subdomain**: requires an external domain (purchase or FreeDNS)
- **Mandatory authentication**: the public deployment requires implementing a complete authentication layer (sign-up, login, sessions, data isolation) before going live — a significant effort not yet planned
- **Ollama = hard dependency**: LLaMA inference cannot be skipped (see ADR-028 and issue #375, v2 arbitration "AI always on"). Ollama must be operational before the application is considered available. In beta, only `llama3.2:3b` is loaded on demand (ADR-039); the full self-hosted profile with both models resident (`llama3.1:8b`, `llama3.2:3b`) fits in RAM (~6-7 GB of margin on 24 GB) but saturates the 4 CPU cores — that is the real limiting factor, not RAM. The Coolify healthcheck must include an Ollama ping (`GET /api/health`) in addition to the existing application healthchecks.

### Neutral

- Coolify simplifies operations (web UI, Git deployment, automatic SSL) but adds a software layer to maintain

## Sources

- [Oracle Cloud Free Tier](https://www.oracle.com/cloud/free/)
- [Oracle Cloud Always Free Resources](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)
- [Coolify — Self-Hosted PaaS](https://coolify.io/self-hosted)
- [Coolify + Oracle Cloud Setup](https://coolify.io/docs/knowledge-base/server/oracle-cloud)
- [Fly.io Pricing](https://fly.io/pricing/)
- [Render Free Tier](https://render.com/docs/free)
- [Koyeb Pricing](https://www.koyeb.com/pricing)
- [Railway Pricing](https://docs.railway.com/pricing/plans)
- [Strava API v3 Reference](https://developers.strava.com/docs/reference/) — no route creation endpoint
- [FreeDNS — afraid.org](https://freedns.afraid.org)
- [Coolify Docker Compose Deployment](https://coolify.io/docs/knowledge-base/docker/compose)
- [Coolify Traefik SSL / Let's Encrypt](https://coolify.io/docs/knowledge-base/proxy/traefik/overview)
- [OCI ARM "Out of Capacity" retry script](https://github.com/hitrov/oci-arm-host-capacity)

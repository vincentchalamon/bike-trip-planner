# ADR-039: Beta Right-Sizing on the Oracle Free Tier + Corrected RAM/CPU/Disk Budget

- **Status:** Proposed — flips to Accepted once the implementing PRs land (#563 merged; #564-#569 open in Sprint 34.5)
- **Date:** 2026-06-01
- **Depends on:** ADR-019 (Deployment Infrastructure), ADR-028 (Ollama/LLaMA integration), ADR-031 (Error tracking), ADR-034 (Usage analytics), ADR-037 (Dev/Prod Docker convergence)
- **Amends:** ADR-019 (RAM budget correction)

> **Superseded by [ADR-042](adr-042-optional-multi-provider-ai-byo-token.md) (2026-06-19):** the self-hosted Ollama/LLaMA tier was replaced by an optional, per-user, multi-provider bring-your-own-token model. The Ollama service, OLLAMA_* env and the bundled LLM resource have been removed, which **frees the LLM RAM/CPU budget on the beta VM** (no resident model, no serialised `llm` worker, no CPU-only inference bottleneck — the constraint this ADR right-sized around). The pipeline shape (2-pass analysis, gate, graceful degradation) still holds, but inference now runs on the user's chosen cloud provider with their own key.

> **Note on numbering.** The source GitHub issue (#570) titled this ADR "ADR-035". ADR-035 was already allocated to RGPD account erasure (sprint 34), and 036 (manual OSM refresh) / 037 (dev/prod convergence) were taken on disk. The next free number, 038, is already claimed by an open PR (#578, backup/DR for #527). To avoid a collision the number slid to **ADR-039**. The technical scope is unchanged.

## Context and Problem Statement

ADR-019 retained the Oracle Cloud Always Free ARM VM (4 OCPUs / 24 GB RAM / 200 GB disk) as the production target, on the basis that it was the only free tier able to run the *complete* self-hosted stack including a local Overpass server. Two things have since changed, and the ADR-019 budget no longer reflects reality:

1. **Overpass local was removed (ADR-025).** POI discovery now uses the public `overpass-api.de` API via the search-corridor strategy. The ~2.5 GB line in the ADR-019 budget for a local Overpass server is dead weight: that service is not deployed.

2. **The ADR-019 budget under-counted everything else.** It omitted the host OS + Docker daemon, the self-hosted observability stack (GlitchTip, ADR-031), uptime monitoring (Uptime Kuma, runbook `uptime-monitoring.md`), and the analytics stack (Plausible CE + ClickHouse, ADR-034). It also under-counted Coolify. The headline "~9.5 GB margin" on a 24 GB VM was therefore illusory: a fully self-hosted stack with both LLaMA models resident, GlitchTip, Plausible/ClickHouse and Uptime Kuma lands around **17-18 GB**, not 14.5 GB.

3. **The real limiting factor is CPU, not RAM.** Inference runs CPU-only on the Ampere A1 (4 cores, no GPU). A single LLaMA generation saturates several cores for seconds; the 8B analysis pass (ADR-028) takes up to ~30 s per stage. With 5 async workers able to fire LLM analyses in parallel, the VM thrashes on CPU long before RAM is exhausted. Sizing the deployment around RAM headroom misses the actual bottleneck.

The project is entering a **closed beta with fewer than 10 users**. We need a deployment profile sized for that reality, not for the theoretical "everyone self-hosted, both models hot, full observability" maximum. This ADR formalises that profile and corrects the ADR-019 budget.

## Decision

Adopt a **beta right-sizing profile** for the Oracle Free Tier deployment, optimised for the CPU bottleneck and a <10-user load, while keeping a clean migration path to GCP when the beta outgrows the free tier.

### 1. Single 3B model, on-demand (no resident 8B)

Run **only `llama3.2:3b`**, loaded on demand rather than kept hot for both models. The 8B analysis model (ADR-028) is not resident in the beta profile: keeping two models pinned in memory is what pushed the budget over, and the 8B pass is the heaviest CPU consumer. The 3B model covers the conversational brief intake and the in-ride POI assistant. Stage analysis narrative either uses the 3B model or is deferred; this is an explicit beta trade-off documented in `docs/LLaMA.md`. Ollama `keep_alive` is tuned so the single model unloads under memory pressure instead of co-resident pinning.

### 2. Split Messenger workers: `async` vs `llm`

Today all messages, including `AnalyzeStageWithLlmMessage`, route to the single `async` transport consumed by `replicas: 5` (see `api/config/packages/messenger.php` and `compose.yaml`). In the beta profile, LLM work is isolated onto its own `llm` transport with a **single** dedicated consumer (`worker-llm`, 1 replica), while the non-LLM enrichments keep the `async` transport with a reduced replica count. This caps concurrent CPU-only inference to one at a time and stops a burst of trip computations from saturating all 4 cores with parallel LLaMA runs. The async (I/O-bound) work stays parallel; the LLM (CPU-bound) work is serialised.

### 3. LLM endpoint seam

Keep the LLM call behind a single endpoint seam (`OLLAMA_BASE_URL`, already an env var in `compose.dev.yaml`) so the inference backend is swappable without code changes. This is what makes the GCP migration path (below) a config change rather than a refactor: point the seam at a GPU-backed or managed inference endpoint and the application is unchanged.

### 4. SaaS observability, deferred analytics

For the beta, **do not self-host the observability and analytics stacks** on the Oracle VM:

- **Error tracking:** use **Sentry SaaS** (free tier) instead of self-hosted GlitchTip (ADR-031). The Sentry SDKs are already wired (`sentry/sentry-symfony`, `@sentry/nextjs`); only the DSN changes. This removes GlitchTip-web/worker/postgres/redis (~500 MB+) from the VM.
- **Uptime:** use **UptimeRobot** (free external tier) as the primary monitor instead of co-hosted Uptime Kuma. An external monitor is strictly better for the "VM is down" case anyway (a co-hosted monitor dies with the VM).
- **Analytics:** **defer** the Plausible CE + ClickHouse stack (ADR-034) entirely. ClickHouse is a heavy resident process for <10 users of analytics signal. Analytics is revisited post-beta.

ADR-031 and ADR-034 remain the accepted *target* architecture for a self-hosted, post-beta deployment; this ADR records the beta deviation, not a reversal.

### 5. Memory limits

Apply explicit Docker `mem_limit` / `deploy.resources.limits.memory` to the app services (php, worker, worker-llm, pwa, redis, valhalla) so a single misbehaving service cannot OOM-kill the VM. Without limits, the FrankenPHP workers and Next.js can grow unbounded under load; with the CPU bottleneck this manifests as memory creep during queue backlogs.

### 6. OSM France build local

The Valhalla tiles and the OSM extract are built from the **France** Geofabrik PBF locally on the VM (ADR-017, ADR-033, manual refresh per ADR-036), not pulled as a pre-built artifact. France-only keeps the tile build and disk footprint within the free-tier budget; a wider extract is a post-beta concern tied to the GCP migration.

### Corrected budget

The corrected budget reflects the actual beta profile (single 3B on demand, public Overpass, SaaS observability, deferred analytics) and, separately, the realistic *full self-hosted* maximum that ADR-019 should have shown.

#### Beta profile (deployed)

| Service | Rôle | RAM |
|---------|------|-----|
| OS + Docker daemon | Host | ~800 MB |
| Coolify + Traefik | PaaS + reverse proxy | ~700 MB |
| PHP (FrankenPHP, API + Mercure embedded) | Backend stateless + SSE | ~600 MB |
| Next.js | Frontend SSR | ~600 MB |
| Redis | Cache + queue | ~150 MB |
| Worker `async` (×2) | Enrichments I/O-bound | ~600 MB |
| Worker `llm` (×1) | LLM inference, serialised | ~350 MB |
| PostgreSQL | Persistance | ~250 MB |
| Valhalla (France) | Routing | ~1.5 GB |
| Ollama (`llama3.2:3b`, on demand) | Inférence LLM | ~3-4 GB |
| **Total beta** | | **~9-9.5 GB RAM** |

Observability (Sentry) and uptime (UptimeRobot) are **off-VM SaaS**; analytics is **deferred**. Overpass is the public API (off-VM). Disk: ~5 GB (France PBF + Valhalla tiles) + ~2.5 GB (single 3B model).

#### Full self-hosted maximum (corrected ADR-019 reference)

For completeness, the realistic cost of the *fully self-hosted* target (both models hot, GlitchTip, Plausible/ClickHouse, Uptime Kuma) is:

| Bloc | RAM |
|------|-----|
| OS + Docker | ~800 MB |
| Coolify + Traefik | ~700 MB |
| App core (php + pwa + redis + postgres + workers) | ~2.5 GB |
| Valhalla | ~1.5 GB |
| Ollama (3B + 8B résidents) | ~7 GB |
| GlitchTip (web + worker + pg + redis) | ~1 GB |
| Plausible + ClickHouse | ~2 GB |
| Uptime Kuma | ~150 MB |
| **Total full self-hosted** | **~17-18 GB RAM** |

This leaves ~6-7 GB headroom on 24 GB, not 9.5 GB — and, more importantly, **RAM is not the binding constraint**. The 4 CPU cores are.

### Limiting factor: CPU

The Ampere A1 has **4 cores and no GPU**. LLaMA inference is CPU-only. A single 3B generation already pins multiple cores for seconds; an 8B analysis pass runs up to ~30 s. The deployment is therefore sized around **inference concurrency**, not RAM:

- One `llm` worker means at most one inference at a time.
- Async (I/O-bound) work stays parallel and does not contend for the same bottleneck.
- The reclaim policy (ADR-019: p95 CPU < 20 % over 7 days) is comfortably avoided because even modest beta traffic keeps inference cores busy.

### Migration path to GCP

When the beta outgrows the free tier (more users, resident 8B, faster inference, self-hosted observability), migrate off Oracle to GCP:

1. **Inference:** point `OLLAMA_BASE_URL` at a GPU-backed Compute Engine instance (or a managed endpoint). The endpoint seam (decision 3) makes this a config change. GPU inference removes the CPU bottleneck and unblocks the resident 8B analysis pass.
2. **Compute:** move the app stack to a GCP VM (Compute Engine) or GKE, sized on the corrected full-stack budget (~18 GB) plus inference.
3. **Observability/analytics:** re-enable self-hosted GlitchTip (ADR-031) and Plausible CE (ADR-034), or keep SaaS — decided at migration time on cost vs. control.
4. **Region:** `europe-west1` (Belgium) / `europe-west9` (Paris) for latency + RGPD.

The Docker Compose topology (ADR-037) is portable as-is; the migration is an infrastructure swap, not an application rewrite.

## Consequences

### Positive

- The deployment is sized around the real bottleneck (CPU), so the VM stays responsive under beta load.
- Serialising LLM work on a dedicated `llm` worker prevents a trip-computation burst from saturating all 4 cores.
- Dropping GlitchTip, ClickHouse and Uptime Kuma from the VM frees several GB and a lot of CPU for inference.
- SaaS uptime (UptimeRobot) survives a full VM outage, which a co-hosted monitor cannot.
- Memory limits prevent a single service from OOM-killing the whole VM.
- The endpoint seam keeps the GCP migration a config change.

### Negative

- **Beta uses Sentry SaaS:** error data leaves the perimeter (free-tier cap, third party), a deliberate deviation from the ADR-031 self-hosted stance for the beta only.
- **No analytics during beta:** product usage signal is lost until the Plausible stack is re-enabled post-beta.
- **No resident 8B:** stage-analysis narrative is degraded (3B or deferred) until the GCP migration provides GPU inference.
- **Single `llm` worker is a throughput ceiling:** LLM analyses queue behind each other; acceptable at <10 users, a known scaling limit beyond.

### Neutral

- ADR-031 and ADR-034 remain the accepted post-beta target; this ADR records the beta deviation, not a reversal.
- France-only OSM extract is unchanged from the existing manual-refresh posture (ADR-036).

## Sources

- [ADR-019](adr-019-deployment-infrastructure-strategy.md) — Deployment infrastructure (Oracle Always Free), budget amended by this ADR
- [ADR-025](adr-025-removal-of-self-hosted-overpass.md) — Removal of self-hosted Overpass (public API)
- [ADR-028](adr-028-ollama-llama-integration.md) — Ollama/LLaMA integration (2-pass, hard dependency, context window)
- [ADR-031](adr-031-error-tracking-strategy.md) — Error tracking (GlitchTip self-hosted target)
- [ADR-034](adr-034-usage-analytics-plausible.md) — Usage analytics (Plausible CE target)
- [ADR-037](adr-037-docker-dev-prod-convergence.md) — Dev/Prod Docker convergence on FrankenPHP
- [docs/LLaMA.md](../LLaMA.md) — LLM architecture and beta 3B profile
- [docs/runbooks/uptime-monitoring.md](../runbooks/uptime-monitoring.md) — Uptime Kuma + UptimeRobot
- [Oracle Cloud Always Free Resources](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)

# ADR-043: Synchronous Structural Computation with Per-Block Asynchronous Enrichments

- **Status:** Proposed — flips to Accepted once the implementing PRs land.
- **Date:** 2026-06-21
- **Depends on:** ADR-006 (Pacing Engine), ADR-040 (Local-First Reference Data in PostGIS), ADR-042 (Optional Per-User AI, BYO token)
- **Supersedes:** ADR-027 (Gate Mechanism and Two-Phase Pipeline) — specifically the user-facing Preview→Analysis gate and the wizard's *Aperçu*/*Analyse* steps. The `ComputationTracker` completion-gate machinery introduced by ADR-027 is **retained** for the asynchronous enrichments.

## Context and Problem Statement

ADR-027 split trip computation into a Preview phase and a user-gated Analysis phase. Its premise was that analysis was dominated by slow runtime calls — Overpass OSM scans plus weather — taking 10–30s, so a "Launch full analysis" gate gave early feedback and avoided wasting expensive calls on a wrong route.

Two later decisions invalidated that premise:

1. **ADR-040** moved all OSM/tourism reference data into a local PostGIS index (Tier-1). POI / accommodation / alert detection is now indexed spatial SQL (`ST_DWithin`/`ST_Covers`), sub-millisecond, with no runtime network. The pacing engine (ADR-006) was always pure in-memory CPU.
2. **ADR-042** turned AI into an optional per-user BYO-token feature (no self-hosted Ollama). The LLM is now the dominant — and only user-cost-bearing — slow step.

Code-level reality confirms it: `GenerateStagesHandler` / `PacingEngineRegistry` are pure in-memory (no Valhalla, no DB); 13 of 15 enrichment handlers read PostGIS locally; only **weather** (Open-Meteo, network, cached 3h) and the **LLM** passes remain slow; the external **route fetch** for a link (Komoot/Strava/RideWithGPS) is a network call (10s timeout, 2 retries). One structural scan is an outlier: `WaysRepository::findInCorridor` is currently unbounded (no `LIMIT`, no `highway` filter, `::geography` cast) and can exceed 1s on a long, dense route.

Therefore the two-phase user gate no longer protects against a long *structural* computation — it would only gate the LLM (the user's token/cost). The *Aperçu* step, which existed only to precede *Analyse*, loses its purpose. The recette (#649) also reported the wizard's Aperçu/Analyse steps as confusing and frequently skipped.

## Decision Drivers

- **UX first** — minimize visible waiting and remove artificial steps.
- **Reload-safe / shareable** — the trip URL must re-render the correct state on reload.
- **Respect the BYO AI token** — never burn the user's token automatically and silently.
- **Simpler backend** where the asynchronous machinery is no longer warranted.

## Considered Options

### Option A — Keep the ADR-027 two-phase user gate

Rejected. The gate's premise (slow structural analysis) no longer holds post-PostGIS; the Aperçu/Analyse steps are now friction the recette flagged.

### Option B — Fully synchronous, including network (fetch + weather + LLM in the HTTP request)

Rejected. The external link fetch (up to ~30s with retries) and weather/LLM are third-party network calls; blocking the HTTP request on them is fragile (timeouts) and ties up a web worker for the whole duration.

### Option C — Synchronous structural computation, per-block asynchronous enrichments (Chosen)

Structural data (pacing + PostGIS scans + alerts) is computed without a user gate; the network/LLM enrichments are asynchronous, each surfaced as its own block with a spinner.

## Decision

**Option C.**

### Structural computation (no user gate)

Structural = pacing (stage splitting) + all PostGIS scans (accommodations, POIs, water, bike shops, health services, stations, borders, ferries, fords, cultural POIs, events) + the alert engine.

- **GPX upload:** runs synchronously in the State Processor (parsing is local), so the HTTP response already carries the structured trip.
- **External link:** the fetch stays asynchronous (it is a network call) behind a single loader; the worker chains fetch → stages → scans → alerts and publishes one terminal *trip ready (structural)* Mercure event. Same UX: one loader, then the complete trip — no intermediate step.

### Asynchronous enrichments (per block, with spinner)

- **Weather** (Open-Meteo) and its dependents (wind, fords-from-weather).
- **AI**: trip overview / per-stage insights / chat. Triggered automatically when a token is configured (configuring the key is the consent), with a manual "regenerate" affordance; chat is on demand. AI never blocks the structural trip.

### Editing at the trip page

Per-day distance and profile/pacing edits re-pace and re-scan the affected stages **synchronously / in place** (no Valhalla re-routing, no network). AI is **not** re-run in a blocking way — it is skipped by default (reusing the existing `skipAiAnalysis` flag) and refreshed on demand / in the background.

### State model

The persisted trip status reflects structural readiness (e.g. `draft` → `ready`) plus per-block enrichment states (weather: pending/ready, ai: pending/ready/idle), exposed by `GET /trips/{id}/detail` so the page is reload-safe and authoritative on mount. Mercure SSE only drives live updates; a missed event is recovered from the persisted state on reload.

### Performance prerequisite

`WaysRepository::findInCorridor` must be bounded (add a `LIMIT` and a `highway` pre-filter, and avoid the `::geography` cast that defeats the GIST index) so the synchronous structural budget stays well under ~1s even on long, dense routes. If a route still exceeds budget, terrain may fall back to an asynchronous block with a spinner.

### Reused machinery

The `ComputationTracker` completion gate and `TripCompletionGate` (ADR-027, hardened in PR #740) are retained to coordinate the asynchronous weather/AI blocks and publish their terminal events.

## Consequences

### Positive

- Two visible states (Saisie → Voyage) instead of four wizard steps; the structural trip is visible almost immediately.
- Reload-safe and shareable URLs; the BYO AI token is spent deliberately.
- The asynchronous fan-out shrinks to weather + AI; in-place edits with no re-analysis wait.

### Negative

- Backend refactor: move structural computation out of the async fan-out; collapse the wizard; introduce per-block statuses.
- The `WaysRepository` bound must land first (PR1).
- Visual baselines (VR) and recette/mocked specs that assume the wizard must be updated.
- `POST /trips/{id}/analyze` is removed/repurposed (OpenAPI + TypeScript client regenerated via `make typegen`).

### Neutral

- The route fetch for links remains asynchronous (it is a network call); weather/LLM latency is unchanged, only relocated to non-blocking blocks.
- ADR-027's tracker/Mercure machinery is reused, not rebuilt.

## Sources

- [ADR-006: Pacing Engine and Dynamic Stage Generation Algorithm](adr-006-pacing-engine-and-dynamic-stage-generation-algorithm.md)
- [ADR-027: Gate Mechanism and Two-Phase Pipeline](adr-027-gate-mechanism-two-phase-pipeline.md)
- [ADR-040: Local-First Reference Data in PostGIS](adr-040-local-first-reference-data-postgis.md)
- [ADR-042: Optional Multi-Provider AI (BYO Token)](adr-042-optional-multi-provider-ai-byo-token.md)

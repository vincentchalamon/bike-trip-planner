# ADR-027: Gate Mechanism and Two-Phase Pipeline (Preview → Analysis)

- **Status:** Accepted
- **Date:** 2026-04-19
- **Depends on:** ADR-001 (Global Architecture), ADR-005 (External API caching), ADR-022 (Persistent Storage)
- **Supersedes:** ADR-001 (single-phase synchronous computation model described in the Backend Implementation section)

## Context and Problem Statement

The current pipeline triggers all computations immediately upon route import: GPX parsing, pacing engine, OSM scans, weather checks, and all enrichments run sequentially or in parallel before the user sees any result. This creates two problems:

1. **No early feedback** — The user cannot see the route on the map or the elevation profile until the full computation (including slow external API enrichments) has completed. On a first load with a cold Redis cache, this can take 10–30 seconds.
2. **No user control** — The user cannot inspect and validate the generated stages before the expensive analysis phase (OSM scans, LLaMA inference) is triggered. If the track is wrong (e.g., a wrong Komoot URL), all enrichment resources are wasted.

A further constraint comes from the planned LLaMA 8B integration: the LLM must receive fully enriched stage data as input. It cannot run until all enrichment handlers (OSM scanner, weather, accommodations, cultural POIs, events) have completed. Today there is no mechanism to detect that all enrichments are finished and to trigger a downstream step conditionally on that fact.

---

## Decision Drivers

- **Fast feedback** — The route preview (map, elevation profile, stage statistics) must be available in under 2 seconds on a warm cache and under 5 seconds on a cold cache.
- **User control** — The user must be able to validate the generated stages before committing to the full analysis.
- **Gate prerequisite for LLaMA** — A reliable mechanism is required to detect when all enrichment handlers have completed so that LLaMA 8B inference can be triggered exactly once.
- **Mercure event consistency** — Frontend progress indicators require a well-defined event taxonomy: per-step progress events and a single terminal event.
- **Backward compatibility** — The existing `/trips` POST endpoint and pacing engine must be preserved; the new pipeline adds a second endpoint without breaking the first.

---

## Considered Options

### Option A: Single-Phase Pipeline (Current)

Keep the existing model: import triggers all computations atomically. The frontend waits for a single `TRIP_READY` event.

**Pros:**

- No additional API surface.
- Simple mental model.

**Cons:**

- No early preview; user waits 10–30s before seeing anything.
- No user checkpoint before expensive enrichments.
- No natural trigger point for LLaMA (all enrichments share the same flat event bus).
- Impossible to scale independently: the preview computation (fast, CPU-bound) and the enrichment phase (slow, I/O-bound) share the same worker pool.

**Rejected.** Does not meet the fast-feedback or gate requirements.

### Option B: Two Sequential Phases Without Gate

Split into Phase 1 (import + pacing) and Phase 2 (all enrichments + LLaMA), triggered automatically one after the other without user intervention.

**Pros:**

- Delivers an early preview.
- Simpler than a gate mechanism.

**Cons:**

- Phase 2 starts automatically, so the user still cannot validate the route before enrichments begin.
- LLaMA is still triggered without a reliable completion signal for all enrichments (same race condition as today).
- Removes user agency.

**Rejected.** Does not satisfy the user-control requirement.

### Option C: Two-Phase Pipeline With Gate (Chosen)

Phase 1 is triggered automatically on import; Phase 2 is triggered explicitly by the user via a dedicated API endpoint. A `ComputationTracker` gate detects when all Phase 2 enrichment handlers have completed and triggers LLaMA inference exactly once.

---

## Decision

**Option C: Two-phase pipeline with gate mechanism.**

### Phase 1 — Preview (Acte 1.5)

Triggered automatically when `POST /trips` is called.

| Step | Handler | Output |
|------|---------|--------|
| GPX fetch + parse | `FetchAndParseRouteHandler` | Raw + decimated points stored in Redis |
| Pacing engine | `GenerateStagesHandler` | `Stage[]` persisted to PostgreSQL |
| Preview ready | Mercure SSE | `TRIP_PREVIEW_READY` event |

The frontend renders the map, elevation profile, and stage statistics immediately upon receiving `TRIP_PREVIEW_READY`. The user can inspect the route and decide whether to proceed.

### Phase 2 — Analysis (Acte 2)

Triggered explicitly by `POST /trips/{id}/analyze` (new endpoint).

The handlers marked *(new)* do not exist yet and are introduced as part of implementing this ADR.

| Step | Handler | Output |
|------|---------|--------|
| OSM scan (POIs, accommodations, bike shops, cemeteries) | `ScanAllOsmDataHandler` | Redis cache |
| POI scan | `ScanPoisHandler` | Alert(s) |
| Weather check | `FetchWeatherHandler` | Alert(s) |
| Accommodation scan | `ScanAccommodationsHandler` | Alert(s) |
| Terrain analysis | `AnalyzeTerrainHandler` | Alert(s) |
| Wind analysis | `AnalyzeWindHandler` | Alert(s) |
| Bike shop check | `CheckBikeShopsHandler` | Alert(s) |
| Water point check | `CheckWaterPointsHandler` | Alert(s) |
| Cultural POI scan | `CheckCulturalPoisHandler` | Alert(s) |
| Railway station check | `CheckRailwayStationsHandler` | Alert(s) |
| Health service check | `CheckHealthServicesHandler` | Alert(s) |
| Border crossing check | `CheckBorderCrossingHandler` | Alert(s) |
| Calendar check | `CheckCalendarHandler` | Alert(s) |
| Event scan | `ScanEventsHandler` | Alert(s) |
| Market scan | `ScanMarketsHandler` *(new)* | Alert(s) |
| Wikidata enrichment | `EnrichWithWikidataHandler` *(new)* | Enriched POI data |
| LLaMA inference | `RunLlamaInferenceHandler` *(new)* | Narrative summary |

All enrichment handlers run in parallel via Symfony Messenger. Each handler calls `ComputationTracker::markDone()` on completion. The tracker's gate fires `RunLlamaInferenceHandler` exactly once when every expected computation reports `done`; atomicity of that "exactly once" is provided by the Symfony Lock primitive described in the idempotency note below, not by the PSR-6 cache itself.

### Gate Mechanism — `ComputationTracker`

The existing `ComputationTracker` (backed by the PSR-6 `cache.trip_state` pool, itself wired on Redis) already coordinates completion. Its current public surface is:

- `initializeComputations(string $tripId, array $computations): void` — seeds every expected `ComputationName` with status `pending` under cache key `trip.{tripId}.computation_status`.
- `markRunning()` / `markDone()` / `markFailed()` / `resetComputation()` — lifecycle status transitions (PSR-6 read-modify-write; not atomically safe under concurrent writes — see idempotency note below).
- `isAllComplete(string $tripId): bool` — true when every tracked computation is in `done` or `failed`.

For Phase 2 the contract is unchanged; only the expected set and the post-completion hook differ:

```text
POST /trips/{id}/analyze
  │
  ├─► ComputationTracker::initializeComputations(tripId, [...Phase2 ComputationNames])
  │     Persists the expected set under cache key `trip.{tripId}.computation_status` (TTL 1800s)
  │
  ├─► Dispatch ScanAllOsmDataHandler (async)
  ├─► Dispatch FetchWeatherHandler   (async)
  ├─► Dispatch ScanAccommodationsHandler (async)
  ├─► ... (all enrichment handlers)
  │
  └─► Each handler on completion:
        ComputationTracker::markDone(tripId, ComputationName::X)
          │  Updates the cached status map (PSR-6 read-modify-write, not atomic)
          │
          ├─ if ComputationTracker::isAllComplete(tripId):
          │     → Acquire Symfony Lock(`trip.{tripId}.llama`, TTL 3600s)
          │         ├─ acquired → Dispatch RunLlamaInferenceHandler (exactly once)
          │         └─ refused  → another handler already dispatched, skip
          │
          └─ Publish computation_step_completed event via Mercure
```

**Idempotency of the LLaMA trigger.** `isAllComplete()` can return `true` to several concurrent handlers racing on the last computation, and `ComputationTracker::updateStatus()` is a non-atomic PSR-6 read-modify-write, so neither the tracker nor an in-band marker computation is sufficient to guarantee "exactly once" dispatch.

The dispatch site therefore uses a dedicated Symfony `LockFactory` (backed by the Redis store, i.e. `Symfony\Component\Lock\Store\RedisStore`) to wrap the `RunLlamaInferenceHandler` dispatch:

- key: `trip.{tripId}.llama` (one lock per trip analysis)
- TTL: 3600s, generous enough to cover the full LLaMA inference
- acquisition mode: non-blocking (`acquire(false)`); the second and subsequent racers observe refusal and exit silently.

This is the only concurrency primitive outside the PSR-6 cache path, and it is confined to this single dispatch point. Integration with `ComputationTracker` remains unchanged: `ComputationName` gains the Phase 2 values, but no atomic marker entry is needed because the lock — not the cache — provides the exactly-once guarantee.

### Mercure Events

| Event type | Phase | Payload | Frontend effect |
|---|---|---|---|
| `TRIP_PREVIEW_READY` | 1 | `{tripId, stages[]}` | Render map + elevation profile |
| `computation_step_completed` | 2 | `{tripId, step, progress: {done, total}}` | Update progress stepper |
| `TRIP_READY` | 2 | `{tripId, summary}` | Render full analysis + LLaMA narrative |
| `TRIP_ERROR` | 1 or 2 | `{tripId, step, error}` | Display inline error, allow retry |

### API Surface

| Method | URI | Description |
|--------|-----|-------------|
| `POST` | `/trips` | Import route, run Phase 1, return `tripId` |
| `GET` | `/trips/{id}` | Fetch persisted trip (stages, status) |
| `POST` | `/trips/{id}/analyze` | Trigger Phase 2; returns `202 Accepted` immediately |

The `POST /trips/{id}/analyze` endpoint is idempotent: if Phase 2 is already running or completed for the given `tripId`, it returns `409 Conflict` with a `Retry-After` header or a status field indicating the current phase.

### Frontend Stepper

The frontend introduces a two-step flow:

1. **Step 1 — Preview:** Route is displayed immediately after `TRIP_PREVIEW_READY`. A "Launch full analysis" button is shown.
2. **Step 2 — Analysis:** After the user clicks the button, `POST /trips/{id}/analyze` is called. A progress stepper tracks each `computation_step_completed` event. The full report is revealed on `TRIP_READY`.

---

## Consequences

### Positive

- **Sub-2s route preview** — The map and elevation profile are visible before any enrichment begins. Users can immediately identify wrong tracks and abort before expensive API calls.
- **User agency** — The "Launch full analysis" button is a deliberate checkpoint. Users on mobile or slow connections can choose to defer analysis.
- **Deterministic LLaMA trigger** — The Redis-backed Symfony Lock on `trip.{tripId}.llama` ensures LLaMA inference runs exactly once per analysis, regardless of handler concurrency or retry behaviour.
- **Worker isolation** — Phase 1 workers (fast, CPU-bound) and Phase 2 workers (slow, I/O-bound) can be scaled independently via Symfony Messenger worker configuration.
- **Structured event taxonomy** — `computation_step_completed` + `TRIP_READY` give the frontend precise progress data without polling.

### Negative

- **New API endpoint** — `POST /trips/{id}/analyze` must be added to the OpenAPI spec and the TypeScript client regenerated (`make typegen`).
- **Two-step UX** — The user must click an extra button to trigger the full analysis. Acceptable as an intentional design choice; can be made automatic via a feature flag if UX testing shows friction.
- **Additional cache and lock entries** — Each active trip during Phase 2 consumes a single status map at `trip.{tripId}.computation_status` (TTL 1800s) plus, briefly, one Symfony Lock key `trip.{tripId}.llama` (TTL 3600s). Both expire automatically; no manual cleanup required.
- **`ComputationTracker` becomes a coordination point** — All Phase 2 handlers must call `markDone()` with the matching `ComputationName`. The real risk is a `ComputationName` passed to `initializeComputations()` whose handler is never dispatched (e.g. conditionally skipped): it stays `pending` indefinitely and blocks `isAllComplete()` forever. Conversely, a handler not listed in the initial expected set self-registers its status key via `markRunning()`, which also blocks the gate until it completes — so a missing-from-init-but-dispatched handler does *not* cause a premature LLaMA dispatch, it just delays it. Both invariants must be enforced by a test that cross-checks the dispatched handlers against `initializeComputations()`.
- **Second concurrency primitive** — The Symfony Lock introduces a second coordination mechanism alongside the PSR-6 cache. It is intentionally confined to the single `RunLlamaInferenceHandler` dispatch point; any future "fan-in → trigger once" case should reuse the same primitive rather than re-opening this question.

### Neutral

- Phase 1 is unaffected by this change: `POST /trips` and the pacing engine continue to work as before.
- The existing `ComputationTracker` public API and cache-key schema are reused as-is; only the `ComputationName` enum gains the new Phase 2 entries.
- Handlers that are not part of the enrichment phase (e.g., `GenerateStagesHandler`) are not registered in the expected set and do not call `markDone()`.

---

## Sources

- [ADR-001: Global Architecture and Separation of Concerns](adr-001-global-architecture-and-separation-of-concerns.md)
- [ADR-005: Orchestration, Optimization, and Caching of External APIs](adr-005-orchestration-optimization-and-caching-of-external-apis.md)
- [ADR-022: Persistent Storage Strategy](adr-022-persistent-storage-strategy.md)
- [Symfony Messenger — Async Transport](https://symfony.com/doc/current/messenger.html)
- [Symfony Cache — PSR-6 Contracts](https://symfony.com/doc/current/components/cache.html)
- [Symfony Lock — Redis Store](https://symfony.com/doc/current/components/lock.html#stores)
- [Mercure Protocol](https://mercure.rocks/spec)

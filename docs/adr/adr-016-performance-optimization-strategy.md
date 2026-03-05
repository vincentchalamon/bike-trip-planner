# ADR-016: Performance Optimization Strategy for Async Computation Pipeline

**Status:** Accepted

**Date:** 2026-03-03

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner — Local-first bikepacking trip generator

---

## Context and Problem Statement

The trip computation pipeline takes **~7-15 seconds** end-to-end between URL submission and `trip_complete` SSE event, tested with a typical 187km Komoot tour (<https://www.komoot.com/fr-fr/tour/2795080048>). While the architecture already employs async message processing (5 Redis workers) and progressive SSE rendering, several structural bottlenecks limit perceived performance:

1. **Sequential dependency chain:** `ROUTE → STAGES → STAGE_GPX → [6 leaf handlers]` — the first 3 steps add 650ms-3.3s before any enrichment computation begins.
2. **Three separate Overpass HTTP calls:** POIs, accommodations, and bike shops each independently query `overpass-api.de`, subject to external latency (1-5s per call) and rate-limiting.
3. **No route fetch caching:** Re-submitting the same Komoot URL always triggers a full HTTP fetch + HTML parsing.
4. **Sequential Komoot collection fetching:** N tours fetched in a `foreach` loop (~200ms each).
5. **Eager GPX generation:** GPX files are generated for all stages during computation, but are rarely downloaded immediately.

```text
Current pipeline (critical path ~7s):

ROUTE (150-500ms) → STAGES (200-800ms) → STAGE_GPX (300ms-2s) → 6 leaves in parallel
                                                                  ├─ ScanAccommodations (2-10s)
                                                                  ├─ ScanPois (1-5s)
                                                                  ├─ CheckBikeShops (1-3s)
                                                                  ├─ FetchWeather (500ms-2s)
                                                                  ├─ AnalyzeTerrain (50-500ms)
                                                                  └─ CheckCalendar (100-500ms)
```

### Architectural Requirements

| Requirement | Description |
|---|---|
| Backward Compatibility | SSE event contract with the frontend must remain unchanged (except for removed events). |
| Progressive Rendering | Users must continue to see partial results as they arrive, not wait for full completion. |
| Cache Coherence | Cached data must not produce stale or inconsistent results within a single trip computation. |
| Testability | All changes must pass existing QA pipeline (`make qa`) and E2E tests (`make test-e2e`). |

---

## Decision Drivers

* **User perception** — The delay between URL submission and first meaningful data (accommodations, POIs) directly impacts perceived quality.
* **External API dependency** — Overpass API is the dominant bottleneck; its latency is unpredictable (1-5s) and rate-limited.
* **Resource efficiency** — Workers should not perform computation whose results are never consumed (e.g., GPX files).
* **Incremental adoptability** — Optimizations should be deployable independently without requiring a big-bang migration.

---

## Considered Options

### Option A: Flatten the Computation DAG

**Principle:** Move the 6 leaf computation dispatches from `GenerateStageGpxHandler` to `GenerateStagesHandler`. GPX generation becomes a parallel leaf handler instead of a sequential prerequisite.

**Key insight:** None of the 6 leaf handlers (POIs, accommodations, terrain, weather, calendar, bike shops) need GPX content. They only need stage geometries and endpoints, which are available immediately after `GenerateStagesHandler`.

**Before:**

```text
ROUTE → STAGES → STAGE_GPX (300ms-2s) → [6 leaves]
Total sequential before leaves: 650ms-3.3s
```

**After:**

```text
ROUTE → STAGES → [7 leaves in parallel, including GPX]
Total sequential before leaves: 350ms-1.3s
```

**Expected improvement:** 300ms-2s removed from critical path.

**Complexity:** Low — move 6 `dispatch()` calls from one handler to another.

**Files:**

* `api/src/MessageHandler/GenerateStagesHandler.php` — add 6 dispatch calls after line 88
* `api/src/MessageHandler/GenerateStageGpxHandler.php` — remove dispatch calls (lines 66-71), keep GPX generation + SSE publish only

---

### Option B: Unified Overpass Query with Speculative Pre-Fetch

**Principle:** Merge the 3 separate Overpass queries (POIs, accommodations, bike shops) into a single unified query, and dispatch it immediately after route parsing — in parallel with stage computation.

**Why this works:**

* All 3 queries use Overpass `around` filters on polylines derived from the same route.
* Overpass QL supports different `around` radii within a single query (2km for POIs/bike shops, 5km for accommodations).
* The decimated route polyline is available after `FetchAndParseRouteHandler`, before stages are computed.
* The OSM cache (24h TTL, key = `xxh128` hash of query) guarantees a cache hit when leaf handlers later execute equivalent queries.

**New DAG:**

```text
ROUTE ──┬── STAGES ──── [7 leaves] (Overpass queries → instant cache hits)
        └── ScanAllOsm (3-5s, background, warms cache)
```

**Unified Overpass QL query structure:**

```text
[out:json][timeout:25];(
  nwr["amenity"~"^(restaurant|cafe|bar|...)$"](around:2000,polyline);
  nwr["shop"~"^(convenience|supermarket|...|bicycle)$"](around:2000,polyline);
  nwr["tourism"~"^(viewpoint|attraction)$"](around:2000,polyline);
  nwr["tourism"~"^(camp_site|hostel|hotel|...)$"](around:5000,polyline);
);out center 200;
```

**Trade-off:** The accommodation handler currently queries around stage endpoints only (not the full route). To share the same cache key as the pre-fetch, it must switch to using the full route polyline at 5km radius. This returns a superset of results, but the existing haversine distribution logic assigns each accommodation to its nearest stage endpoint, so correctness is preserved.

**Expected improvement:** 2-5s removed from critical path (Overpass latency moved off-path).

**Complexity:** Medium — new message/handler, query builder method, cache key alignment.

**Files:**

* `api/src/Scanner/OsmOverpassQueryBuilder.php` — new `buildUnifiedBatchQuery()` method
* New: `api/src/Message/ScanAllOsmData.php`, `api/src/MessageHandler/ScanAllOsmDataHandler.php`
* `api/src/MessageHandler/FetchAndParseRouteHandler.php` — dispatch `ScanAllOsmData` alongside `GenerateStages`
* `api/src/MessageHandler/ScanAccommodationsHandler.php` — switch to full route polyline query
* `api/src/Enum/ComputationName.php` — add `OSM_SCAN`
* `api/config/packages/messenger.php` — route new message

---

### Option C: On-Demand GPX Generation via API Platform Format

**Principle:** Remove `GenerateStageGpx` from the async pipeline entirely. Serve GPX on-demand via `GET /api/trips/{tripId}/stages/{index}.gpx` using API Platform's custom format support and a Symfony Serializer normalizer.

**Implementation:**

1. Register a `gpx` format (`application/gpx+xml`) on a `#[Get]` operation with `outputFormats` (per-operation, not global).
2. URI template: `/trips/{tripId}/stages/{index}{._format}` — API Platform resolves `.gpx` suffix automatically.
3. Create `GpxNormalizer` implementing `NormalizerInterface`:
   * `supportsNormalization()`: returns `true` for `Stage` instances with `gpx` format.
   * `normalize()`: delegates to the existing `GpxWriterInterface` to generate GPX XML.
   * `getSupportedTypes()`: declares `Stage::class` support for `gpx` format.
4. Symfony autowiring registers the normalizer automatically — no manual config needed.

**Trade-off:** The frontend can no longer display GPX download buttons immediately. Instead, download triggers an HTTP request. For the typical UX (download is a deliberate action), this is acceptable. The GPX computation is fast (~50ms per stage) so the response is near-instant.

**Expected improvement:** Removes one async computation step entirely. Reduces pipeline width (fewer messages, less worker contention).

**Complexity:** Medium — requires backend endpoint + frontend download flow change.

**Files:**

* Remove: `api/src/Message/GenerateStageGpx.php`, `api/src/MessageHandler/GenerateStageGpxHandler.php`
* `api/src/Enum/ComputationName.php` — remove `STAGE_GPX`
* `api/src/ApiResource/Stage.php` — add `#[Get]` operation with `outputFormats`
* New: `api/src/Serializer/GpxNormalizer.php`
* `pwa/src/store/trip-store.ts` — remove `gpxContent` and `updateStageGpx`
* `pwa/src/lib/mercure/types.ts` — remove `stage_gpx_ready` event
* `pwa/src/hooks/use-mercure.ts` — remove `stage_gpx_ready` dispatch
* GPX download component — fetch on click

---

### Option D: Route Fetch Caching (Komoot/Google)

**Principle:** Add a Redis cache layer on parsed route data (`RouteFetchResult`), keyed by normalized source URL, with 24h TTL.

**Use case:** When a user re-submits the same Komoot URL (new trip, or retry after adjusting parameters), the 150-500ms HTTP fetch + HTML parsing is skipped entirely. For Komoot collections, each individual tour is cached separately, enabling cross-collection reuse.

**Cache keys:**

* Tour: `route_fetch.komoot_tour.{tourId}`
* Collection: `route_fetch.komoot_collection.{collectionId}`
* Google MyMaps: `route_fetch.google_mymaps.{mapId}`

**Expected improvement:** 150ms-3s on repeat submissions. Especially impactful for collections (N tours cached individually).

**Complexity:** Low — wrapper cache around existing `fetch()` methods.

**Files:**

* `api/config/packages/cache.php` — add `cache.route_fetch` pool (Redis, 86400s TTL)
* `api/src/RouteFetcher/KomootTourRouteFetcher.php` — cache wrapper
* `api/src/RouteFetcher/KomootCollectionRouteFetcher.php` — cache per individual tour
* `api/src/RouteFetcher/GoogleMyMapsRouteFetcher.php` — cache wrapper

---

### Option E: Parallelize Komoot Collection Tour Fetches + Cache

**Principle:** Replace the sequential `foreach` loop in `KomootCollectionRouteFetcher::fetch()` (lines 50-65) with Symfony HttpClient multiplexing: fire all tour requests concurrently, then collect results. Combined with Option D's cache, each tour is cached individually and reused across collections.

**Pattern reference:** Already implemented in `ScanAccommodationsHandler::scrapeAsync()` (lines 240-248) — fire all non-blocking requests, then iterate results.

**Before (7 tours):** 7 x ~200ms = 1.4s sequential.
**After:** max(200ms) + overhead = ~400ms parallel. 0ms if all tours are cached.

**Cache integration:**

* Same `cache.route_fetch` pool as Option D
* Each tour cached by `route_fetch.komoot_tour.{tourId}` — a tour fetched via collection is reusable when fetched directly, and vice versa

**Expected improvement:** ~1s for collections on first submission, instant on repeat.

**Complexity:** Low — loop restructure following existing codebase pattern.

**Files:**

* `api/src/RouteFetcher/KomootCollectionRouteFetcher.php` — restructure to 2 loops (fire all, then collect) + per-tour cache

---

### Option F: Self-Hosted Overpass Instance (Nord-Pas-de-Calais)

**Principle:** Deploy a local Overpass API instance in Docker with Nord-Pas-de-Calais OSM data only, eliminating external network latency and rate-limiting concerns.

**Impact:** Overpass queries drop from 1-5s (external) to 50-200ms (local).

**Regional data (Geofabrik):**

| Metric | Nord-Pas-de-Calais | France (ref.) | Europe (ref.) |
|---|---|---|---|
| PBF download | **223 MB (~7s at 344Mbps)** | ~5 GB | ~32 GB |
| Overpass DB after import | **~1.3 GB** | ~20-35 GB | ~200+ GB |
| Initial import duration | **~30min-1h30** | ~6-12h | ~24-48h |
| Recommended disk space | **~5 GB** | ~70 GB | ~300 GB |
| Recommended RAM | **4 GB** | 16 GB | 32 GB |

**Docker configuration (`wiktorn/overpass-api`):**

```yaml
overpass:
  image: wiktorn/overpass-api
  environment:
    OVERPASS_MODE: init
    OVERPASS_PLANET_URL: http://download.geofabrik.de/europe/france/nord-pas-de-calais-latest.osm.pbf
    OVERPASS_DIFF_URL: http://download.openstreetmap.fr/replication/europe/france/nord-pas-de-calais/minute/
    OVERPASS_META: "yes"
    OVERPASS_RULES_LOAD: 4
  volumes:
    - overpass-data:/db
```

**Trade-off:** Limits the application to routes within Nord-Pas-de-Calais. Routes outside the region will return empty Overpass results. Extensible to larger regions (France, Europe) at the cost of proportionally larger disk/RAM/import time. Requires periodic data updates (OSM diffs).

**Complexity:** Medium — lightweight data, fast import, but adds infrastructure management.

**Files:**

* `compose.yaml` — add `overpass` service
* `api/config/packages/framework.php` — change `overpass.client` base_uri to `http://overpass:80`
* New: `.docker/overpass/` — configuration and data update scripts

---

### Options Considered but Not Retained

**Progressive per-stage computation:** Dispatch leaf computations per-stage as each stage is individually computed (instead of waiting for all stages). Rejected because stage computation is tightly coupled — pacing depends on total distance and fatigue decay across all stages. Splitting would require a fundamental redesign of `PacingEngineRegistry` with no guarantee of correctness.

**WebSocket replacement for SSE:** Replace Mercure SSE with WebSocket for bidirectional streaming. Rejected because the communication is unidirectional (server → client only), Mercure is already configured and working, and WebSocket adds complexity without performance benefit.

**Frontend predictive rendering:** Show estimated data (historical weather, typical accommodation prices) while real data loads. Rejected because the complexity of maintaining dual data paths outweighs the marginal UX improvement, and the progressive SSE rendering already provides good perceived performance.

---

## Decision Outcome

**Chosen: Options A + B + C + D + E + F (all options). Option F foundation implemented.**

### Implementation Order

| Priority | Option | Complexity | Expected Gain |
|---|---|---|---|
| 1 | **A** — Flatten DAG | Low | -300ms to -2s |
| 2 | **E** — Parallel collection fetches + cache | Low | -1s (collections) |
| 3 | **B** — Unified Overpass + pre-fetch | Medium | -2s to -5s |
| 4 | **D** — Route fetch caching | Low | -150ms to -3s (repeat) |
| 5 | **C** — On-demand GPX | Medium | Worker efficiency |
| 6 | **F** — Local Overpass (foundation implemented) | Medium | -1s to -4s |

### Option F: Foundation Implemented

Option F (self-hosted Overpass) foundation is now implemented:

* **Docker infrastructure:** `osm-download` + `overpass` services with shared PBF volume (Nord-Pas-de-Calais).
* **OsmScanner fallback:** Local-first query strategy with automatic fallback to public `overpass-api.de` when local is unavailable or returns empty results.
* **Split HTTP clients:** `overpass.local.client` (local, 5s timeout) + `overpass.public.client` (public, 15s timeout).

Routes within the imported region benefit from ~50-200ms local Overpass latency. Routes outside the region transparently fall back to the public API. See ADR-020 for the dynamic region provisioning system.

### Combined Impact

```text
Target pipeline (critical path ~2.5s):

ROUTE (350ms) ──┬── STAGES (400ms) ──── [7 leaves] (Overpass = cache hit)
                └── ScanAllOsm (3-5s, background, warms cache)

Dominant leaf: ScanAccommodations scraping (~1-3s after cache hit)
```

| Scenario | Typical E2E | Reduction |
|---|---|---|
| Current baseline | ~7s | — |
| + Option A (flatten DAG) | ~5.5s | -21% |
| + Option B (unified Overpass pre-fetch) | ~3s | -57% |
| + Option C (on-demand GPX) | ~3s | -worker waste |
| + Option D+E (route caching + parallel collections) | ~2.5s | -64% |
| + Option F (local Overpass, deferred) | ~1.5s | -79% |

---

## Verification

* Run `make qa` after each option implementation.
* Run `make test-php` for unit tests.
* Run `make test-e2e` for full SSE flow validation.
* Manual test with <https://www.komoot.com/fr-fr/tour/2795080048> — measure time between submission and `trip_complete` SSE event.

---

## Consequences

### Positive

* **64% latency reduction** (7s → 2.5s typical) without infrastructure investment.
* **Cache sharing across users** — identical Komoot tours/routes produce Redis cache hits for Overpass queries and route fetches.
* **Reduced Overpass API load** — single unified query instead of 3 separate ones, with speculative pre-fetch ensuring near-zero external calls during the critical path.
* **Worker efficiency** — removing eager GPX generation frees a worker slot for meaningful computation.
* **Incremental deployment** — each option is independently deployable and testable.

### Negative

* **Option B increases Overpass result volume:** Using the full route polyline at 5km radius for accommodations (instead of endpoints only) returns more candidates. Mitigated by existing deduplication + `array_slice` limit.
* **Option C changes GPX UX:** Download buttons no longer show preloaded state. Mitigated by GPX generation being fast (~50ms) so the on-demand response is near-instant.
* **Option B adds a new message handler and computation step:** Increases the number of tracked computations from 10 to 11. Mitigated by the handler being non-critical (failure doesn't block leaf computations, they just query Overpass directly without cache hit).

### Neutral

* The existing `OsmScanner` cache (24h TTL, keyed by query hash) is the linchpin of Option B. Its correctness has been validated in production since ADR-005.
* Option F foundation is implemented. See ADR-020 for dynamic region provisioning.

---

## Sources

* [ADR-005: Orchestration, Optimization, and Caching of External APIs](./adr-005-orchestration-optimization-and-caching-of-external-apis.md)
* [Overpass QL `around` filter documentation](https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL#around)
* [Geofabrik Download Server — Nord-Pas-de-Calais](https://download.geofabrik.de/europe/france/nord-pas-de-calais.html)
* [wiktorn/overpass-api Docker image](https://hub.docker.com/r/wiktorn/overpass-api)
* [API Platform: Custom Formats](https://api-platform.com/docs/core/content-negotiation/)
* [Symfony Serializer: Custom Normalizers](https://symfony.com/doc/current/serializer/custom_normalizer.html)
* [Symfony HttpClient: Multiplexing](https://symfony.com/doc/current/http_client.html#concurrent-requests)

# ADR-057: Progressive Trip Loading — Summary / Stage-Detail / Route Split

- **Status:** Accepted
- **Date:** 2026-08-19
- **Depends on:** ADR-055 (Mobile State Architecture — Thin Store), ADR-007 (Frontend Local State Management), ADR-040 (Local-First Reference Data — PostGIS), ADR-053 (Mobile Strategy — Native App)

## Context and Problem Statement

A trip is loaded through a single `GET /trips/{id}/detail` (`TripDetailProvider`)
that serializes **every stage in full** in one payload: geometry, weather, alerts,
events, accommodations, and the raw POI set from the local-first corridor read
(ADR-040). Measured on a real 2-stage trip, that payload is **1.18 MB dominated
by 5 457 POI objects** (one stage alone ships 3 901 POIs / 831 KB); geometry is
only ~28 KB. On the mobile Hermes engine the `JSON.parse` of that object graph
blocks the roadbook for ~20 s before any render (#1099), and the non-virtualized
`PoiBlock` then mounts thousands of native views.

Two structural problems compound:

1. **POIs are shipped in bulk.** The roadbook and stage-detail screens never need
   thousands of anonymous POIs; the product only wants a handful of *resupply
   suggestions* per stage. The corridor read is an internal computation input, not
   a client payload.
2. **The single-payload model does not scale with trip duration.** Even after the
   POIs are cut, geometry stays bundled and grows linearly: a 3-week trip is ~21
   stages × ~300 decimated points ≈ **6 300 geometry objects** — the same
   object-count order as today's POI bloat, so the parse ceiling simply moves from
   POIs to geometry. A weekend trip is fine; a multi-week trip is not.

The platforms consume the trip differently: **mobile shows one stage detail at a
time** (naturally lazy), while **web renders the whole detailed timeline at once**.
Any split must serve both without forcing a web UI redesign, and must preserve the
shared thin-store + per-stage SSE reconciliation model (ADR-055).

## Decision

Split the trip read model into **three resources by concern**, and **hydrate the
store progressively** instead of in one blocking payload.

### 1. Resupply replaces raw POIs (both platforms)

The corridor POI set stops being a client payload. The backend computes a small,
categorized **`resupply`** suggestion per stage from the existing lunch estimation
(`riderTimeEstimator` + `LUNCH_NUDGE_DISTANCE_KM`, already in `ScanPoisHandler`)
and route-distance positioning:

```text
resupply: {
  foodAtLunch:     Poi[≤2],   // best food shops near the estimated lunch stop
  waterMorning:    Poi|null,  // one water point between start and lunch
  waterAfternoon:  Poi|null,  // one water point between lunch and arrival
  foodAtArrival:   Poi[≤2],   // best food shops at the arrival
}
```

At most **6 POIs/stage** instead of thousands. These are explicitly *suggestions*
(a short help note says so on both UIs); there is no "widen radius" for resupply —
the user who wants more searches an external map. The raw `pois` field is dropped
from every read model.

### 2. Three read resources

- **`GET /trips/{id}` — roadbook summary** (always, initial load). Per-stage
  summary only: `dayNumber`, `startLabel`/`endLabel`, `distance`, `elevation`,
  `elevationLoss`, `isRestDay`, a weather summary, and an alert **count** — no
  geometry, no collections. ~200 B/stage, so it stays instant for a months-long
  trip.
- **`GET /trips/{tripId}/stages/{index}/detail` — stage detail** (on demand). The
  full single stage: that stage's geometry, `resupply`, accommodations, events,
  classified alerts, weather. The `StageResponse` DTO already models this shape
  (currently `#[NotExposed]`); it is promoted to an exposed operation with a
  provider on a `/detail` sub-route (the bare `/stages/{index}` IRI is shadowed by
  the `NotExposed` `StageResponse`, same reason `/export` uses a sub-route). O(1)
  per stage opened.
- **`GET /trips/{id}/route` — route geometry** (on demand). All stages, **geometry
  only**, for the map. The one unavoidable all-stages payload, kept geometry-only
  and fetched **only when the map is actually viewed**.

### 3. Progressive hydration

- **Mobile** loads the summary on open, fetches a stage's detail on tap, and
  fetches the route when the Map tab opens. It parses one stage's object graph at
  a time — the #1099 ceiling disappears.
- **Web** loads the summary, renders the timeline with a **skeleton per row**,
  then fetches every stage detail **in parallel with bounded concurrency**
  (HTTP/2-multiplexed over FrankenPHP/Caddy), each row hydrating as its detail
  arrives. **No UI change** — the same timeline, filled progressively. The map
  uses the route resource. Each `/stages/{index}/detail` is independently cacheable
  (Redis server-side, browser/CDN client-side) and resumable, unlike one 630 KB
  blob.
- **SSE reconciliation is unchanged** (ADR-055): Mercure events already target
  individual stages by index, so they reconcile whatever slices are currently
  loaded. The shared `@btp/core` reducers keep both stores in step.

### 4. Vulcain is a layered optimization, not a foundation

[Vulcain](https://vulcain.rocks) is designed for exactly this client-driven fetch
shape. But its headline mechanism, **HTTP/2 Server Push, is deprecated in
browsers** (Chrome removed it in 2022), so the "push linked resources" win no
longer lands on the web. The parts that remain current — **`Fields` sparse
fieldsets** (one `/stages/{index}` resource, web asking for all fields, mobile for
the summary subset, avoiding two contract shapes) and `Link` / `103 Early Hints`
preloading — were worth a **short spike** before layering them on. The baseline
that ships regardless is the client-driven **parallel progressive fetch** above;
Vulcain layers on top only if the spike shows a clear win. We do **not** build the
loading model on Server Push.

#### Spike outcome (#1106): NO-GO for now — defer, do not adopt

The spike verdict is **do not add Vulcain on top of the split**. Measured against
the current stack, none of the three mechanisms clears the bar:

- **Server Push** — dead in browsers, excluded up front.
- **`Fields` sparse fieldsets** — would collapse the two stage shapes (the summary
  stage inlined in `TripDetail.stages[]` vs the full `StageResponse` served at
  `/stages/{index}/detail`) into a single resource. But that is a **contract**
  simplification, **not a payload win**: the payload bottleneck this ADR targets —
  the 1.18 MB corridor-POI dump — is already eliminated by the `#1099` resupply
  curation and the read-model split, both served over HTTP/2-multiplexed parallel
  fetch. The residual drift between the two shapes is narrow (the summary carries
  `startLabel`/`endLabel`/`onCycleNetwork`; the full stage carries
  `geometry`/`events`/a `trip` back-reference) and is cheaply maintained in
  `@btp/core` without new infrastructure.
- **`103 Early Hints`** — its benefit over the already-shipped HTTP/2-multiplexed
  parallel fetch is marginal, and it depends on the production edge (the
  Coolify-managed Traefik / the FrankenPHP–Caddy service) actually emitting or
  forwarding `103`, which is unconfigured and unverified.

Enabling any of this is not free: the `vulcain` Caddy directive must be added (the
module ships compiled into the stock `dunglas/frankenphp` image but is **not**
activated — it is absent from `.docker/php/Caddyfile`), API Platform's single
`jsonld` format wiring extended, and both `openapi-fetch` clients taught to send
the `Fields` header — new moving parts for a marginal, non-performance gain.

**Revisit trigger:** adopt `Fields` only if the two-shape drift becomes a real
maintenance burden (schema divergence causing client bugs), or `103 Early Hints`
only if a measured client-side waterfall shows it would materially cut
time-to-first-stage. Absent either signal, the progressive split alone stands.

## Consequences

- Trip open no longer parses a monolithic payload; the roadbook is bounded by
  stage *count*, not stage *content*, and stays fast at any trip length.
- Two→three read resources; the store gains per-stage loading states (skeletons on
  web, on-tap load on mobile). The write/mutation paths and the existing
  accommodation expand-scan (`AccommodationScanProcessor`) are unaffected.
- Per-stage HTTP caching replaces one uncacheable blob — better CDN/Redis reuse,
  parallelism, and resumability.
- The OpenAPI contract changes (drop `pois`, add `resupply`, expose the stage and
  route resources); `@btp/core` types regenerate and both platforms adapt in the
  same pass — intentional compile-time drift (per the type-contract principle).
- Since the app is pre-release, `/trips/{id}/detail` is **replaced**, not kept in
  parallel; no deprecation window.
- The corridor POI read (ADR-040) stays an internal computation input feeding
  resupply selection and the resupply/lunch nudges — only its *exposure* changes.

## Alternatives Considered

- **Single capped `/detail` (Phase 1 only).** Cap POIs to resupply but keep
  geometry bundled. Fixes #1099 for weekend/1-week trips but leaves the geometry
  ceiling for multi-week trips. Rejected as the end state; it is effectively the
  first increment of this decision.
- **Vulcain Server Push as the loading mechanism.** Rejected: browser Server Push
  is deprecated, so it cannot be the foundation.
- **Single streamed response (NDJSON / chunked).** Flush summary first, then each
  stage. Rejected: complicates typed store hydration and openapi-fetch consumption
  for little gain over cacheable per-stage resources.
- **GraphQL field selection.** Rejected: outside the current REST + openapi-fetch
  contract model; disproportionate for one read path.

## References

- [ADR-055](adr-055-mobile-state-architecture.md) — Thin store + shared reconciliation
- [ADR-040](adr-040-local-first-reference-data-postgis.md) — Local-first reference data (POI/water corridor reads)
- [ADR-007](adr-007-frontend-local-state-management-and-reactivity.md) — Frontend local state
- `api/src/State/TripDetailProvider.php`, `api/src/ApiResource/TripDetail.php` — current single payload
- `api/src/ApiResource/StageResponse.php` — per-stage DTO to expose
- `api/src/MessageHandler/ScanPoisHandler.php` — lunch estimation reused for resupply
- Issue #1099 — trip-open latency root cause (POI payload)

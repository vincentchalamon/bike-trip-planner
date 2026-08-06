# ADR-050: Terrain Attribution to the Ridden Route

- **Status:** Accepted — keep the corridor. Map-matching is **not adopted**; this ADR records why and what would reopen it. No application code follows from this decision.
- **Date:** 2026-08-06
- **Depends on:** ADR-004 (GPX parsing and decimation), ADR-040 (Local-first reference data — single PostGIS source), ADR-049 (Zone opening and containment invariant), ADR-017 (Valhalla routing engine)
- **Reformulates:** nothing. ADR-017's status is left untouched **on purpose** — the "Fichiers impactés" note on issue [#889](https://github.com/vincentchalamon/bike-trip-planner/issues/889) makes that edit conditional on retaining map-matching, which this ADR does not.
- **Numbering note:** ADR-048 is reserved by issue [#936](https://github.com/vincentchalamon/bike-trip-planner/issues/936) (Sprint 51, in-ride assistance without AI), ADR-049 by the zone-opening work ([ADR-049](adr-049-zone-opening-and-import-time-completeness.md)). The next free number is 050 — the one this ADR takes.

## Context and Problem Statement

The surface and traffic alerts read highway ways from the Tier-1 PostGIS index along the route and total the rough / dangerous metres per stage (`SurfaceAlertAnalyzer`, `TrafficDangerAnalyzer`, priority 20, both fed by `AnalyzeTerrainHandler`). The question this ADR settles is whether that attribution — deducing which edges the rider is on from geometric proximity — should be replaced by **map-matching**: snapping the trace onto the routing graph and reading the attributes of the edges actually traversed, via Valhalla's `/trace_attributes`.

**What Sprint 44 already delivered ([#859](https://github.com/vincentchalamon/bike-trip-planner/issues/859), PR [#894](https://github.com/vincentchalamon/bike-trip-planner/pull/894)).** The corridor read had two faults, both fixed:

1. **Length was the whole way.** `WaysRepository` measured `ST_Length(w.geom::geography)` — the full way in the table. A 40 km departemental grazing the corridor for 200 m contributed 40 000 m to the totals. It now clips each way to the corridor (`ST_Intersection` against a metric `ST_Buffer(geom::geography, :radius)`, materialised) and measures only the followed portion; the centroid is taken on that same clipped portion so `GeometryBasedDistributor` attributes it to the right day.
2. **The corridor was too wide.** It was 100 m, so a greenway running beside a trunk road put both inside it and the trunk road was counted as "ridden". The radius is now **20 m** (`AnalyzeTerrainHandler::WAYS_CORRIDOR_RADIUS_METERS`), sized on the route's own positional error: Douglas-Peucker decimation at 20 m (ADR-004) dominates GPS noise, so a way actually ridden stays inside the corridor while a road merely alongside falls out. The index-usable bbox pre-filter of ADR-043 is preserved (no per-row `geom::geography` cast on the whole `osm.ways` table).

So the measured baseline is: **parallel-road false positives are resolved** (a way separated from the trace by more than 20 m is no longer returned), and **magnitudes are correct** (a 200 m graze counts as ~200 m, not 40 km). The analyzers' thresholds — 500 m of rough surface, 500 m minimum dangerous segment — now sit above corridor noise rather than being tripped by it.

**What the corridor still cannot resolve.** A trace running within ~20 m of a road it does not ride: a `contre-allée`, a cycle path glued to a carriageway closer than the radius, the few metres around a junction where two ways interleave. Map-matching would resolve exactly those, because it decides membership by graph topology, not by distance. The issue asks this be quantified before concluding — see below.

**Two properties of the delivered path matter for what follows.** The terrain analysis reads **PostGIS only** (`osm.ways`); it makes no routing call. And the reference index is append-only, local and deterministic (ADR-040): the same trace yields the same ways with no request-time third-party dependency.

## Decision

**Keep the corridor. Do not adopt Valhalla map-matching for terrain attribution now.** The decision rests on three measured axes and one coherence argument, not on preference.

### 1. The accuracy gain is marginal against the corridor as delivered

The corridor's remaining blind spot is the sub-20 m adjacency case, and only that case — everything wider was closed by #859. Nothing in the Sprint 44 measurements or the recette established a **material rate** of such cases on real traces: the false positive that motivated the work (greenway alongside a nationale) is a >20 m separation and is already gone. Adopting map-matching to recover the sub-20 m residue is paying a new runtime coupling (axis 2) for an unquantified gain. The corridor cannot go narrower to chase it either: below the 20 m decimation floor of ADR-004 it would start dropping ways that *are* ridden (false negatives), which is why 20 m was chosen and not 10.

### 2. Availability: map-matching would make surface and traffic alerts share routing's fate

Today a Valhalla problem never erases a terrain alert, because the analysis path does not touch Valhalla — it reads `osm.ways`. Map-matching inverts that: every surface and traffic alert would depend on a successful `/trace_attributes` call. A Valhalla outage, a trace that fails to match, or a stage whose geometry leaves the graph would then make **all** surface and traffic alerts for that stage disappear, silently, and read as "this stage is fine".

The ADR-049 §6 containment invariant (the routing perimeter encompasses the reference perimeter, checked at zone-opening and asserted in `/api/health`) guarantees the graph *covers* any opened zone, but it guarantees nothing about the graph being *up*. Coverage is not availability. Valhalla is already a required dependency of the `/api/health` readiness probe (`HealthController`, `routing.client` at `framework.php:82-89`), but that is a coarse instance-level gate; the property worth protecting is finer — the alert engine's core signal currently rests on the most available substrate it has (the local index), and ADR-040's own degradation model keeps index-derived alerts working out of zone while only routing edit-actions (split/merge/reroute) are disabled. Map-matching would collapse that distinction. This is the strongest argument against, and it is decisive on its own.

### 3. Feasibility of `/trace_attributes` on a decimated trace — measured against the documented limits

Valhalla exposes the right data. The per-edge filter keys of `/trace_attributes` include `edge.surface`, `edge.road_class`, `edge.cycle_lane`, `edge.bicycle_network`, `edge.speed_limit`, `edge.unpaved` and `edge.use` — a clean cover of what the two analyzers consume today (surface value, highway class, cycleway presence, maxspeed). The point budget is not a constraint: the trace is decimated to ~1 500 points (ADR-004), and the default trace service limit is `service_limits.trace.max_shape = 16000` — the whole trip fits roughly ten times over.

**The binding limit is distance, not points.** `service_limits.trace.max_distance = 200000` (200 km) per request, while bikepacking routes run 100–1 500 km (ADR-004). A whole trip cannot go in a single call; the natural unit is **one `/trace_attributes` request per stage** (a day is typically well under 200 km), i.e. N requests per analysis for an N-day trip (~5–15). The default `shape_match` is `walk_or_snap` (edge-walk first, falling back to the more expensive `map_snap`); `map_snap` is documented as the costly path.

**End-to-end latency was not measured here, and this is stated plainly rather than fabricated:** this worktree has no routing graph (`make start-dev` runs without routing, `/api/health` reports Valhalla degraded), so no live `/trace_attributes` timing exists to quote. The feasibility verdict from the documented constraints is: **technically feasible** on point budget and attribute coverage, at the cost of per-stage fan-out and a `map_snap` fallback whose latency against a real French graph is unknown. That unknown is itself a reason not to adopt now — the async budget (ADR-043) cannot be signed off against a number nobody has.

### 4. Coherence with ADR-004 and ADR-040

The issue asks the ADR-004 / ADR-040 reasoning be made explicitly, not assumed. ADR-004 rejected PostGIS for GPX parsing because that is a transient, per-request workload where spatial SQL bought nothing over streaming `XMLReader`. ADR-040 adopted PostGIS for a persistent, geo-bounded reference index queried with spatial predicates. The two are not in tension because they treat different workloads — and the same test applies here. Terrain attribution is a read against that same persistent index (`ST_DWithin` / `ST_Intersection` along the corridor); adding a Valhalla round-trip to the async analysis path introduces the exact class of request-time routing coupling that both prior ADRs kept off the request path. Keeping the corridor keeps terrain attribution on the ADR-040 substrate, consistently.

### 5. What would reopen this decision

Map-matching becomes the right call when, and only when, all three hold:

- a **measured** rate of sub-20 m adjacency errors (parallel path glued closer than the radius, junction interleaving) on real traces, above a material threshold — the gain must be quantified, not assumed;
- a **measured** `/trace_attributes` latency on a live French graph within the async analysis budget (ADR-043), including the `map_snap` fallback and the per-stage fan-out;
- an availability answer for axis 2 — either a PostGIS **fallback** so a Valhalla outage degrades terrain alerts to the corridor result instead of erasing them, or Valhalla promoted to a hard, highly-available dependency the alert engine may lean on.

Absent those, the corridor is the better trade.

## Consequences

### Positive

- Surface and traffic alerts stay on the local, deterministic, append-only PostGIS substrate: a Valhalla outage, a match failure or an out-of-graph stage cannot silently erase them.
- No new per-stage fan-out of routing requests in the async path, no `map_snap` latency to budget for, no coupling of the alert engine to the routing graph's availability.
- The decision is coherent with ADR-004 and ADR-040: terrain attribution remains a read against the reference index, off the request-time routing path.
- Nothing is built: the option already delivered in Sprint 44 is the option retained, so the cost of this ADR is a decision, not code.

### Negative

- The sub-20 m adjacency case remains unresolved: a trace running within the corridor of a road it does not ride (contre-allée, glued cycle path, junction interleave) can still be mis-attributed. Assumed, because it is unquantified and the corridor cannot narrow past the 20 m decimation floor without producing false negatives.
- Attribution stays geometric, so it can never distinguish two ways closer than the radius by which one is actually ridden — a limit map-matching would not have. Accepted as the price of the availability property in axis 2.

### Neutral

- The corridor radius (20 m) and the analyzer thresholds (500 m rough, 500 m minimum dangerous segment) are unchanged by this ADR; it decides the attribution *method*, not those constants.
- ADR-017's status is intentionally not amended: no map-matching is added, so §17.2's routing integration is unaffected. The `/trace_attributes` endpoint remains available on the existing Valhalla service for a future reversal under §5, at no extra infrastructure cost.
- The reversal criteria in §5 are recorded so the question can be reopened on evidence rather than re-litigated from scratch.

## Sources

- [ADR-004](adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md) — GPX parsing and 20 m Douglas-Peucker decimation (~25k → ~1.5k points), the positional floor the corridor radius is sized on; PostGIS rejected there for a transient workload.
- [ADR-040](adr-040-local-first-reference-data-postgis.md) — Local-first PostGIS reference index and corridor reads; the graceful-degradation model that keeps index-derived alerts working out of zone.
- [ADR-043](adr-043-synchronous-structural-computation-async-enrichments.md) — synchronous structural computation, asynchronous enrichments; the async budget any `/trace_attributes` latency would have to fit, and the index-usable bbox pre-filter reused by `WaysRepository`.
- [ADR-049](adr-049-zone-opening-and-import-time-completeness.md) — §6 containment invariant (routing perimeter encompasses reference perimeter): coverage, checked, not availability.
- [ADR-017](adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md) — the Valhalla service and `routing.client`; status unchanged by this decision.
- [Issue #859](https://github.com/vincentchalamon/bike-trip-planner/issues/859) / PR [#894](https://github.com/vincentchalamon/bike-trip-planner/pull/894) — the delivered corridor tightening (100 m → 20 m) and length clipping, the measured baseline this ADR compares against.
- [Valhalla Map Matching API — `trace_attributes`](https://valhalla.github.io/valhalla/api/map-matching/api-reference/) — per-edge filter keys (`edge.surface`, `edge.road_class`, `edge.cycle_lane`, `edge.speed_limit`, …) and `shape_match` modes (`edge_walk`, `map_snap`, `walk_or_snap`).
- [Valhalla default service limits](https://github.com/valhalla/valhalla/blob/master/scripts/valhalla_build_config) — `service_limits.trace.max_shape = 16000`, `max_distance = 200000` m, `max_search_radius = 100`, `max_gps_accuracy = 100`.

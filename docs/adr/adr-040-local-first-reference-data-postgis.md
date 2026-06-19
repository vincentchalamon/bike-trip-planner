# ADR-040: Local-First Reference Data — Single PostgreSQL/PostGIS Source

- **Status:** Accepted — the API local-read cut-over has landed (runtime Overpass removed); provisioner enrichment (DataTourisme) and coverage/monitoring follow
- **Date:** 2026-06-11
- **Depends on:** ADR-022 (Persistent storage), ADR-033 (OSM data refresh), ADR-036 (Manual OSM refresh), ADR-039 (Beta right-sizing)
- **Reformulates:** ADR-005 (external-API caching), ADR-013 (accommodation discovery), ADR-025 (removal of self-hosted Overpass), ADR-026 (multi-source integration)
- **Does not affect:** ADR-004 (GPX parsing/decimation) — see "Relationship to ADR-004" below

## Context and Problem Statement

The closed-beta acceptance test ([#649](https://github.com/vincentchalamon/bike-trip-planner/issues/649)) showed that the product pillars that depend on map data are not reliable: POI discovery (food, water, shops), accommodation recommendation, and the alert engine that reads them. The root cause is structural, not a set of isolated bugs: the API depends on **fragile third-party services at request time**.

- **Public Overpass (`overpass-api.de`)** has no retry/failover and is rate-limited. `OsmScanner` catches every failure and returns an **empty result** (`api/src/Scanner/OsmScanner.php`). That "empty-on-error" behaviour makes "the area genuinely has no data" indistinguishable from "the query failed", which directly produces:
  - the **`alert.lunch.nudge` false positive** (`ScanPoisHandler`): an Overpass hiccup yields zero POIs, so `hasResupplyPoi()` is false and the nudge fires on a stage that actually has plenty of food;
  - **missing accommodations / POIs**, because a transient failure silently drops a whole scan.
- **Water detection** is a proxy: it queries cemeteries (legally required to have a water tap in France) instead of real drinking-water points, so the data is both approximate and tied to the same fragile Overpass path.
- The accommodation path additionally stacks **runtime scraping** for prices, **DataTourisme** (rate-limited), and **Wikidata** (slow) — each a request-time third-party dependency that can degrade or block enrichment.

ADR-025 removed the self-hosted Overpass to save RAM and accepted the public API plus a 24 h Redis cache as mitigation. The recette shows that mitigation is insufficient for a product whose core value is "where can I eat, sleep and refill water on this route".

The application is **not yet launched**. That makes this the right moment to fix the foundation rather than patch symptoms.

## Decision

Adopt a **local-first architecture with a single PostgreSQL/PostGIS source of truth** for reference geodata. An asynchronous **provisioner aggregates** reference data into PostgreSQL/PostGIS; the **API reads only the local database**, autonomously. Third-party fragility is concentrated in the provisioner (best-effort) and never blocks a user request — at worst the data is dated, never absent-because-of-an-error.

### Three-tier data model

| Tier | Nature | Sources | Location | Runtime third-party dependency |
|------|--------|---------|----------|--------------------------------|
| **1. Reference** | Static, geo-bounded | OSM (POI, accommodations, water, cycle networks, admin boundaries), DataTourisme, Wikidata | **PostgreSQL/PostGIS** (provisioner = async aggregator) | None |
| **2. Local compute** | Computation | Valhalla (routing), Ollama (LLM) | Local services | None |
| **3. Live resilient** | Date/user input | Weather (open-meteo), route fetch (Komoot/Strava/RWGPS/GPX) | On-demand + cache | Yes, by nature |

Beta perimeter for Tier 1: **France + Belgium + Netherlands + Luxembourg**. Adding a zone later is "add a PBF + re-provision", with no application code change.

### Tier 1 — provisioner aggregator → PostGIS

The existing `provisioner/` (already a Geofabrik PBF downloader/merger for Valhalla) is extended to also populate PostGIS:

- **Download** Geofabrik PBF: `france` (already), plus `belgium`, `netherlands`, `luxembourg`.
- **Extract** with `osmium tags-filter` (multi-GB PBF → tens of MB of relevant features).
- **Import** with `osm2pgsql` (flex/Lua style) into spatial tables — `pois`, `accommodations`, `water_points`, `admin_boundaries`, `cycle_routes` — with useful columns in clear (`name`, `category`, `opening_hours`, `fee`/`charge`, `website`, `stars`, `capacity`, `wikidata`, …) plus the raw tags as `JSONB`. **GIST** indexes; centroids for ways/relations.
- **Enrich** (in the provisioner only): **Wikidata** (batched SPARQL over the bounded set of Q-IDs) and **DataTourisme** via its dump/feed (not the runtime API).
- **Accommodation pricing (no scraping):** `price = structured open data (DataTourisme priceSpecification, OSM charge/fee) ?? heuristic (type/region/stars)`. Live HTML price scraping has been removed (PR2b): all prices come from pre-loaded sources or the heuristic, set by each `AccommodationSource` at fetch time.
- **Atomic versioned swap:** import into a staging schema, then switch in a single transaction; the previous dataset is kept until the new one is complete. Idempotent, never a partial state.
- **Coverage polygon:** the provisioner materialises `osm.coverage` (union of the provisioned countries' `admin_boundaries`, admin_level = 2) inside the staging schema, so it ships atomically with the data; the API tests trip geometry against it via `ST_Covers`.
- **Monitoring:** the provisioner records `osm.metadata` / `tourism.metadata` (last refresh timestamp + per-table feature counts) on each run; `/api/health` surfaces them under a non-required `reference_data` dependency. Cron cadence (OSM weekly, DataTourisme daily, Wikidata monthly).

### API — local read only

- New `PoiRepository` / `AccommodationRepository` (Doctrine DBAL + PostGIS) behind the existing `OsmScanner` / `OsmOverpassQueryBuilder` seams.
- `ScanPoisHandler` / `ScanAccommodationsHandler` query **`ST_DWithin` along the decoded route corridor**, not only around the stage endpoint — this fixes the "radius too short / endpoint-only" gaps. Distribution stays via `GeometryBasedDistributor`.
- **Snapshot preserved:** POI/accommodations remain frozen in `Stage` (JSONB) at analysis/recompute time (trips stay shareable and stable), but **sourced from the local index** instead of Overpass. The index is the source; the `Stage` is the per-trip frozen result.
- The **in-ride assistant** is repointed onto the PostGIS repository (it was a 5-minute Overpass cache).
- **Runtime Overpass dependency removed.** No more "empty-on-error", so `alert.lunch.nudge` can no longer be a false positive. Water uses the **real** `water_points` (end of the cemetery proxy).
- **Out of zone:** `ST_Covers(coverage, trip)`. Outside the coverage polygon → a **non-blocking notice** in the preview **and** the edit actions that need Valhalla (split/merge/reroute) are **disabled** (no routing tiles out of zone) → display-only trip.

**Implementation status (2026-06-15):** the cut-over is complete. Every reader was migrated to PostGIS repositories across a slice-per-category series (POIs, accommodations, water points, bike shops, health services, railway and charging stations, cultural POIs, terrain ways, admin boundaries), and the runtime Overpass machinery — `OsmScanner`, `OsmOverpassQueryBuilder`, the `ScannerInterface` / `QueryBuilderInterface` seams, the `ScanAllOsmData` pre-warm umbrella with its `OSM_SCAN` computation, and the `overpass.client` scoped HTTP client — has been deleted. Nominatim geocoding (also OSM data, but a request-time place lookup, not a Tier-1 index read) keeps the `cache.osm` pool. The DataTourisme cut-over followed: the flux is imported into a `tourism` schema by the provisioner and read by the API (cultural POIs, accommodations, dated events) in place of the runtime REST API, with OSM/DataTourisme duplicates collapsed by proximity + name — so the app now has **no runtime third-party data dependency** (only weather and route fetch stay live by nature). The provisioner then gained the monitoring foundation: it materialises the `osm.coverage` polygon and records `osm.metadata` / `tourism.metadata` (refresh timestamp + per-table counts), both surfaced by `/api/health`. The out-of-zone consumer followed (the preview notice + Valhalla edit-action gating that read `osm.coverage`). The DataTourisme **food layer** (eateries + food shops, `tourism.food_pois`) was then imported and merged into the resupply scan (`ScanPoisHandler`) alongside the OSM `pois` through a `PoiSourceRegistry` that collapses the OSM/DataTourisme overlap by proximity + name — the same source-registry + `NearbyNameDeduplicator` pattern as cultural POIs and accommodations. The historical ADRs describing the runtime-Overpass design (ADR-017/020/025/033/036) are reconciled in a follow-up docs pass.

### Relationship to ADR-004

[ADR-004](adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md) rejected PostGIS for **GPX parsing and decimation** — a per-request, transient workload where spinning up spatial SQL added no value over streaming `XMLReader` + in-PHP Douglas-Peucker. **That decision stands:** GPX ingestion keeps its in-process pipeline. This ADR introduces PostGIS for a *different* concern — a persistent, geo-bounded **reference index** queried with spatial predicates (`ST_DWithin`, `ST_Covers`). The two are not in tension.

Concretely, the database image becomes `postgis/postgis:18-3.6-alpine` (same PG18 binaries and data layout as the former `postgres:18-alpine`, plus the extension). The extension is enabled by a Doctrine migration so it applies on existing volumes too. `spatial_ref_sys` is excluded from Doctrine's schema tool (`schema_filter`); the Tier-1 tables live in their own schema, managed by `osm2pgsql`, outside Doctrine's metadata.

## Consequences

### Positive

- **Deterministic discovery:** the same route always yields the same POIs/accommodations/water — no rate-limit or timeout variance, no HTTP latency at request time.
- **The `alert.lunch.nudge` false positive disappears:** "no data" now means the local index truly has none, not that a query failed.
- **Real drinking water** replaces the cemetery proxy.
- **Corridor queries** (`ST_DWithin` along the track) replace fixed "around the endpoint" radii, closing detection gaps.
- **Third-party fragility is isolated** in the best-effort provisioner; a Wikidata/DataTourisme/Overpass-mirror outage degrades the *next refresh*, never a user request.

### Negative

- **PostGIS is an engine change** across dev, prod, CI and the test DB (not just a migration). Foundry resets the test DB in `migrate` mode, so the CI PHPUnit database service must also run the PostGIS image.
- **Provisioning peak RAM:** `osm2pgsql` is the new memory consumer; its peak (bounded via `--slim` + a capped cache) must fit the provisioner's budget on the 24 GB beta VM (ADR-039). Measured when the import lands.
- **Test DB PostGIS is a prerequisite** for the API local-read cut-over (the deferred real-DB integration coverage, issue #56).
- **Stale drinking water is a safety risk**, not just "dated data", on an isolated stage → a sane refresh cadence and a neutral UI label are required.
- **Larger database disk footprint** for the reference index (bounded by `osmium tags-filter` pre-extraction).

### Neutral

- ADR-004 is unaffected (GPX stays in-process).
- ADR-005/013/025/026 are reformulated, not reversed: caching, accommodation discovery, the public-Overpass posture and multi-source integration are folded into the provisioner instead of the request path.
- The beta perimeter (FR + Benelux) is intentionally bounded; widening it is data, not code.

## Sources

- [ADR-004](adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md) — GPX parsing/decimation (PostGIS rejected there for a different, transient workload)
- [ADR-005](adr-005-orchestration-optimization-and-caching-of-external-apis.md) — External-API orchestration and caching (reformulated)
- [ADR-013](adr-013-accomodation-discovery-and-heuristic-pricing-strategy.md) — Accommodation discovery and heuristic pricing (reformulated)
- [ADR-022](adr-022-persistent-storage-strategy.md) — Persistent storage strategy (PostgreSQL + JSONB)
- [ADR-025](adr-025-removal-of-self-hosted-overpass.md) — Removal of self-hosted Overpass (runtime posture reformulated)
- [ADR-026](adr-026-multi-source-data-integration.md) — Multi-source data integration (moved into the provisioner)
- [ADR-033](adr-033-osm-data-refresh-strategy.md) — OSM data refresh strategy
- [ADR-036](adr-036-manual-osm-data-refresh.md) — Manual OSM data refresh
- [ADR-039](adr-039-beta-right-sizing-free-tier.md) — Beta right-sizing and RAM/CPU budget

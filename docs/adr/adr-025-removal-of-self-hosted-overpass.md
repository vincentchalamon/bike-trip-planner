# ADR-025: Removal of Self-Hosted Overpass

- **Status:** Accepted
- **Date:** 2026-04-09
- **Supersedes:** ADR-017 §17.1 (Overpass Docker service), §17.4 (Overpass fallback strategy), §17.5 (OSM data update strategy), ADR-020 (Dynamic Overpass Region Provisioning)

## Context and Problem Statement

ADR-017 introduced a self-hosted Overpass instance alongside Valhalla, sharing a single PBF download. While the marginal cost argument was sound at the time, operational experience revealed a disproportionate infrastructure burden:

| Resource | Cost |
|---|---|
| RAM (runtime) | ~2-3 GB |
| Disk (database) | ~3 GB |
| Import time | ~20-25 minutes (after provisioning) |
| Startup | 120s health check grace period |
| Maintenance | `fix-permissions.sh` workaround, `osmium` PBF preprocessing |
| Code complexity | Dual-client fallback logic, `LocalOverpassStatusChecker`, `OverpassStatusCheckerInterface` |

Meanwhile, the existing mitigation strategies make the public API (`overpass-api.de`) sufficient:

1. **Redis cache** — 24h TTL on `cache.osm` pool with xxh128 keys. Once a trip's OSM data is fetched, subsequent views and recalculations hit cache, not the API.
2. **Unified batch pre-fetch** — `ScanAllOsmDataHandler` fires 5 query types (POI, accommodation, bike shops, cemetery, ways) in a single concurrent batch immediately after route parsing. This front-loads all Overpass queries into one burst per trip.
3. **Query volume** — A typical trip generates 5-10 Overpass queries on first computation, then zero until cache expires. This is well within the public API's fair-use limits.

## Decision

Remove the self-hosted Overpass instance entirely. Use only the public Overpass API at `overpass-api.de`.

### What changes

- **Docker**: Remove `overpass` service and `overpass-data` volume from `compose.yaml` and `compose.prod.yaml`. Remove `fix-permissions.sh`.
- **Backend**: Simplify `OsmScanner` to single-client architecture. Remove `LocalOverpassStatusChecker` and `OverpassStatusCheckerInterface`. Rename `overpass.public.client` to `overpass.client`.
- **Tests**: Simplify `OsmScannerTest` — remove dual-client/fallback test scenarios, add genuine empty result caching test.

### What stays unchanged

- **Valhalla** — Routing engine is unaffected. It shares the PBF file but has zero coupling with Overpass.
- **Provisioner** — Continues to download and merge PBF regions for Valhalla. Contains no Overpass references.
- **Cache strategy** — Redis `cache.osm` pool with 24h TTL and xxh128 keys. Identical behavior.
- **`ScannerInterface` contract** — `query()` and `queryBatch()` signatures unchanged. All consumers (message handlers) are unaffected.
- **Graceful degradation** — `OsmScanner` still returns `[]` on transient HTTP failures without caching the error.

## Consequences

### Positive

- **~3 GB RAM freed** — Available for Valhalla, LLM services, or general headroom on constrained deployments
- **Simpler codebase** — Removed 3 PHP files, ~120 lines of dual-client/fallback logic
- **Faster startup** — No 120-second Overpass health check grace period; `docker compose up` completes sooner
- **Reduced operational surface** — One fewer service to monitor, update, and debug

### Negative

- **Higher latency on cache miss** — Public API: 1-5s vs self-hosted: 50-200ms. Mitigated by 24h cache and batch pre-fetch (most users never hit a cache miss after initial load).
- **External dependency** — `overpass-api.de` availability. Mitigated by graceful degradation (features degrade, app doesn't crash) and the rarity of uncached queries.

### Neutral

- **ADR-020 superseded** — Its entire purpose (dynamic Overpass region provisioning) is no longer relevant. The provisioner remains for Valhalla.
- **ADR-017 partially superseded** — Valhalla sections (§17.2, §17.3) remain active. Overpass sections (§17.1, §17.4, §17.5) are no longer in effect.

## Sources

- [ADR-017: Valhalla Routing Engine and Self-Hosted Overpass Integration](adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md)
- [ADR-020: Dynamic Overpass Region Provisioning](adr-020-dynamic-overpass-region-provisioning.md)
- [ADR-005: Orchestration, Optimization, and Caching of External APIs](adr-005-orchestration-optimization-and-caching-of-external-apis.md)

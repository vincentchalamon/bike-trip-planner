# ADR-017: Valhalla Routing Engine and Self-Hosted Overpass Integration

- **Status:** Accepted
- **Date:** 2026-03-03
- **Supersedes:** ADR-016 Option F (Self-hosted Overpass — deferred)

## Context and Problem Statement

Two features from the backlog (`docs/ideas.md`) are blocked without a routing engine:

1. **Cultural POI suggestion with route recalculation** — When a notable POI is discovered near the route, the system must calculate a detour (waypoint insertion) and present the added distance/elevation cost. This requires routing between arbitrary coordinates, not just GPS trace following.

2. **Accommodation selection with auto-reroute** — When a rider selects an accommodation that is off-trace, the system must re-route the stage endpoint to/from that accommodation. The current pipeline discovers accommodations (ADR-013) but cannot compute the connecting segments.

**Current state:**

- **Routing:** Zero capability. The backend processes GPS traces (GPX/KML) but cannot compute new routes between arbitrary points.
- **Overpass:** Public API at `overpass-api.de` with `overpass.client` scoped HTTP client. Works but latency is 1-5s per query, subject to rate limiting and availability issues.

**Synergy insight:** ADR-016 Option F (self-hosted Overpass) was deferred because the infrastructure cost was not justified alone. Valhalla requires a PBF extract from Geofabrik as input. Once that PBF is downloaded, importing it into a self-hosted Overpass instance has near-zero marginal cost — both services share the same source file. This changes the cost-benefit analysis: we get two infrastructure upgrades for the price of one PBF download.

## Architectural Requirements

| Requirement | Target | Rationale |
|---|---|---|
| Re-routing latency | < 1s end-to-end | Async via Messenger + Mercure SSE (user perceives instant) |
| Native elevation in cost model | Required | Bikepacking profiles must penalize climbs (ADR-013 context) |
| Geographic scope | Nord-Pas-de-Calais | Primary use case; graceful degradation outside zone |
| Disk footprint | ~5 GB total | PBF (~223 MB) + Valhalla tiles (~2 GB) + Overpass DB (~3 GB) |
| RAM footprint | ~4-5 GB total | Valhalla ~1-2 GB + Overpass ~2-3 GB |
| Pattern consistency | Scoped clients, interfaces, Messenger | Matches ADR-005, ADR-015, existing handler/message patterns |
| Backward compatibility | SSE pipeline unchanged | New computation slots alongside existing ones |

## Decision Drivers

1. **Feature enablement** — Two backlog features are completely blocked without routing
2. **Elevation is critical** — Bikepacking route quality depends on climb-aware cost models; a router without native elevation is inadequate
3. **Infrastructure synergy** — One PBF download serves two services, making self-hosted Overpass nearly free in marginal cost
4. **External dependency elimination** — Self-hosted Overpass removes rate limiting, improves latency 10-50x, and eliminates a runtime dependency on `overpass-api.de`

## Considered Options

### Option A: Valhalla (recommended)

[Valhalla](https://github.com/valhalla/valhalla) is an open-source, tiled routing engine by Mapzen/Linux Foundation.

**Strengths:**

- **Tiled architecture** — Routing graph split into tiles loaded on-demand → low base RAM (~1-2 GB for Nord-Pas-de-Calais)
- **Native elevation** — SRTM/DEM data baked into the cost model; elevation penalties are first-class in route computation
- **Sophisticated bicycle profile** — Configurable `use_roads`, `use_hills`, `cycling_speed`, surface preferences, `shortest` vs `bicycle` costing modes
- **Docker-ready** — `ghcr.io/gis-ops/docker-valhalla/valhalla` with PBF auto-import and tile building
- **Performance** — 10-50ms for short segments (<10 km), well within our <1s async budget
- **License** — Apache 2.0, no restrictions

**Weaknesses:**

- First-time tile build: ~5-10 min for Nord-Pas-de-Calais
- Limited to pre-imported geographic scope (by design — this is a feature for resource control)

### Option B: OSRM (rejected)

[OSRM](https://github.com/Project-OSRM/osrm-backend) is the fastest open-source router.

**Strengths:** Sub-millisecond routing, mature ecosystem.

**Rejection rationale:** No native elevation support. The bicycle profile is basic and does not account for climbs. For bikepacking, a route that ignores elevation is worse than no route — it would suggest flat-distance-optimal paths over mountain passes. Adding elevation post-hoc (via external DEM lookups) does not influence the route choice itself, only annotates it.

### Option C: GraphHopper (rejected)

[GraphHopper](https://github.com/graphhopper/graphhopper) offers good bicycle profiles with elevation.

**Strengths:** Elevation-aware routing, flexible profiles, active development.

**Rejection rationale:** JVM runtime with ~2-3 GB base memory before loading any graph. Combined with Overpass (~2-3 GB), total RAM would reach 5-6 GB for routing alone. Disproportionate for a single-region deployment. Valhalla's tiled architecture achieves comparable routing quality at roughly half the memory.

### Option D: External API — Google Directions / Mapbox (rejected)

**Strengths:** Zero infrastructure, global coverage, high quality.

**Rejection rationale:**

- Recurring cost per request (Google: $5-10/1000 requests, Mapbox: usage-based)
- External dependency and latency (100-500ms per request)
- No PBF synergy — Overpass would remain on the public API, losing the mutualization benefit
- Contradicts the local-first architecture principle (ADR-001)

### Sub-decision: Shared PBF Volume vs. Separate Downloads

| Approach | Disk | Bandwidth | Consistency | Complexity |
|---|---|---|---|---|
| **Shared volume (recommended)** | 1× PBF (~223 MB) | 1 download | Guaranteed — same file | Init container + named volume |
| Separate downloads | 2× PBF (~446 MB) | 2 downloads | Risk of version mismatch | Simpler per-service, but wasteful |

**Chosen: Shared volume.** An init container downloads the PBF once into a named volume. Both Valhalla and Overpass mount it read-only. This guarantees data consistency and halves bandwidth/disk usage.

## Decision Outcome

**Chosen: Option A — Valhalla + Shared PBF Volume + Self-hosted Overpass**

Valhalla provides elevation-aware bicycle routing with low memory overhead. The shared PBF volume makes self-hosted Overpass a near-free addition, replacing the public API dependency with a local instance offering 10-50x lower latency.

---

### 17.1 — Infrastructure: Docker Services

Three new services added to `compose.yaml`:

#### Services

| Service | Image | Role | Port | Depends on |
|---|---|---|---|---|
| — | `.docker/osm/lille-stub.osm.pbf` | Shared ~18 KB PBF stub (Lille roads) bind-mounted into both Overpass and Valhalla; real regions provisioned via `app:overpass:provision` (ADR-020) | — | — |
| `valhalla` | `ghcr.io/gis-ops/docker-valhalla/valhalla:latest` | Routing engine: builds tiles, serves route API | 8002 | `osm-download` |
| `overpass` | `wiktorn/overpass-api:latest` | Overpass API: imports PBF, serves Overpass QL | 8003 | `osm-download` |

#### Volumes

| Volume | Contents | Size | Mounted by |
|---|---|---|---|
| `osm-pbf-data` | PBF data (empty stub bind-mounted initially, provisioned regions after `app:overpass:provision`) | ~250 MB+ | `valhalla` (ro), `overpass` (ro) |
| `valhalla-tiles` | Valhalla routing tiles + elevation data | ~2 GB | `valhalla` (rw) |
| `overpass-data` | Overpass database | ~3 GB | `overpass` (rw) |

#### Resource Estimates

| Resource | `osm-download` | `valhalla` | `overpass` | Total |
|---|---|---|---|---|
| Disk | ~250 MB (PBF) | ~2 GB (tiles) | ~3 GB (DB) | ~5.25 GB |
| RAM (import) | Negligible | ~2 GB (peak) | ~3 GB (peak) | ~5 GB peak |
| RAM (runtime) | — | ~1-2 GB | ~2-3 GB | ~3-5 GB |
| Import time | — (bind-mounted) | ~5-10 min (tiles) | ~20-25 min (DB, after provisioning) | ~35 min (after provisioning) |

#### PBF Source

```text
https://download.geofabrik.de/europe/france/nord-pas-de-calais-latest.osm.pbf
```

#### Docker Compose Additions

```yaml
services:
  valhalla:
    image: ghcr.io/gis-ops/docker-valhalla/valhalla:latest
    ports:
      - "8002:8002"
    volumes:
      - osm-pbf-data:/data/osm:ro
      - valhalla-tiles:/data/valhalla
    environment:
      - tile_urls=/data/osm/region.osm.pbf
      - serve_tiles=True
      - build_elevation=True
      - build_admins=True
      - build_time_zones=True
    depends_on:
      osm-init:
        condition: service_completed_successfully

  overpass:
    image: wiktorn/overpass-api:latest
    ports:
      - "8003:8003"
    volumes:
      # Lille PBF stub — real road data for instant startup.
      # Real region data provisioned via: bin/console app:overpass:provision (ADR-020)
      - .docker/osm/lille-stub.osm.pbf:/data/osm/region.osm.pbf:ro
      - overpass-data:/db
    environment:
      - OVERPASS_META=yes
      - OVERPASS_MODE=init
      - OVERPASS_PLANET_URL=file:///data/osm/region.osm.pbf
      - OVERPASS_RULES_LOAD=10

volumes:
  valhalla-tiles:
  overpass-data:
```

---

### 17.2 — Backend Integration: Routing Provider

#### Scoped HTTP Client

New entry in `api/config/packages/framework.php`:

```php
'routing.client' => [
    'base_uri' => 'http://valhalla:8002',
    'timeout' => 5,
],
```

The existing `overpass.client` base URI changes from `https://overpass-api.de` to `http://overpass:8003` (with fallback strategy — see §17.4).

#### Interface

Following the `RouteFetcherInterface` / `WeatherProviderInterface` pattern (ADR-015):

```php
namespace App\Routing;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.routing_provider')]
interface RoutingProviderInterface
{
    /**
     * Calculate a bicycle route between two points, optionally via intermediate waypoints.
     *
     * @param list<Coordinate> $via
     */
    public function calculateRoute(
        Coordinate $from,
        Coordinate $to,
        array $via = [],
    ): RoutingResult;
}
```

#### Value Object: `RoutingResult`

```php
namespace App\Routing;

use App\ApiResource\Model\Coordinate;

final readonly class RoutingResult
{
    /**
     * @param list<Coordinate> $coordinates  Full route geometry with elevation
     * @param float            $distance     Total distance in meters
     * @param float            $elevationGain Total elevation gain in meters
     * @param float            $duration     Estimated duration in seconds
     */
    public function __construct(
        public array $coordinates,
        public float $distance,
        public float $elevationGain,
        public float $duration,
    ) {}
}
```

#### Implementation: `ValhallaRoutingProvider`

```php
namespace App\Routing;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ValhallaRoutingProvider implements RoutingProviderInterface
{
    public function __construct(
        #[Autowire(service: 'routing.client')]
        private HttpClientInterface $routingClient,
    ) {}

    public function calculateRoute(
        Coordinate $from,
        Coordinate $to,
        array $via = [],
    ): RoutingResult {
        $locations = [
            ['lat' => $from->lat, 'lon' => $from->lon],
            ...array_map(
                fn (Coordinate $c) => ['lat' => $c->lat, 'lon' => $c->lon],
                $via,
            ),
            ['lat' => $to->lat, 'lon' => $to->lon],
        ];

        $response = $this->routingClient->request('POST', '/route', [
            'json' => [
                'locations' => $locations,
                'costing' => 'bicycle',
                'costing_options' => [
                    'bicycle' => [
                        'bicycle_type' => 'Hybrid',
                        'cycling_speed' => 20.0,
                        'use_roads' => 0.5,
                        'use_hills' => 0.3,
                    ],
                ],
                'directions_options' => ['units' => 'km'],
                'shape_format' => 'polyline6',
            ],
        ]);

        $data = $response->toArray();
        $leg = $data['trip']['legs'][0];

        $coordinates = $this->decodePolyline6($leg['shape']);
        $summary = $data['trip']['summary'];

        return new RoutingResult(
            coordinates: $coordinates,
            distance: $summary['length'] * 1000,  // km → m
            elevationGain: $summary['elevation_gain'] ?? 0.0,
            duration: $summary['time'],
        );
    }

    /**
     * Decode Valhalla polyline6 format into Coordinate[].
     *
     * @return list<Coordinate>
     */
    private function decodePolyline6(string $encoded): array { /* ... */ }
}
```

#### Cache Pool

New entry in `api/config/packages/cache.php`:

```php
'cache.routing' => [
    'adapter' => 'cache.adapter.redis',
    'default_lifetime' => 86400, // 24 hours
],
```

Cache key pattern (consistent with `OsmScanner`):

```php
$cacheKey = 'routing.' . hash('xxh128', serialize([$from, $to, $via]));
```

---

### 17.3 — Async Processing: Route Segment Recalculation

#### Message DTO

Following the `RecalculateStages` pattern:

```php
namespace App\Message;

final readonly class RecalculateRouteSegment
{
    /**
     * @param string $tripId     Trip identifier
     * @param int    $stageIndex Stage to recalculate
     * @param float  $waypointLat  New waypoint latitude
     * @param float  $waypointLon  New waypoint longitude
     * @param string $reason     Why: 'poi_detour' | 'accommodation_reroute'
     */
    public function __construct(
        public string $tripId,
        public int $stageIndex,
        public float $waypointLat,
        public float $waypointLon,
        public string $reason,
    ) {}
}
```

#### Handler

```php
namespace App\MessageHandler;

use App\Enum\ComputationName;
use App\Message\RecalculateRouteSegment;
use App\Routing\RoutingProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecalculateRouteSegmentHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private RoutingProviderInterface $routingProvider,
        private TripStateManagerInterface $stateManager,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(RecalculateRouteSegment $message): void
    {
        $this->executeWithTracking(
            $message->tripId,
            ComputationName::ROUTE_SEGMENT,
            function () use ($message) {
                // 1. Get current stage endpoints from trip state
                // 2. Build waypoint Coordinate from message
                // 3. Call routingProvider->calculateRoute(stageStart, stageEnd, [waypoint])
                // 4. Store recalculated segment in trip state
                // 5. Publish MercureEventType::ROUTE_SEGMENT_RECALCULATED
            },
        );
    }
}
```

#### Enum Additions

```php
// ComputationName
case ROUTE_SEGMENT = 'route_segment';

// MercureEventType
case ROUTE_SEGMENT_RECALCULATED = 'route_segment_recalculated';
```

#### Messenger Routing

```php
// messenger.php
RecalculateRouteSegment::class => 'async',
```

---

### 17.4 — Fallback Strategy

#### Valhalla (routing)

Valhalla only has data for Nord-Pas-de-Calais. When a route request falls outside the imported region:

- Valhalla returns HTTP 400 with `"error_code": 171` (no route found) or `"error_code": 170` (no location found)
- `ValhallaRoutingProvider` throws `RoutingUnavailableException`
- The handler catches this exception and publishes an error event via Mercure
- The frontend disables the POI detour / accommodation reroute features for that stage
- **No silent fallback to an external routing API** — the feature is simply unavailable outside the covered region

#### Overpass (POI/accommodation discovery)

Self-hosted Overpass replaces the public API for the covered region. For trips outside Nord-Pas-de-Calais:

- The `OsmScanner` detects that the query returned zero results for coordinates outside the imported region
- Fallback: retry the same query against the public `overpass-api.de` endpoint
- Implementation: decorator pattern wrapping the scoped client, with the public API as secondary

```php
// Simplified fallback logic in OsmScanner
try {
    $result = $this->localOverpassClient->request(/* ... */);
    if ($this->isEmptyResult($result)) {
        throw new OutOfRegionException();
    }
    return $result;
} catch (OutOfRegionException) {
    return $this->publicOverpassClient->request(/* ... */);
}
```

This preserves backward compatibility: existing trips outside Nord-Pas-de-Calais continue to work exactly as before.

---

### 17.5 — OSM Data Update Strategy

#### PBF Update (Monthly Cron)

```bash
# Runs monthly via cron or CI/CD
curl -L -o /tmp/region.osm.pbf \
  https://download.geofabrik.de/europe/france/nord-pas-de-calais-latest.osm.pbf
# Replace volume content
docker cp /tmp/region.osm.pbf osm-download:/data/region.osm.pbf
```

#### Overpass: Incremental Diffs

The `wiktorn/overpass-api` image supports incremental updates via the `OVERPASS_DIFF_URL` environment variable:

```yaml
environment:
  - OVERPASS_DIFF_URL=https://download.openstreetmap.fr/replication/europe/france/nord_pas_de_calais/minute/
```

This applies minutely diffs automatically, keeping the Overpass database current without full reimport.

#### Valhalla: Tile Rebuild

After PBF update, Valhalla tiles must be rebuilt:

```bash
docker compose restart valhalla
# Tile rebuild takes ~5-10 min for Nord-Pas-de-Calais
# Valhalla serves stale tiles during rebuild, then hot-swaps
```

The gis-ops Docker image detects PBF changes on startup and rebuilds tiles automatically.

---

## Consequences

### Positive

- **Two backlog features unblocked** — POI detour suggestion and accommodation re-routing become implementable
- **Overpass latency improvement** — Local: 50-200ms vs public API: 1-5s (10-50x faster, per ADR-016 Option F estimates)
- **Overpass reliability** — No more rate limiting or availability dependency on `overpass-api.de`
- **Future routing features enabled** — Lunch stop suggestions, multi-day route optimization, alternative route proposals all become possible with the routing infrastructure in place
- **Consistent architecture** — New services follow established patterns: scoped clients, interfaces, Messenger async, Mercure SSE

### Negative

- **Geographic limitation** — Routing and fast Overpass are limited to Nord-Pas-de-Calais; extending requires downloading additional PBF regions and rebuilding
- **Resource cost** — +5 GB disk, +4-5 GB RAM (acceptable for a dedicated server, tight for small VPS)
- **First-start latency** — ~35 minutes for initial PBF download + Valhalla tile build + Overpass import (one-time cost, subsequent starts use cached volumes)
- **Operational complexity** — Three new services to monitor and update (mitigated by Docker health checks and the monthly update cron)

### Neutral

- **ADR-016 Option F superseded** — Self-hosted Overpass is now bundled with Valhalla rather than being a standalone optimization. The latency and reliability benefits documented in ADR-016 are realized here
- **Extension point** — `RoutingProviderInterface` allows future backends (OSRM for flat regions, GraphHopper for specific profiles) without changing consumers
- **PBF region expansion** — Adding regions (e.g., all of France) requires only changing the Geofabrik URL and accepting higher resource usage (~3-4 GB PBF, ~10-15 GB tiles)

## Sources

- [ADR-016: Performance Optimization Strategy](adr-016-performance-optimization-strategy.md) — Option F (self-hosted Overpass, deferred)
- [ADR-005: Orchestration, Optimization, and Caching of External APIs](adr-005-orchestration-optimization-and-caching-of-external-apis.md) — Overpass caching patterns
- [ADR-013: Accommodation Discovery and Heuristic Pricing Strategy](adr-013-accomodation-discovery-and-heuristic-pricing-strategy.md) — Accommodation pipeline context
- [ADR-015: Dynamic Engine Management Design Pattern](adr-015-dynamic-engine-management-design-pattern.md) — Registry/interface patterns
- [Valhalla Documentation](https://valhalla.github.io/valhalla/)
- [gis-ops/docker-valhalla](https://github.com/gis-ops/docker-valhalla) — Docker image used
- [wiktorn/overpass-api](https://github.com/wiktorn/docker-overpass-api) — Docker image used
- [Geofabrik Downloads — Nord-Pas-de-Calais](https://download.geofabrik.de/europe/france/nord-pas-de-calais.html)

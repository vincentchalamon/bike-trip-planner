# ADR-005: Orchestration, Optimization, and Caching of External APIs (OSM & Weather)

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The "Survival & Security" module of Bike Trip Planner heavily relies on external data sources to enrich the user's route:

1. **OpenStreetMap (OSM) via Overpass API:** To detect drinking water (cemeteries/fountains), TER train stations, and
   road surfaces.
2. **OpenWeather API:** To fetch weather forecasts and wind direction for the calculated stages.

A standard bikepacking route can span hundreds of kilometers. Querying the Overpass API for every single GPS coordinate
along a 300km track is architecturally unviable: it will result in massive latency, exceed HTTP request size limits, and
immediately trigger IP bans from public Overpass instances due to rate limiting. Similarly, the OpenWeather API has
strict daily quota limits on free/low-tier plans.

We must define a strategy to orchestrate these external calls from the PHP 8.5 backend that minimizes network latency,
avoids rate limits, securely manages API keys, and aggressively caches responses.

### Architectural Requirements

| Requirement          | Description                                                                                                                                |
|----------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| Rate-Limit Evasion   | The system must minimize the number of external HTTP requests to stay within free-tier quotas.                                             |
| Latency Reduction    | External queries must not bottleneck the generation of the trip. Responses should be served from a local cache when possible.              |
| Spatial Optimization | Overpass queries must be spatially optimized to scan the entire route in a single, efficient query rather than thousands of micro-queries. |
| Secret Management    | API keys (e.g., OpenWeatherMap) must never be exposed to the Next.js frontend.                                                             |

---

## Decision Drivers

* **Server IP Reputation** — Public Overpass servers (like `overpass-api.de`) strictly throttle or ban abusive IPs. We
  must be good net-citizens.
* **Performance** — Caching identical requests (e.g., scanning the same segment of a popular route like "La Véloscénie")
  drastically improves UX.
* **Simplicity** — As a stateless MVP without a Redis instance, caching must rely on the container's local filesystem
  while remaining fast and reliable.

---

## Considered Options

### Option A: Direct Client-Side Calls

The Next.js frontend calls Overpass and OpenWeather directly using the browser's `fetch` API.

### Option B: Naive Backend Proxy

The PHP API Platform backend acts as a proxy, forwarding requests to OSM and Weather APIs on every trip generation
without any caching or spatial compression.

### Option C: Backend Orchestration with Spatial Decimation and Symfony HTTP Caching (Chosen)

The PHP backend compresses the spatial query using a decimated polyline, and utilizes Symfony 8's built-in
`CachingHttpClient` with a `FilesystemAdapter` to cache the external API responses transparently.

---

## Decision Outcome

**Chosen: Option C (Backend Orchestration with Spatial Decimation and Caching)**

### Why Other Options Were Rejected

**Option A (Client-Side) rejected:**

* Exposes the OpenWeather API key to the public, leading to quota theft.
* Forces the user's browser to execute complex Overpass QL queries, which may be blocked by CORS policies or
  ad-blockers.

**Option B (Naive Proxy) rejected:**

* Fails to protect the server's IP address from Overpass rate limits.
* If a user clicks "Generate" three times in a row while tweaking their dates, the backend will make three identical
  heavy spatial queries, adding ~5 seconds of latency each time.

---

## Implementation Strategy

### 5.1 — Spatial Query Optimization (Overpass QL)

Instead of querying a bounding box (which might include hundreds of irrelevant square kilometers of data for a diagonal
route) or querying point-by-point, we will use Overpass QL's `around` filter combined with a polyline.

Because a raw GPX track contains too many points for a valid HTTP GET request URL length, we will pipe the coordinates
through the Douglas-Peucker decimation algorithm (defined in ADR-004) to reduce a 10,000-point route to roughly 100
strategic anchor points. We then format these points into a single Overpass QL query.

**File:** `api/src/Osm/OverpassQueryBuilder.php`

```php
namespace App\Osm;

final class OverpassQueryBuilder
{
    /**
     * Generates an Overpass QL query to find nodes around a decimated polyline.
     * @param array<array{lat: float, lon: float}> $decimatedPoints
     */
    public function buildPolylineQuery(array $decimatedPoints, int $radiusMeters = 500): string
    {
        // Flatten the array into a comma-separated string: lat1,lon1,lat2,lon2...
        $coords = implode(',', array_map(
            fn($p) => sprintf('%.5f,%.5f', $p['lat'], $p['lon']),
            $decimatedPoints
        ));

        // Overpass QL: Find drinking water and cemeteries within X meters of the route line.
        return <<<QL
        [out:json][timeout:25];
        (
          node(around:{$radiusMeters},{$coords})["amenity"="drinking_water"];
          node(around:{$radiusMeters},{$coords})["amenity"="grave_yard"];
          node(around:{$radiusMeters},{$coords})["landuse"="cemetery"];
        );
        out body;
        >;
        out skel qt;
        QL;
    }
}
```

### 5.2 — Transparent HTTP Caching (Symfony)

Symfony 7.4 introduced the `CachingHttpClient`, which automatically stores and reuses API responses based on RFC 9111
HTTP caching headers (or manual TTLs) using the Cache component. Since we lack Redis, we will configure the
`FilesystemAdapter`.

**Configuration:** `api/config/packages/cache.yaml`

```yaml
framework:
  cache:
    # Use the local filesystem for the application cache pool
    app: cache.adapter.filesystem
    directory: '%kernel.project_dir%/var/cache/api_responses'
```

**Configuration:** `api/config/packages/framework.yaml`

```yaml
framework:
  http_client:
    scoped_clients:
      overpass.client:
        base_uri: 'https://overpass-api.de/api/interpreter'
        timeout: 30
      weather.client:
        base_uri: 'https://api.openweathermap.org/data/2.5/'
```

**File:** `api/src/Osm/OsmScanner.php`

```php
namespace App\Osm;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Request;

final readonly class OsmScanner
{
    private HttpClientInterface $cachingClient;

    public function __construct(
        HttpClientInterface $overpassClient
    ) {
        // Wrap the scoped HTTP client with the CachingHttpClient using the Filesystem pool.
        $store = new FilesystemAdapter('osm_queries', 86400); // 24 hours TTL
        $this->cachingClient = new CachingHttpClient($overpassClient, $store);
    }

    public function scanRoute(string $overpassQuery): array
    {
        // The CachingHttpClient will intercept this. If the exact same query was made
        // in the last 24 hours, it will return the cached response without hitting the network.
        $response = $this->cachingClient->request(Request::METHOD_POST, '', [
            'body' => $overpassQuery,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        return $response->toArray();
    }
}
```

### 5.3 — Cache Invalidation and Variations

* **OSM Data:** Road infrastructure and water points rarely change overnight. The `OsmScanner` cache is set to a hard
  TTL of 24 hours (86,400 seconds). The cache key is automatically derived from the URL and POST body (the Overpass QL
  string).
* **Weather Data:** Weather is highly temporal. The `WeatherScanner` service will use a shorter TTL of 3 hours.

---

## Verification

1. **Cache Hit Assertions:** - Write a PHPUnit test that calls the `OsmScanner` twice with the same mocked Overpass QL
   query.

   * Assert that the underlying mock `HttpClient` only registers `1` network request, proving the `CachingHttpClient`
     successfully intercepted the second call.

2. **Query Length Validation:** Ensure the output of the Douglas-Peucker decimation (ADR-004) produces an Overpass QL
   string under 8KB to avoid HTTP `414 URI Too Long` or `413 Payload Too Large` errors from the Overpass API.
3. **Log Monitoring:** During local testing, check `var/cache/api_responses` to verify that the Symfony Filesystem
   adapter is physically writing the serialized cache files to the disk.

---

## Consequences

### Positive

* **Immunity to Rate Limits:** If 50 users generate trips covering the same popular cycling route within 24 hours, the
  Overpass API is only hit once.
* **Sub-second Latency:** Subsequent route generations (e.g., a user adjusting their pacing slider and triggering a
  recalculation) will resolve in milliseconds because the heavy spatial data is loaded straight from the local NVMe/SSD
  via the filesystem cache.
* **Simplified Codebase:** Relying on `CachingHttpClient` removes the need to manually inject `CacheInterface` and write
  `$cache->get(key, callback)` boilerplate for every HTTP call.

### Negative

* **Disk Space Usage:** The `var/cache/` directory will grow as more unique routes are queried. The Symfony cache
  `prune()` command must be scheduled (e.g., via a basic cron job or during deployment) to delete expired filesystem
  items.
* **Stale Weather Data:** A 3-hour cache on weather might occasionally miss sudden meteorological updates, requiring a
  manual "force refresh" button in the UI for edge cases (scheduled for Lot 2).

### Neutral

* The system heavily relies on the Douglas-Peucker algorithm's accuracy. If the decimation is too aggressive, the
  Overpass `around` query might cut corners and miss POIs located on winding roads. The `around:radius` must be padded (
  e.g., 500 meters) to account for polyline simplification losses.

---

## Sources

* [New in Symfony 8.4: Caching HTTP Client (Symfony Blog)](https://symfony.com/blog/new-in-symfony-7-4-caching-http-client)
* [Supercharging Performance with Caching HTTP Client (Nexgismo)](https://www.nexgismo.com/blog/symfony-7-4-caching-http-client)
* [Filesystem Cache Adapter (Symfony Docs)](https://symfony.com/doc/current/components/cache/adapters/filesystem_adapter.html)
* [Cache Pools and Supported Adapters (Symfony Docs)](https://symfony.com/doc/current/components/cache/cache_pools.html)
* [Searching within a radius using 'around' - OSM Queries](https://osm-queries.ldodds.com/tutorial/12-radius-search.osm.html)

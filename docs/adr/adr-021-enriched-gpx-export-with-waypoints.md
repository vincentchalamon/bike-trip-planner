# ADR-021: Enriched GPX Export with Waypoints

- **Status:** Proposed
- **Date:** 2026-03-05
- **Depends on:** ADR-004 (GPX parsing, decimation, GpxStreamParser), ADR-018 (Garmin export strategy)
- **Enables:** ADR-018 Phase 1 (GPX enrichi), ADR-018 Option B (FIT Course Points via shared DTO)

## Context and Problem Statement

The current GPX export produces a trace-only file: a single `<trk>` element containing `<trkpt>` coordinates from the decimated geometry. The rider downloads this file and loads it on their GPS, but sees only the route line -- no markers for lunch stops, resupply points, or overnight accommodations.

The data already exists in memory. The async computation pipeline populates `Stage.pois` (via `ScanPoisHandler`) and `Stage.accommodations` (via `ScanAccommodationsHandler`), but `GpxNormalizer` discards this data at export time, passing only `$data->geometry` to `GpxWriter::generate()`.

The GPX 1.1 specification natively supports `<wpt>` (waypoint) elements for standalone points of interest. These are rendered as markers on virtually all GPS devices and mapping software, making them the ideal vehicle for enriching the export.

### Current Export Flow

```text
Stage
  |-- geometry (list<Coordinate>)  -->  GpxNormalizer  -->  GpxWriter::generate()  -->  <trk>/<trkpt>
  |-- pois (PointOfInterest[])     -->  (ignored)
  |-- accommodations (Accommodation[])  -->  (ignored)
```

### Target Export Flow

```text
Stage
  |-- geometry (list<Coordinate>)       -->  GpxNormalizer  -->  GpxWriter::generate()  -->  <trk>/<trkpt>
  |-- pois (PointOfInterest[])          -->  GpxNormalizer  -->  GpxWaypoint[]          -->  <wpt>
  |-- accommodations (Accommodation[])  -->  GpxNormalizer  -->  GpxWaypoint[]          -->  <wpt>
```

---

## Considered Options

### Option A: `<wpt>` for all POIs and accommodations (chosen)

Add top-level `<wpt>` elements in the GPX file, one per POI and accommodation. Each waypoint carries a `<name>`, `<sym>` (Garmin-compatible symbol identifier), and `<type>` (domain category).

```xml
<wpt lat="50.6292" lon="3.0573">
  <name>Boulangerie du Centre</name>
  <sym>Shopping Center</sym>
  <type>bakery</type>
</wpt>
```

**Advantages:**

- Standard GPX 1.1, universally supported (Garmin, Wahoo, Hammerhead, Coros, OsmAnd, QGIS...)
- `<sym>` values from the Garmin symbol set are recognized by most devices
- Semantically correct: waypoints are autonomous POIs, independent of the route path

**Drawbacks:**

- High waypoint count on long routes (mitigated by future selected/suggested filtering)

### Option B: `<rte><rtept>` for stops (rejected)

Encode stops as route points inside a `<rte>` element.

**Rejected because:** `<rtept>` is semantically a turn-by-turn routing instruction. GPS devices interpret `<rte>` as a route to be navigated point-to-point, recalculating between each `<rtept>`. A restaurant or campground is not a navigation waypoint -- it's a POI to display alongside the track. Using `<rte>` would cause GPS devices to generate unwanted turn-by-turn navigation between POIs.

### Option C: Garmin proprietary extensions (rejected)

Use `<extensions><gpxx:WaypointExtension>` with Garmin-specific XML namespaces for richer POI metadata (proximity alerts, display modes, categories).

**Rejected because:** Breaks compatibility with non-Garmin devices and software. The universal `<wpt>` + `<sym>` approach already provides device-native rendering on Garmin via recognized symbol names, without vendor lock-in.

---

## Decision Outcome

**Chosen: Option A** -- Enrich the GPX export with `<wpt>` elements carrying Garmin-compatible `<sym>` values.

---

## Implementation Strategy

### 21.1 -- `GpxWaypoint` DTO

**File:** `api/src/GpxWriter/GpxWaypoint.php`

A simple value object representing a waypoint to be written into the GPX file. This DTO decouples the GPX writer from the domain models (`PointOfInterest`, `Accommodation`), and will be reused by the `FitWriter` (ADR-018) for Course Point generation.

```php
namespace App\GpxWriter;

final readonly class GpxWaypoint
{
    public function __construct(
        public float $lat,
        public float $lon,
        public string $name,
        public string $symbol,
        public string $type,
    ) {
    }
}
```

| Property | Source (POI) | Source (Accommodation) |
|----------|-------------|----------------------|
| `lat` | `PointOfInterest::$lat` | `Accommodation::$lat` |
| `lon` | `PointOfInterest::$lon` | `Accommodation::$lon` |
| `name` | `PointOfInterest::$name` | `Accommodation::$name` |
| `symbol` | Mapped via `GpxSymbolMapper` | Mapped via `GpxSymbolMapper` |
| `type` | `PointOfInterest::$category` | `Accommodation::$type` |

---

### 21.2 -- `GpxSymbolMapper`

**File:** `api/src/GpxWriter/GpxSymbolMapper.php`

Maps domain categories (OSM `amenity`, `shop`, `tourism` values) to Garmin-compatible `<sym>` identifiers. Garmin devices recognize a fixed set of symbol names and render them as icons on the map.

```php
namespace App\GpxWriter;

final class GpxSymbolMapper
{
    /** @var array<string, string> */
    private const array SYMBOL_MAP = [
        // Food & drink (amenity)
        'restaurant'   => 'Restaurant',
        'cafe'         => 'Restaurant',
        'bar'          => 'Restaurant',
        'fast_food'    => 'Restaurant',

        // Resupply (shop)
        'supermarket'  => 'Shopping Center',
        'convenience'  => 'Shopping Center',
        'bakery'       => 'Shopping Center',
        'butcher'      => 'Shopping Center',
        'pastry'       => 'Shopping Center',
        'deli'         => 'Shopping Center',
        'greengrocer'  => 'Shopping Center',
        'general'      => 'Shopping Center',
        'farm'         => 'Shopping Center',
        'marketplace'  => 'Shopping Center',

        // Health (amenity)
        'pharmacy'     => 'Medical Facility',

        // Tourism
        'viewpoint'    => 'Scenic Area',
        'attraction'   => 'Museum',

        // Water (future)
        'drinking_water' => 'Drinking Water',

        // Accommodation (tourism)
        'camp_site'    => 'Campground',
        'hostel'       => 'Lodge',
        'guest_house'  => 'Lodge',
        'alpine_hut'   => 'Lodge',
        'chalet'       => 'Lodge',
        'hotel'        => 'Hotel',
        'motel'        => 'Hotel',
    ];

    private const string FALLBACK_SYMBOL = 'Flag, Blue';

    public static function map(string $category): string
    {
        return self::SYMBOL_MAP[$category] ?? self::FALLBACK_SYMBOL;
    }
}
```

#### Symbol Mapping Reference

| Domain category | `<sym>` Garmin | Source tag |
|---|---|---|
| `restaurant`, `cafe`, `bar`, `fast_food` | `Restaurant` | `amenity` |
| `supermarket`, `convenience`, `bakery`, `butcher`, `pastry`, `deli`, `greengrocer`, `general`, `farm`, `marketplace` | `Shopping Center` | `shop` / `amenity` |
| `pharmacy` | `Medical Facility` | `amenity` |
| `viewpoint` | `Scenic Area` | `tourism` |
| `attraction` | `Museum` | `tourism` |
| `drinking_water` | `Drinking Water` | `amenity` (future) |
| `camp_site` | `Campground` | `tourism` |
| `hostel`, `guest_house`, `alpine_hut`, `chalet` | `Lodge` | `tourism` |
| `hotel`, `motel` | `Hotel` | `tourism` |
| Unknown (fallback) | `Flag, Blue` | -- |

Categories sourced from:

- **POIs:** `ScanPoisHandler::RESUPPLY_CATEGORIES` + Overpass query in `OsmOverpassQueryBuilder` (amenity, shop, tourism tags)
- **Accommodations:** `OsmOverpassQueryBuilder::buildAccommodationQuery()` (tourism tag) + `PricingHeuristicEngine::PRICE_BRACKETS`

---

### 21.3 -- `GpxWriterInterface` + `GpxWriter` Modification

**Files:**

- `api/src/GpxWriter/GpxWriterInterface.php`
- `api/src/GpxWriter/GpxWriter.php`

Add an optional `$waypoints` parameter to the `generate()` method. Waypoints are written as `<wpt>` elements before the `<trk>` element (GPX convention: waypoints precede tracks).

```php
interface GpxWriterInterface
{
    /**
     * @param list<Coordinate>   $points
     * @param list<GpxWaypoint>  $waypoints
     */
    public function generate(
        array $points,
        string $trackName = '',
        array $waypoints = [],
    ): string;
}
```

In `GpxWriter::generate()`, after opening the `<gpx>` element and before the `<trk>`:

```php
// Write waypoints
foreach ($waypoints as $waypoint) {
    $xml->startElement('wpt');
    $xml->writeAttribute('lat', (string) $waypoint->lat);
    $xml->writeAttribute('lon', (string) $waypoint->lon);
    $xml->writeElement('name', $waypoint->name);
    $xml->writeElement('sym', $waypoint->symbol);
    $xml->writeElement('type', $waypoint->type);
    $xml->endElement(); // wpt
}
```

The resulting GPX structure:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="BikeTripPlanner" xmlns="http://www.topografix.com/GPX/1/1">
    <wpt lat="50.6292" lon="3.0573">
        <name>Boulangerie du Centre</name>
        <sym>Shopping Center</sym>
        <type>bakery</type>
    </wpt>
    <wpt lat="50.6381" lon="3.0612">
        <name>Camping Les Peupliers</name>
        <sym>Campground</sym>
        <type>camp_site</type>
    </wpt>
    <trk>
        <name>Stage 1</name>
        <trkseg>
            <trkpt lat="50.6292" lon="3.0573"><ele>42.0</ele></trkpt>
            <!-- ... -->
        </trkseg>
    </trk>
</gpx>
```

---

### 21.4 -- `GpxNormalizer` Modification

**File:** `api/src/Serializer/GpxNormalizer.php`

The normalizer converts `Stage.pois` and `Stage.accommodations` into `GpxWaypoint[]` and passes them to `GpxWriter::generate()`.

```php
public function normalize(mixed $data, ?string $format = null, array $context = []): string
{
    \assert($data instanceof Stage);

    $label = $data->label ?? \sprintf('Stage %d', $data->dayNumber);

    $waypoints = $this->buildWaypoints($data);

    return $this->gpxWriter->generate(
        $data->geometry ?: [$data->startPoint, $data->endPoint],
        $label,
        $waypoints,
    );
}

/**
 * @return list<GpxWaypoint>
 */
private function buildWaypoints(Stage $stage): array
{
    $waypoints = [];

    foreach ($stage->pois as $poi) {
        $waypoints[] = new GpxWaypoint(
            lat: $poi->lat,
            lon: $poi->lon,
            name: $poi->name,
            symbol: GpxSymbolMapper::map($poi->category),
            type: $poi->category,
        );
    }

    foreach ($stage->accommodations as $accommodation) {
        $waypoints[] = new GpxWaypoint(
            lat: $accommodation->lat,
            lon: $accommodation->lon,
            name: $accommodation->name,
            symbol: GpxSymbolMapper::map($accommodation->type),
            type: $accommodation->type,
        );
    }

    return $waypoints;
}
```

---

## Consequences

### Positive

- **GPS markers out of the box** -- Riders see resupply points, restaurants, and accommodations as icons on their GPS device, directly usable for navigation planning during the ride.
- **Foundation for FIT export** -- The `GpxWaypoint` DTO is reusable by the `FitWriter` (ADR-018 Option B) for generating typed Course Points (`Food`, `Water`, `Summit`...), avoiding a parallel conversion pipeline.
- **Standard-compliant** -- Uses only GPX 1.1 standard elements (`<wpt>`, `<sym>`, `<type>`), no proprietary extensions. Works with Garmin, Wahoo, Hammerhead, Coros, OsmAnd, QGIS, and any GPX-compliant software.
- **Backward-compatible** -- The `$waypoints = []` default parameter ensures existing callers continue to work without modification.

### Negative

- **Waypoint volume** -- A multi-day trip through a populated area could generate 50-100+ waypoints per stage, potentially cluttering the GPS display. Mitigation: a future iteration will filter waypoints based on selected/suggested status (requires ADR-017 Valhalla routing for detour cost calculation).
- **No elevation on waypoints** -- `<wpt>` supports `<ele>` but POI/Accommodation models don't carry elevation data. Not critical for marker display but could be added if needed.

### Neutral

- The `GpxSymbolMapper` symbol set covers all categories currently queried by `OsmOverpassQueryBuilder`. Adding new OSM categories to the Overpass queries will require a corresponding entry in the mapper (or they fall back to `Flag, Blue`).
- Compatible with the future selected/suggested POI filtering -- the mapper and DTO don't need changes, only the `buildWaypoints()` method in `GpxNormalizer` would add a filter predicate.

---

## Cross-References

- **[ADR-004](adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md)** -- GPX parsing pipeline, `GpxStreamParser`, decimation. The enriched export builds on the existing `GpxWriter` introduced alongside ADR-004.
- **[ADR-017](adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md)** -- Valhalla routing, required for future selected/suggested POI filtering (detour cost calculation).
- **[ADR-018](adr-018-garmin-export-and-device-sync-strategy.md)** -- Garmin export strategy. Phase 1 depends on this ADR for the enriched GPX. The `GpxWaypoint` DTO will be reused for FIT Course Points.

## Sources

- [GPX 1.1 Schema](https://www.topografix.com/GPX/1/1/) -- `<wpt>` element specification
- [Garmin MapSource Symbol Names](https://www.garmin.com/xmlschemas/GpxExtensions/v3/GpxExtensionsv3.xsd) -- Recognized `<sym>` values
- [GPX for Developers](https://www.topografix.com/gpx_for_developers.asp) -- Best practices for GPX generation

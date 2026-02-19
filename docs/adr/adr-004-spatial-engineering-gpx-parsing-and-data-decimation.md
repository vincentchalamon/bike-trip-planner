# ADR-004: Spatial Engineering, GPX Parsing, and Data Decimation

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Bike Trip Planner’s core value lies in analyzing user-provided routes (via Komoot URLs or raw `.gpx` file uploads). Bikepacking
routes are typically long (100km to 1500km) and consist of tens of thousands of GPS coordinates (`<trkpt>` in GPX).

Processing these files in the PHP 8.5 backend introduces three critical engineering challenges:

1. **Memory Consumption:** Loading a 15MB XML GPX file entirely into RAM using PHP's `simplexml_load_file()` (DOM
   parsing) can easily hit PHP memory limits (`memory_limit`) when multiple users upload files concurrently.
2. **Elevation Noise:** Raw GPS data is notoriously noisy. Summing the raw altitude differences between every single
   point often results in an exaggerated total positive elevation gain () by up to 30%.
3. **Frontend Payload Constraints:** Sending an array of 25,000 coordinates `[lat, lng]` via the API to the Next.js
   frontend will result in a massive JSON payload (several megabytes), which will freeze the Zustand store and crash the
   client-side map renderer (e.g., MapLibre GL JS).

We must define a strategy to parse files efficiently, calculate accurate metrics, and compress the spatial data before
it reaches the frontend.

### Architectural Requirements

| Requirement          | Description                                                                                                      |
|----------------------|------------------------------------------------------------------------------------------------------------------|
| Memory Efficiency    | Parsing must operate within a strict memory boundary (e.g., < 10MB per request) regardless of the GPX file size. |
| Elevation Accuracy   | Must implement a smoothing algorithm to calculate a realistic  (Positive Elevation Gain).                        |
| Payload Optimization | The output JSON must contain a simplified geometry (decimated polyline) safe for browser rendering.              |

---

## Decision Drivers

* **Server Stability** — Prevent Out-Of-Memory (OOM) fatal errors in the PHP-FPM container.
* **Client Performance** — Keep the API JSON response strictly under 500KB.
* **Data Accuracy** — The fatigue algorithm (Pacing Engine) relies heavily on accurate data. If is artificially high due
  to noise, the stages will be cut too short.

---

## Considered Options

### Option A: DOM Parsing + Raw Calculation

Use `SimpleXMLElement`, extract all points into an array, calculate distances using custom Haversine PHP functions, and
return the full array to the frontend.

### Option B: External Geospatial Database (PostGIS)

Upload the GPX to a temporary PostGIS database, use SQL functions (`ST_Length`, `ST_Simplify`) to process the data, and
return the result.

### Option C: Stream Parsing (`XMLReader`) + Geospatial Library + Douglas-Peucker Decimation (Chosen)

Use PHP's native `XMLReader` for stream-based parsing (reading the file line-by-line), `mjaschen/phpgeo` for accurate
distance/bearing math, implement a moving average for elevation smoothing, and apply the **Douglas-Peucker algorithm**
to decimate the coordinates before JSON serialization.

---

## Decision Outcome

**Chosen: Option C (Stream Parsing + Data Decimation)**

### Why Other Options Were Rejected

**Option A (DOM + Raw) rejected:**

* `SimpleXMLElement` loads the entire XML tree into RAM. A 20MB GPX file can consume over 100MB of PHP memory, rendering
  the server vulnerable to DoS (Denial of Service) via large file uploads.
* Returning 20,000 points to Next.js will cripple the browser's UI thread.

**Option B (PostGIS) rejected:**

* Strictly violates ADR-001 (Stateless, database-less architecture). Adding a PostgreSQL container purely for spatial
  math introduces massive infrastructural overhead for an MVP.

---

## Implementation Strategy

### 4.1 — Stream-Based GPX Parsing

We will use PHP's `XMLReader`. It acts as a cursor going through the file node by node, meaning memory consumption
remains constant (a few kilobytes) whether the file is 1MB or 50MB.

**File:** `api/src/Spatial/GpxStreamParser.php`

```php
namespace App\Spatial;

use XMLReader;
use RuntimeException;

final class GpxStreamParser
{
    /**
     * @return iterable<array{lat: float, lon: float, ele: float}>
     */
    public function parse(string $filePath): iterable
    {
        $reader = new XMLReader();
        if (!$reader->open($filePath)) {
            throw new RuntimeException('Cannot open GPX file.');
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'trkpt') {
                $lat = (float) $reader->getAttribute('lat');
                $lon = (float) $reader->getAttribute('lon');
                $ele = 0.0;

                // Move to inner elements to find <ele>
                $node = $reader->expand();
                if ($node !== false) {
                    $eleNode = $node->getElementsByTagName('ele')->item(0);
                    if ($eleNode !== null) {
                        $ele = (float) $eleNode->nodeValue;
                    }
                }

                yield ['lat' => $lat, 'lon' => $lon, 'ele' => $ele];
            }
        }
        $reader->close();
    }
}

```

### 4.2 — Elevation Smoothing (Thresholding & Moving Average)

To prevent GPS noise (e.g., coordinates bouncing between 100m and 102m repeatedly) from inflating the , we implement a
threshold logic.

**File:** `api/src/Spatial/ElevationCalculator.php`

```php
namespace App\Spatial;

final class ElevationCalculator
{
    private const ELEVATION_THRESHOLD = 3.0; // Ignore micro-variations under 3 meters

    public function calculateTotalAscent(iterable $points): float
    {
        $totalAscent = 0.0;
        $previousEle = null;

        foreach ($points as $point) {
            if ($previousEle === null) {
                $previousEle = $point['ele'];
                continue;
            }

            $diff = $point['ele'] - $previousEle;

            // Only count if the climb exceeds the noise threshold
            if ($diff >= self::ELEVATION_THRESHOLD) {
                $totalAscent += $diff;
                $previousEle = $point['ele']; // Only update reference point if threshold met
            } elseif ($diff <= -self::ELEVATION_THRESHOLD) {
                $previousEle = $point['ele']; // Update reference for descents too
            }
        }

        return $totalAscent;
    }
}

```

### 4.3 — Data Decimation (Douglas-Peucker Algorithm)

Before sending the route to the frontend, we must reduce the number of points without losing the visual shape of the
route. We will use a PHP implementation of the Ramer-Douglas-Peucker algorithm.

*Note: For maximum reliability, we will rely on a standardized library or implement a strict DP algorithm class.*

**File:** `api/src/Spatial/RouteSimplifier.php`

```php
namespace App\Spatial;

use Location\Coordinate;
use Location\Polyline;
use Location\Formatter\Polyline\GeoJSON;

final readonly class RouteSimplifier
{
    /**
     * @param array<array{lat: float, lon: float}> $points
     * @param float $tolerance Tolerance in meters
     * @return array<array{lat: float, lon: float}>
     */
    public function simplify(array $points, float $tolerance = 20.0): array
    {
        // Conceptual implementation. In production, utilize mjaschen/phpgeo 
        // or a dedicated Douglas-Peucker PHP package.
        
        // 1. Convert array to Polyline object
        // 2. Apply simplification (removes points that deviate from the line 
        //    between two other points by less than $tolerance)
        // 3. A 20,000 point array is typically reduced to ~1,500 points 
        //    with a 20m tolerance, perfectly preserving the shape for MapLibre.
        
        return $decimatedPoints;
    }
}

```

### 4.4 — Komoot URL Ingestion

If a user provides a Komoot URL instead of a file, the backend will act as an HTTP client. Komoot exposes an
undocumented API format or allows GPX downloads by appending `.gpx` or extracting the `tour_id`. The backend will fetch
this data, write it to a temporary stream (`php://temp`), and pass it to the `GpxStreamParser` exactly as if it were an
uploaded file, unifying the pipeline.

---

## Verification

1. **Memory Profiling:** Create a PHPUnit test that ingests a 50MB GPX file. Assert via `memory_get_peak_usage(true)`
   that memory consumption remains below 5MB during the operation.
2. **Elevation Accuracy:** Ingest a known route (e.g., Alpe d'Huez GPX). Assert that the calculated is within a 5%
   margin of error compared to the official Strava/Komoot statistics.
3. **Payload Compression:** Assert that a 25,000-point array passed through `RouteSimplifier` with a 20-meter tolerance
   returns an array of fewer than 2,000 points.

---

## Consequences

### Positive

* **Uncrushable Backend:** Stream parsing guarantees the API Platform server will never run out of memory, even under
  heavy concurrent load.
* **Snappy Frontend:** By decimating the geometry, the JSON payload remains tiny. Zustand hydrates instantly, and
  MapLibre GL JS renders the line without dropping browser frame rates.
* **Unified Pipeline:** Whether the input is a `.gpx` file upload or a Komoot URL, the data is funneled through the
  exact same parsing and math engines.

### Negative

* **Algorithmic Complexity:** The Ramer-Douglas-Peucker algorithm can be CPU-intensive (O(n²) in worst-case scenarios,
  though usually O(n log n)). If performance degrades, it may require a PHP C-extension (like GEOS) in future lots.

### Neutral

* The original, un-decimated GPX data is lost. The exported JSON from Next.js will only contain the simplified geometry.
  For the scope of a Roadbook/Planning tool, this is perfectly acceptable, but users cannot use Bike Trip Planner to "clean and
  re-export" high-fidelity GPS traces for Garmin devices.

---

## Sources

* [PHP Official Documentation: XMLReader](https://www.php.net/manual/en/book.xmlreader.php)
* [Wikipedia: Ramer–Douglas–Peucker algorithm](https://en.wikipedia.org/wiki/Ramer%E2%80%93Douglas%E2%80%93Peucker_algorithm)
* [phpgeo - A simple Geo Library for PHP](https://github.com/mjaschen/phpgeo)

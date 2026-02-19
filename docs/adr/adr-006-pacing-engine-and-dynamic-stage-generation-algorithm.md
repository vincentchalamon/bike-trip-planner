# ADR-006: Pacing Engine and Dynamic Stage Generation Algorithm

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The core value proposition of Bike Trip Planner is its intelligent "Pacing Engine." When a user imports a continuous 300km GPX
track and specifies a 3-day trip, the application must automatically split the route into three logical daily stages.

This split cannot be a simple mathematical division () because bikepacking introduces two major physiological
constraints:

1. **Cumulative Fatigue:** A cyclist's maximum daily capacity decreases day after day.
2. **Elevation Penalty:** Climbing requires significantly more energy than riding on flat terrain.

The business rule for the target distance of day  () is defined by the following formula:

Where:

* is the theoretical average distance ().
* represents a 10% fatigue degradation per day.
* is the positive elevation gain (in meters) for that specific day.
* The division by 50 applies a penalty (e.g., 500m of climbing reduces the target distance by 10km).

**The Architectural Problem:**  is unknown until the algorithm actually traverses the polyline for that specific day.
The target distance is therefore a *moving target* that must be dynamically recalculated as the algorithm steps through
the geographical coordinates. We must design an algorithm in PHP 8.5 that is efficient, deterministic, and highly
testable.

### Architectural Requirements

| Requirement        | Description                                                                                                |
|--------------------|------------------------------------------------------------------------------------------------------------|
| Determinism        | The algorithm must yield the exact same stages for the same GPX track and day count every time.            |
| Performance        | The loop must execute in under 100ms in PHP to ensure the API responds instantly.                          |
| Minimum Thresholds | A stage cannot be reduced to less than 30km, regardless of the elevation penalty, to prevent micro-stages. |
| State Isolation    | The logic must be decoupled from the API layer to allow pure unit testing.                                 |

---

## Decision Drivers

* **Execution Speed** — Avoiding O(n²) complexity when iterating over thousands of GPS coordinates.
* **Accuracy** — The split points must perfectly align with actual coordinates on the provided GPX polyline to ensure
  the Next.js frontend can draw the map correctly.
* **Testability** — Complex mathematical engines are prone to edge-case bugs (e.g., what happens if the remaining
  distance on the last day is only 5km?). The algorithm must be easily mockable.

---

## Considered Options

### Option A: Pre-calculated Split (Naive Approach)

Divide the polyline into equal distances first, calculate the elevation of those chunks *afterward*, and then attempt to
shift the split points backward or forward to balance the fatigue.

* *Pros:* Extremely fast to compute.
* *Cons:* Highly inaccurate. Shifting the endpoints creates a cascading effect that usually breaks the fatigue formula
  for the subsequent days.

### Option B: Machine Learning / Clustering

Use a heuristic or clustering algorithm (like K-Means weighted by elevation) to find "natural" resting points.

* *Pros:* Could theoretically find nearby cities automatically.
* *Cons:* Non-deterministic, impossible to unit test reliably, and massive overkill for an MVP.

### Option C: Deterministic Weighted Polyline Traversal (Chosen)

A forward-stepping greedy algorithm. It calculates the theoretical base target, walks the decimated polyline
point-by-point, dynamically shrinks the day's target distance as elevation is accumulated, and slices the stage the
moment the accumulated distance exceeds the dynamic target.

---

## Decision Outcome

**Chosen: Option C (Deterministic Weighted Polyline Traversal)**

### Why Other Options Were Rejected

Option C is the only approach that mathematically respects the physiological formula in a single O(n) pass. By utilizing
the *decimated* polyline (as decided in ADR-004), the array of coordinates to traverse is reduced from ~25,000 to ~
1,500, making a point-by-point traversal in PHP 8.5 virtually instantaneous.

---

## Implementation Strategy

### 6.1 — The Algorithm Design

The `PacingEngine` will be implemented as a stateless Symfony service. It accepts an array of decimated coordinates and
the requested number of days, returning an array of `Stage` DTOs.

**File:** `api/src/Pacing/PacingEngine.php`

```php
namespace App\Pacing;

use App\ApiResource\Stage;
use Location\Coordinate;
use Location\Distance\Vincenty;

final class PacingEngine
{
    private const MINIMUM_STAGE_DISTANCE_KM = 30.0;
    private const FATIGUE_FACTOR = 0.9;
    private const ELEVATION_PENALTY_RATIO = 50.0; // 1km penalty per 50m D+

    public function generateStages(array $decimatedPoints, int $numberOfDays, float $totalDistanceKm): array
    {
        $stages = [];
        $baseTargetKm = $totalDistanceKm / $numberOfDays;
        
        $currentDay = 1;
        $accumulatedDistance = 0.0;
        $accumulatedElevation = 0.0;
        $stageStartIdx = 0;
        $previousPoint = null;
        
        $calculator = new Vincenty(); // High precision Haversine alternative

        foreach ($decimatedPoints as $idx => $point) {
            if ($previousPoint !== null) {
                $coord1 = new Coordinate($previousPoint['lat'], $previousPoint['lon']);
                $coord2 = new Coordinate($point['lat'], $point['lon']);
                
                // Add distance (convert meters to km)
                $accumulatedDistance += $calculator->getDistance($coord1, $coord2) / 1000;
                
                // Add thresholded elevation (assuming it's already smoothed per ADR-004)
                if ($point['ele'] > $previousPoint['ele']) {
                    $accumulatedElevation += ($point['ele'] - $previousPoint['ele']);
                }
            }

            // Calculate the moving target for the current day
            $fatigueDegradation = $baseTargetKm * (self::FATIGUE_FACTOR ** ($currentDay - 1));
            $elevationPenalty = $accumulatedElevation / self::ELEVATION_PENALTY_RATIO;
            
            $dynamicTargetKm = max(
                self::MINIMUM_STAGE_DISTANCE_KM, 
                $fatigueDegradation - $elevationPenalty
            );

            // Check if we hit the target OR if it's the absolute last point of the GPX
            $isLastPoint = $idx === array_key_last($decimatedPoints);
            
            if ($accumulatedDistance >= $dynamicTargetKm || $isLastPoint) {
                // Slice the array to get the geometry for this specific stage
                $stageGeometry = array_slice($decimatedPoints, $stageStartIdx, $idx - $stageStartIdx + 1);
                
                $stages[] = new Stage(
                    dayNumber: $currentDay,
                    distance: $accumulatedDistance,
                    elevation: $accumulatedElevation,
                    startPoint: $stageGeometry[0],
                    endPoint: $stageGeometry[array_key_last($stageGeometry)]
                );

                // Reset counters for the next day
                $currentDay++;
                $accumulatedDistance = 0.0;
                $accumulatedElevation = 0.0;
                $stageStartIdx = $idx;
                
                // Break early if we reached the requested number of days 
                // (The rest of the track will be bundled into the final day)
                if ($currentDay > $numberOfDays && !$isLastPoint) {
                    $this->bundleRemainingTrack($stages, $decimatedPoints, $idx);
                    break;
                }
            }
            
            $previousPoint = $point;
        }

        return $stages;
    }
    
    private function bundleRemainingTrack(array &$stages, array $points, int $currentIndex): void
    {
        // Logic to append the remaining distance to the final stage 
        // to ensure the entire GPX is covered even if the math falls short.
    }
}

```

### 6.2 — Edge Case Management (The "Reliquat")

Because the target dynamically shrinks, the sum of all dynamic targets will often be *less* than the total distance of
the GPX track. The algorithm handles this via the `bundleRemainingTrack` method.

If the algorithm reaches the final requested day but still has 15km of polyline left, it forces those remaining
kilometers into the final stage, ensuring the user actually reaches their destination. If the remaining distance is
excessively large (> 40km), a warning flag is added to the `TripResponse` DTO advising the user to add an extra day to
their trip.

---

## Verification

1. **Pure Unit Testing:** The `PacingEngine` must be tested using PHPUnit without booting the Symfony Kernel.
2. **Flat Terrain Test:** Pass a mock array of points representing a perfectly flat 300km straight line. Assert that Day
   1 = 110.8km, Day 2 = 99.7km, Day 3 = 89.5km.
3. **Mountain Terrain Test:** Pass a mock array of 300km where the first 100km contains 2000m of . Assert that Day 1 is
   aggressively cut short (e.g., 70km) and the remaining distance is successfully rolled over to Days 2 and 3.
4. **Minimum Threshold Test:** Pass an extreme scenario (e.g., 5000m in 50km). Assert that the stage never terminates
   before accumulating exactly 30.0km.

---

## Consequences

### Positive

* **Physiologically Accurate:** It is one of the only routing algorithms that treats elevation as a direct distance
  penalty in real-time, greatly enhancing the safety and planning accuracy for bikepackers.
* **O(n) Complexity:** The algorithm iterates through the point array exactly once, keeping CPU overhead minimal.
* **Highly Testable:** Because it relies entirely on basic math and array traversal without any external dependencies or
  database queries, it is trivial to test every edge case.

### Negative

* **Rigid Endpoints:** The algorithm splits the track exactly where the math dictates. This point might be in the middle
  of a forest, 5km away from the nearest town or TER station. (Note: ADR-005 resolves this by scanning for POIs *around*
  this endpoint, but the endpoint itself is not magnetically snapped to a city in Lot 1).

### Neutral

* Modifying the `FATIGUE_FACTOR` (currently 0.9) drastically alters the entire shape of the trip. In Lot 2, this
  constant should be exposed to the frontend UI as an "Experience Level" slider (e.g., Beginner = 0.8, Expert = 1.0).

# ADR-013: Accommodation Discovery and Heuristic Pricing Strategy

**Status:** Accepted — Extended by ADR-026

> **Note (Sprint 20):** This ADR describes the initial OSM-only accommodation discovery strategy. Sprint 20 extended it with a multi-source architecture: DataTourisme is now a complementary source for accommodations (gîtes d'étape, auberges routières) and cultural POIs, and Wikidata provides cross-cutting enrichment via Q-IDs. The interface registry pattern (`AccommodationSourceInterface`, `#[AutowireIterator]`) was introduced to abstract source origin from consumers. See [ADR-026: Multi-Source Data Integration](adr-026-multi-source-data-integration.md) for the full decision and consequences.

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Lot 1 requires Bike Trip Planner to provide "smart suggestions for accommodations near the arrival location (campsites, hostels,
bike-friendly hotels)" and "automatically detect prices for the selected dates."

Because Bike Trip Planner relies on a stateless API Platform backend (ADR-001) and must respond to the frontend in under 2
seconds, we cannot perform live web scraping (e.g., using Puppeteer to scrape Booking.com). Scraping is slow, highly
brittle to DOM changes, and will quickly result in the server's IP address being blacklisted by anti-bot protections (
like Cloudflare or Datadome).

Furthermore, obtaining official API access to major aggregators (Expedia, Booking.com) requires a registered business
entity, prolonged approval processes, and strict PCI-DSS compliance, which is out of scope for an MVP.

We must define a strategy to discover relevant accommodations, estimate their prices, and allow the user to book them
without relying on fragile scraping or heavy B2B APIs.

### Architectural Requirements

| Requirement            | Description                                                                                                           |
|------------------------|-----------------------------------------------------------------------------------------------------------------------|
| Speed & Reliability    | Accommodation data must be retrieved in milliseconds without relying on third-party commercial APIs.                  |
| Pricing Estimation     | The system must provide a realistic pricing bracket based on the accommodation type and region.                       |
| Actionability          | The user must be able to click a link to instantly view live availability and exact pricing for their specific dates. |
| Legal & ToS Compliance | The architecture must not violate the Terms of Service of major booking platforms.                                    |

---

## Decision Drivers

* **Server IP Reputation** — Avoid any architectural pattern that behaves like a malicious bot.
* **Data Autonomy** — Maximize the use of open data (OpenStreetMap) which we are already querying and caching (ADR-005).
* **User Experience (UX)** — Bikepackers need rough budgeting during the planning phase, and a seamless transition to a
  booking platform when they are ready to commit.

---

## Considered Options

### Option A: Live Web Scraping (Puppeteer / Goutte)

Deploy a headless browser in the backend to scrape search results from popular booking sites based on the stage's
coordinates and dates.

* *Cons:* Extremely slow (adds ~5-10 seconds to the API response). Highly illegal/violates ToS. Will fail constantly due
  to captchas.

### Option B: Commercial APIs (Amadeus / RapidAPI)

Use a paid aggregator API to fetch live hotel data.

* *Cons:* Adds recurring financial costs to an MVP. Commercial APIs rarely have good coverage for small municipal
  campsites, which are the primary target for bikepackers.

### Option C: OSM Discovery + Heuristic Pricing + Parameterized Deep Linking (Chosen)

1. Use the existing OpenStreetMap (Overpass) infrastructure to discover local campsites and hotels.
2. Apply a PHP-based heuristic engine to estimate the price.
3. Generate parameterized "Deep Links" in the Next.js frontend to redirect the user to a booking platform with the dates
   and coordinates pre-filled.

---

## Decision Outcome

**Chosen: Option C (OSM Discovery + Heuristic Pricing + Deep Linking)**

### Why Other Options Were Rejected

Options A and B introduce unacceptable financial, legal, and operational risks for a Lot 1 MVP. Option C relies on data
we already own or can cache (OSM) and pushes the final "live pricing" resolution to the client's browser via deep links,
completely bypassing bot protections and server load.

---

## Implementation Strategy

### 13.1 — Accommodation Discovery (OSM Overpass)

We will extend the `OverpassQueryBuilder` (from ADR-005) to search for accommodations within a 5km radius of the stage's
exact endpoint. We prioritize places tagged with bicycle infrastructure.

**File:** `api/src/Osm/OverpassQueryBuilder.php`

```php
// Inside the buildPolylineQuery or a dedicated buildEndpointQuery method:
$query = <<<QL
[out:json][timeout:10];
(
  // Prioritize "Accueil Vélo" or generic bicycle=yes
  node(around:5000, {$lat}, {$lon})["tourism"="camp_site"];
  node(around:5000, {$lat}, {$lon})["tourism"="hostel"];
  node(around:5000, {$lat}, {$lon})["tourism"="hotel"]["bicycle"="yes"];
);
out body;
>;
out skel qt;
QL;
```

### 13.2 — The Heuristic Pricing Engine

Instead of fetching live prices, the PHP backend applies a baseline heuristic model. Prices in France for bikepackers (1
person, 1 small tent or 1 basic room) are generally predictable.

**File:** `api/src/Pricing/PricingHeuristicEngine.php`

```php
namespace App\Pricing;

use App\ApiResource\Model\Accommodation;

final class PricingHeuristicEngine
{
    /**
     * Estimates the price bracket based on OSM tags and accommodation type.
     */
    public function estimatePrice(array $osmNode): array
    {
        $type = $osmNode['tags']['tourism'] ?? 'unknown';
        $stars = (int) ($osmNode['tags']['stars'] ?? 0);
        
        // If OSM provides an exact fee tag, use it
        if (isset($osmNode['tags']['charge'])) {
            return ['min' => (float) $osmNode['tags']['charge'], 'max' => (float) $osmNode['tags']['charge'], 'exact' => true];
        }

        // Heuristic fallback for France (2026 baseline estimates)
        return match ($type) {
            'camp_site' => [
                'min' => $stars > 2 ? 15.0 : 8.0,
                'max' => $stars > 2 ? 25.0 : 15.0,
                'exact' => false
            ],
            'hostel' => ['min' => 20.0, 'max' => 35.0, 'exact' => false],
            'hotel' => [
                'min' => $stars > 2 ? 70.0 : 50.0,
                'max' => $stars > 2 ? 120.0 : 80.0,
                'exact' => false
            ],
            default => ['min' => 30.0, 'max' => 60.0, 'exact' => false],
        };
    }
}
```

### 13.3 — The OpenAPI Contract

The DTO incorporates this estimated bracket, allowing the frontend to display a budget range (e.g.,
`Est. 15€ - 25€ / night`).

**File:** `api/src/ApiResource/Model/Accommodation.php`

```php
namespace App\ApiResource\Model;

final class Accommodation
{
    public function __construct(
        public readonly string $name,
        public readonly string $type, // 'camp_site', 'hotel'
        public readonly float $lat,
        public readonly float $lon,
        public readonly float $estimatedPriceMin,
        public readonly float $estimatedPriceMax,
        public readonly bool $isExactPrice
    ) {}
}
```

### 13.4 — Frontend Parameterized Deep Linking

The Next.js frontend takes the accommodation's coordinates and the specific stage date to generate a "Check Live
Availability" button. This offloads the heavy lifting to the user's browser and the booking platform's servers.

**File:** `pwa/src/lib/deepLinkGenerator.ts`

```typescript
import dayjs from 'dayjs';

/**
 * Generates a Booking.com URL targeting a specific coordinate and date.
 */
export const generateBookingLink = (lat: number, lon: number, checkinDate: string): string => {
    const checkin = dayjs(checkinDate).format('YYYY-MM-DD');
    const checkout = dayjs(checkinDate).add(1, 'day').format('YYYY-MM-DD');

    // Creates a parameterized URL searching near the exact latitude/longitude
    const baseUrl = 'https://www.booking.com/searchresults.html';
    const params = new URLSearchParams({
        dest_type: 'latlong',
        dest_id: `${lat},${lon}`,
        checkin: checkin,
        checkout: checkout,
        group_adults: '1',
        no_rooms: '1',
        req_bike_parking: '1' // Booking.com hidden parameter for bike friendliness
    });

    return `${baseUrl}?${params.toString()}`;
};
```

---

## Verification

1. **Heuristic Engine Unit Test:** Verify that `PricingHeuristicEngine::estimatePrice` correctly assigns a 15€-25€
   bracket to a 3-star campsite and correctly parses an explicit `charge=12 EUR` OSM tag if present.
2. **Deep Link Integrity:** In the Next.js frontend, write a Jest/Vitest test to ensure `generateBookingLink` correctly
   formats the date strings (handling leap years or month rollovers securely) and outputs a valid URL object.
3. **Playwright E2E:** Click the "Check Live Availability" button on an accommodation card. Assert that the resulting
   new browser tab navigates to a URL containing the correct `checkin` and `checkout` query parameters corresponding to
   that specific stage's day.

---

## Consequences

### Positive

* **Zero API Costs:** Accommodations are sourced entirely from free, open data (OSM).
* **Infinite Scalability:** The backend does absolutely no scraping, keeping API response times consistently under
  500ms.
* **Affiliate Revenue Potential:** The parameterized deep links can easily be prepended with affiliate tracking IDs (
  e.g., Booking.com Affiliate Partner program) in Lot 2, creating a monetization vector for the application without
  altering the architecture.

### Negative

* **Lack of Native Booking:** The user leaves the Bike Trip Planner application to finalize their accommodation.
* **Price Inaccuracy:** The heuristic model is an estimate. During peak events (e.g., Tour de France passing through a
  town), actual prices will severely deviate from the baseline heuristic.

### Neutral

* The system relies on the density of OpenStreetMap data. In highly rural areas where hotels are not mapped on OSM, the
  application will not suggest anything, relying on the user to use the generated broad "Area Search" deep link instead.

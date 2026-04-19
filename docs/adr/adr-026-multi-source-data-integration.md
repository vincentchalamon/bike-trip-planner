# ADR-026: Multi-Source Data Integration

- **Status:** Accepted
- **Date:** 2026-04-18
- **Depends on:** ADR-005 (External API caching), ADR-012 (Alert engine), ADR-013 (Accommodation discovery), ADR-022 (Persistent storage)
- **Extends:** ADR-013 (adds DataTourisme and Wikidata as complementary sources)

## Context and Problem Statement

OpenStreetMap provides a reliable baseline for geographic data (roads, bike infrastructure, water points, basic POIs). However, several categories of information are systematically under-represented in OSM for itinerant cyclists in France:

| Gap | OSM limitation |
|-----|---------------|
| **Bikepacker-friendly accommodation** | Gîtes d'étape and auberges routières rarely carry `backpack=yes` or structured bike tags in OSM |
| **Cultural POIs without opening hours** | Many châteaux, abbeys, and museums are mapped but lack `opening_hours`, `fee`, or multilingual descriptions |
| **Dated events** | OSM does not model time-bound events (festivals, exhibitions, fairs) |
| **Weekly markets** | Market data exists on `data.gouv.fr` but is rarely reflected in OSM |

Three open data sources are available to address these gaps without proprietary API dependencies:

- **DataTourisme** — the French national tourism data aggregator (Ministry of Tourism), covering accommodations, cultural POIs, and dated events with structured JSON-LD. Published under Licence Ouverte 2.0 (Etalab). Available via a free-registration REST API.
- **Wikidata** — the structured knowledge base of the Wikimedia Foundation. Q-ID references appear on OSM objects (`wikidata=Q12345`) and in DataTourisme payloads (`owl:sameAs`). Published under CC0. No registration required.
- **data.gouv.fr** — the French open data portal. The "Marchés forains et brocantes" dataset provides geocoded weekly market data with day-of-week and time slots. Published under Licence Ouverte 2.0.

## Decision Drivers

- **Coverage** — Dated events and weekly markets cannot be sourced from OSM alone.
- **Legal compliance** — All sources must be open-licensed and permit attribution-free or low-burden attribution.
- **Operational cost** — Sources must be either free or offer sufficient quota for the application's usage pattern.
- **Architecture consistency** — New sources must plug into the existing alert and enrichment pipelines without requiring a global refactor.
- **Graceful degradation** — The application must remain fully functional when any optional source is unavailable or unconfigured.

---

## Considered Options

### Option A: Scrape RandoCamping.fr

Parse HTML from RandoCamping.fr to extract bikepacker-oriented accommodation listings.

**Rejected.** RandoCamping's terms of service explicitly prohibit automated scraping. Blocked by anti-bot protections (Cloudflare). Technically fragile to DOM changes. Legally untenable.

### Option B: OSM only

Restrict all data to OpenStreetMap. Accept the gaps as known limitations.

**Rejected.** This option leaves the "dated events" use case entirely unaddressed — OSM does not model events. The accommodation gap means bikepackers will miss gîtes d'étape that are the most common overnight stop in France.

### Option C: Duplicate DataTourisme auth per scanner

Add DataTourisme credentials to each scanner class that needs POI or accommodation data, creating N independent HTTP clients.

**Rejected.** Violates DRY. Rate limiting (1 000 req/h) must be enforced at a single point. Auth rotation or key changes would require N code modifications.

### Option D: Multi-source architecture with interface registries and a single DataTourisme client (chosen)

Introduce `AccommodationSourceInterface` and `CulturalPoiSourceInterface` to abstract data origin from consumers. Implement OSM and DataTourisme sources behind each interface, auto-discovered via `#[AutowireIterator]`. A single `DataTourismeClient` handles auth, rate limiting, and caching for all DataTourisme consumers. Wikidata enrichment runs as a cross-cutting batch pass after primary source data is collected.

---

## Decision Outcome

**Chosen: Option D — multi-source architecture with interface registries.**

### Source roles

| Source | Role | Coverage | Licence | Prerequisite |
|--------|------|----------|---------|-------------|
| **OpenStreetMap** | Primary source for all geographic data, bike infrastructure, water points, bike shops, resupply POIs | Global | ODbL | None |
| **DataTourisme** | Complementary source for accommodations and cultural POIs; exclusive source for dated events (festivals, exhibitions, fairs) | France | Licence Ouverte 2.0 | `DATATOURISME_API_KEY` |
| **Wikidata** | Cross-cutting enricher: adds multilingual descriptions, images, Wikipedia links, and structured opening hours to any object carrying a Q-ID | Europe | CC0 | None (optional `WIKIDATA_USER_AGENT`) |
| **data.gouv.fr** | Source for recurring weekly markets (import only — not a live API) | France | Licence Ouverte 2.0 | `make markets-import` |

### Architecture

```text
AccommodationSourceInterface          CulturalPoiSourceInterface
  ├── OsmAccommodationSource            ├── OsmCulturalPoiSource
  └── DataTourismeAccommodationSource   └── DataTourismeCulturalPoiSource
         │                                       │
         └──────────────┬────────────────────────┘
                        │
               DataTourismeClient
               (single instance, rate-limited, Redis-cached)
                        │
               WikidataEnricher  ← batch Q-ID resolution after primary collection
                        │
               MarketRepository  ← PostgreSQL table populated by CLI import
```

**Registry pattern:** each interface is consumed via `#[AutowireIterator]` — new sources implement the interface and are discovered automatically without modifying existing consumers.

**DataTourisme client** (`DataTourismeClientInterface`): single HTTP client scoped to `datatourisme.fr`, rate-limited at 1 000 req/h via Symfony Rate Limiter (`fixed_window` policy), responses cached in a dedicated `cache.datatourisme` Redis pool (TTL 24h).

**Wikidata enricher** (`WikidataEnricherInterface`): batch SPARQL queries via the public Wikidata endpoint. Results cached in `cache.wikidata` Redis pool (TTL 7 days). Errors (timeout, 5xx) are silently swallowed — the application continues without enrichment.

**Market import** (`app:markets:import` CLI command): downloads the `data.gouv.fr` market CSV, geocodes entries, and inserts them into the `market` PostgreSQL table. Not a live API — no rate limiting or auth required.

### Consequences

#### Positive

- **Dated events now supported** — The first alert rule covering cultural/social events around stage endpoints is enabled by DataTourisme.
- **Richer accommodation data** — Gîtes d'étape and accommodation types absent from OSM are now discoverable.
- **Multilingual enrichment** — Wikidata Q-IDs unlock descriptions, images, and Wikipedia links in FR/EN/DE/ES/IT without per-source effort.
- **Weekly markets** — A recurring event type (day-of-week, time slot) is covered without requiring a live API call per trip computation.
- **Interface abstraction** — Adding a new source (e.g., regional tourism APIs) requires only a new class implementing the relevant interface.

#### Negative

- **New Redis pools** — `cache.datatourisme` and `cache.wikidata` add two named pools to the Redis configuration. Memory quota monitoring is required.
- **New PostgreSQL table** — The `market` table must be provisioned and kept fresh via periodic `make markets-import` runs.
- **DataTourisme quota** — 1 000 req/h requires monitoring. A single trip computation may consume up to ~20 requests (one per stage × two queries: events + POIs).
- **Multi-source attribution required in the UI** — ODbL (OSM), Licence Ouverte 2.0 (DataTourisme, data.gouv.fr), and CC0 (Wikidata) must all be credited in the application footer (see F.4 implementation).

#### Neutral

- DataTourisme is opt-in: `DATATOURISME_ENABLED=false` (the default) skips all DataTourisme queries and falls back to OSM only. The application is fully functional without a DataTourisme API key.
- Wikidata is always enabled but degrades silently on errors — it is never a blocking dependency.
- The `market` table is populated independently of trip computation — a missing or empty table results in no market events, not an error.

---

## Sources

- [DataTourisme — Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence)
- [Wikidata — CC0](https://creativecommons.org/publicdomain/zero/1.0/)
- [data.gouv.fr — Marchés forains dataset](https://www.data.gouv.fr/)
- [ADR-005: Orchestration, Optimization, and Caching of External APIs](adr-005-orchestration-optimization-and-caching-of-external-apis.md)
- [ADR-013: Accommodation Discovery and Heuristic Pricing Strategy](adr-013-accomodation-discovery-and-heuristic-pricing-strategy.md)
- [ADR-022: Persistent Storage Strategy](adr-022-persistent-storage-strategy.md)

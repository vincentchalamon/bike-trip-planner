<h1 align="center">Bike Trip Planner</h1>

<p align="center">
  <strong>Plan your bikepacking adventures with confidence.</strong>
</p>

<p align="center">
  Paste a Komoot URL or upload a GPX file, and get a structured day-by-day roadbook<br />
  with smart pacing, safety alerts, and accommodation suggestions.
</p>

<p align="center">
  <a href="https://github.com/vincentchalamon/bike-trip-planner/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue.svg" alt="License" /></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white" alt="PHP 8.5" />
  <img src="https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white" alt="Symfony 8" />
  <img src="https://img.shields.io/badge/Next.js-16-000000?logo=next.js&logoColor=white" alt="Next.js 16" />
  <img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black" alt="React 19" />
  <img src="https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white" alt="TypeScript" />
  <img src="https://img.shields.io/badge/API%20Platform-4.3-38B2AC?logo=api-platform&logoColor=white" alt="API Platform 4.3" />
  <img src="https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white" alt="Docker" />
</p>

---

## Screenshots

> **Desktop** — Split view with day-by-day timeline, contextual alerts, and interactive map.

![Desktop - Split view](docs/assets/screenshots/desktop-split-view.png)

> **Mobile** — Responsive timeline with weather, difficulty badge, and supply points.

<p align="center">
  <img src="docs/assets/screenshots/mobile-timeline.png" alt="Mobile - Timeline" width="300" />
</p>

---

## Features

**Import your route in seconds** — Paste a link from Komoot, Strava, or RideWithGPS, or upload a GPX file directly. The backend fetches, parses, and processes everything asynchronously.

**Smart pacing engine** — Automatically distributes distance across days, accounting for cumulative fatigue and elevation gain. Configurable daily targets with a safety minimum threshold.

**20+ safety & comfort alerts** — A rule-based alert engine analyzes each stage for steep gradients, dangerous traffic, headwinds, surface quality, e-bike range, sunset timing, resupply gaps, and more — with three severity levels (critical, warning, nudge).

**Accommodation finder** — Discovers bivouac spots, refuges, and gites near each stage endpoint via OpenStreetMap, with heuristic pricing estimates.

**Cultural points of interest** — Detects museums, monuments, castles, viewpoints, and other attractions along the route with an "add to itinerary" action.

**Real-time processing** — Async workers compute your trip in parallel; live status updates stream to the browser via Mercure SSE. No page reload needed.

**AI trip analysis (optional, bring-your-own token)** — Off by default and fully opt-in. Enable it in your account settings by choosing a provider — Anthropic (Claude), Google (Gemini), or OpenAI — and pasting your own API key. Your key powers per-stage and whole-trip summaries and a context-aware chat assistant, including an in-ride mode that surfaces nearby points of interest. The key is encrypted at rest and never returned by the API. When AI is on, trip data (route, towns, dates) is sent to your chosen provider with your own key and billed to your account; nothing leaves to a third party unless you opt in. It degrades gracefully: with no key, a bad key, a quota wall, or a provider outage, the rule-based alerts stay fully visible.

**Multi-format export** — Export enriched GPX files with waypoints for accommodation, water points, and POIs — ready for your GPS device. Download per-stage FIT files for Garmin, or generate a text roadbook summary.

**Your account, your data** — Passwordless magic-link sign-in. Export all your data as JSON or irreversibly delete your account at any time. Privacy-friendly, cookieless analytics (self-hosted Plausible) — no third-party trackers.

---

## Supported route sources

| Platform | Supported URL formats |
|---|---|
| **Komoot** | `komoot.com/[xx-xx/]tour/123` and `komoot.com/[xx-xx/]collection/123` |
| **Strava** | `strava.com/routes/123` |
| **RideWithGPS** | `ridewithgps.com/routes/123` |
| **GPX upload** | Direct file upload (up to 30 MB) |

---

## Supported OSM accommodation tags

| Logical type | OSM query | Pricing heuristic |
|---|---|---|
| `hotel` | `tourism=hotel` | €50–€120 |
| `motel` | `tourism=motel` | €45–€90 |
| `guest_house` | `tourism=guest_house` | €40–€80 |
| `chalet` | `tourism=chalet` | €30–€70 |
| `hostel` | `tourism=hostel` | €20–€35 |
| `alpine_hut` | `tourism=alpine_hut` | €25–€45 |
| `camp_site` | `tourism=camp_site` | €8–€25 (€8–€15 if `backpack=yes` or `tents=yes`) |
| `wilderness_hut` | `tourism=wilderness_hut` | free / donation (€0–€10) |
| `shelter` | `amenity=shelter` + `shelter_type~basic_hut\|weather_shelter\|lean_to` | free (€0) |

---

## Quick start

```bash
git clone https://github.com/vincentchalamon/bike-trip-planner.git
cd bike-trip-planner
make start-dev
```

The app is available at:

- **<https://localhost>** — Web application
- **<https://localhost/docs>** — API documentation (Swagger UI)

See [Getting Started](docs/getting-started.md) for prerequisites and detailed setup instructions.

---

## Alert engine

The backend runs a pipeline of analyzers on each stage. Three severity levels are used:

| Level | Badge | Description |
|-------|-------|-------------|
| `critical` | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Blocking issue requiring immediate attention |
| `warning` | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Significant issue to watch |
| `nudge` | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Informational suggestion |

Rules are executed in priority order (lower = higher priority):

| Rule | Priority | Severity | Trigger |
|------|----------|----------|---------|
| **Continuity** | 5 | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Gap > 500 m between consecutive stages |
| **Continuity** | 5 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Gap 100-500 m between stages |
| **Elevation** | 10 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Elevation gain > 1 200 m on a stage |
| **Steep gradient** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Sustained >= 8 % gradient over >= 500 m |
| **Surface** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Unpaved section >= 500 m (gravel, dirt, mud, grass, sand...) |
| **Surface** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | OSM surface data missing on >= 30 % of ways |
| **Traffic** | 20 | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Primary/trunk road without cycle infrastructure >= 500 m |
| **Traffic** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Secondary road, no cycleway, speed limit > 50 km/h |
| **Traffic** | 20 | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Secondary road, speed limit <= 50 km/h |
| **E-bike range** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Day distance > effective range (80 km - elevation / 25); action navigates to the nearest charging station within 2 km, else suggests shortening the stage |
| **Sunset** | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Estimated arrival time exceeds civil twilight end at stage end point |
| **Calendar** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage falls on a French public holiday |
| **Calendar** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage falls on a Sunday (businesses may be closed) |
| **Wind** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Headwind >= 25 km/h on >= 60 % of stages with weather data |
| **Comfort** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Poor comfort index (< 40/100) on at least one stage |
| **Bike shops** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No repair resource within 2 km of stage midpoint (trips > 5 stages) |
| **Bike shops** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Nearby shop sells bikes but does not offer repair service |
| **Resupply** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage >= 40 km with no food/resupply POI along the route |
| **Resupply** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | All resupply POIs on the stage are closed at estimated passage time |
| **Accommodation** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | All detected accommodations on the stage are likely closed due to seasonality |
| **Water points** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stretch > 30 km without a detected drinking water source |
| **Rest day** | 100 | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Every N consecutive cycling days without a rest day (default: every 3 days), except on the trip's last day |
| **Cultural POI** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Museum, monument, castle, church, viewpoint, or attraction within 500 m of route — enriched with opening hours, price and description when sourced from DataTourisme |
| **Railway station** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No train station within 10 km of a stage endpoint (emergency evacuation) |
| **Health services** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No pharmacy, hospital, or clinic within 15 km of a stage |
| **Border crossing** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Route crosses an international border (country change detected via the local PostGIS admin-boundary index) |
| **Ferry** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Stage takes a ferry crossing (route runs within 100 m of an `osm.ferries` line; check schedule/booking) |
| **Ford** | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage crosses a ford (`osm.fords` within 25 m of the route), dry weather |
| **Ford** | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Stage crosses a ford and rain is forecast (precipitation probability >= 50 %): possibly impassable in high water |

**Terrain rules** (Continuity, Elevation, Steep gradient, Surface, Traffic, E-bike range, Sunset, Rest day) implement `StageAnalyzerInterface` and are auto-discovered via `#[AutoconfigureTag('app.stage_analyzer')]`. Rules with `--` priority (Calendar, Wind + Comfort, Bike shops, Resupply, Accommodation, Water points, Cultural POI, Railway station, Health services, Border crossing, Ferry, Ford) are separate async Symfony Message handlers; Comfort is co-located with Wind inside `AnalyzeWindHandler`. Ford runs after the weather computation (its severity depends on the per-stage forecast).

---

## Architecture overview

<!-- markdownlint-disable MD040 -->
```
Browser (Next.js 16)           PHP Backend (API Platform 4.3)
  Zustand + Immer (in-memory)    Stateless computation
  Zod validation                 GPX parsing + pacing engine
  openapi-fetch (typed)          Local PostGIS reference index + weather
  Mercure SSE (real-time)  <--   Async workers (Symfony Messenger)
                                 Redis cache + Mercure publisher
```

The frontend sends a trip request via REST; the backend processes it asynchronously across multiple workers and pushes status updates via Mercure SSE. PostgreSQL 18 persists trip configuration and stages; Redis handles transient computation state, Messenger transport, and external API caches.

Type safety is enforced end-to-end: PHP DTOs define the schema -> API Platform exports an OpenAPI spec -> `npm run typegen` generates TypeScript types -> `openapi-fetch` provides type-safe API calls. A schema change on the backend intentionally causes a TypeScript compilation failure.

---

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.5, Symfony 8, API Platform 4.3, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| State | Zustand + Immer (in-memory), Mercure SSE (real-time) |
| Styling | Tailwind CSS |
| Testing | PHPUnit 13 (backend), Playwright 1.58 (E2E) |
| Quality | PHPStan level 9, PHP-CS-Fixer, ESLint, Prettier |
| Async | Symfony Messenger, Redis transport, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, PostgreSQL, Node) |

---

## Documentation

| Document | Description |
|---|---|
| [Documentation index](docs/README.md) | Find docs by what you need: learn, do, look up, understand |
| [Getting Started](docs/getting-started.md) | Requirements, installation, and local setup |
| [Contributing](docs/contributing.md) | Development workflow, standards, and tooling |
| [Deployment](docs/deployment.md) | CI/CD pipeline, required secrets, rollback procedure |
| [Architecture Decisions](docs/adr/) | 35 ADRs explaining every major technical choice |
| [Runbooks](docs/runbooks/) | On-call playbooks: workers, DB, Redis, Mercure, releases |
| [Claude Code Tooling](docs/claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |
| [Architecture](docs/architecture.md) | System overview and the reasoning behind the ADRs |
| [Legal & Licensing](docs/legal-and-licensing.md) | Project licence, data attribution, and GDPR posture |

---

## External data sources

| Source | Role | Licence | Coverage | Prerequisite |
|--------|------|---------|----------|-------------|
| **OpenStreetMap** | Primary: roads, bike infra, water points, bike shops, resupply, base POIs & accommodations | [ODbL](https://opendatacommons.org/licenses/odbl/) | Global | None |
| **DataTourisme** | Complementary: enriched accommodations and cultural POIs; exclusive: dated events | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | France | `DATATOURISME_API_KEY` |
| **Wikidata** | Cross-cutting enricher: multilingual descriptions, images, Wikipedia links via Q-IDs | [CC0](https://creativecommons.org/publicdomain/zero/1.0/) | Europe | None |

### OpenStreetMap

All geographic and infrastructure data is derived from [OpenStreetMap](https://www.openstreetmap.org). The provisioner imports OSM extracts into a local PostGIS reference index that the API queries directly with spatial predicates (`ST_DWithin` / `ST_Covers`); there is no runtime Overpass dependency. See [ADR-040](docs/adr/adr-040-local-first-reference-data-postgis.md).

**Licence:** [ODbL 1.0](https://opendatacommons.org/licenses/odbl/) — attribution required: "© OpenStreetMap contributors".

#### OSM provisioning and refresh

The Valhalla routing engine consumes Geofabrik PBF extracts mounted at `/data` inside the `valhalla` container — see [ADR-020](docs/adr/adr-020-dynamic-overpass-region-provisioning.md) and [ADR-036](docs/adr/adr-036-manual-osm-data-refresh.md) for the full rationale.

**Bootstrap (first install or adding regions):**

```bash
make provision
```

This runs the unified `provision` command inside the `provisioner` container (Compose profile `provisioning`). It loads **all reference sources** in one shot:

- **OSM + PostGIS** — downloads the configured Geofabrik regions, writes them atomically to the shared `/data` volume, updates `/data/regions.json`, then imports the bikepacking-relevant OSM features (POI, accommodations, water points) into the PostGIS `osm` schema via `osm2pgsql` (flex style `provisioner/osm2pgsql/tier1.lua`). The import builds a staging schema and swaps it onto the live `osm` schema in one transaction, so the previous dataset keeps serving until the new one is complete. This local-first reference index is what the API reads via `ST_DWithin` corridor queries — see [ADR-040](docs/adr/adr-040-local-first-reference-data-postgis.md).
- **DataTourisme** — downloads the configured flux and imports it into the `tourism` schema. Skipped gracefully (with a warning) when `DATATOURISME_FLUX_ID` / `DATATOURISME_APP_KEY` are absent, so OSM still provisions.

Re-running the same command later updates existing regions instead of re-installing them — the install vs update mode is detected from the presence of `/data/regions.json` and the `--no-interaction` flag.

**Refresh (manual):**

OSM data is refreshed manually — there is no scheduled job. Re-download the configured Geofabrik extracts and reload them into Valhalla on whatever cadence suits the deployment:

```bash
make provision-update            # re-download the configured regions (non-interactive)
docker compose restart valhalla  # rebuild routing tiles from the new PBF
```

**Iso-prod recette stack:**

`make provision` / `make provision-update` inherit the dev `COMPOSE_FILE` and target the dev provisioner overlay. To provision against the iso-prod recette stack started with `make start-recette`, use the dedicated target instead:

```bash
make provision-recette           # provision the recette PostGIS index (non-interactive)
```

It targets `-f compose.yaml -f compose.recette.yaml`. The recette JWT keys and passphrase are wired in `compose.recette.yaml` (no `JWT_*` passed on the command line), and the `prod` / `dev` provisioner images now carry distinct tags (`bike-trip-planner-provisioner:prod` vs `:dev`), so the previous forced `--build` is no longer needed. Like `make provision`, it loads OSM + DataTourisme. The first run needs a seeded region selection in `.docker/osm/data/regions.json` (e.g. `{"slugs":["nord-pas-de-calais"]}`) or a prior interactive `make provision`.

See [ADR-036](docs/adr/adr-036-manual-osm-data-refresh.md) for why the automated nightly job (`osm-cron`) was dropped.

### DataTourisme

[DataTourisme](https://www.datatourisme.fr) provides enriched POI data (accommodations, cultural sites, dated events) for France. It is used as an optional supplementary source alongside OpenStreetMap.

**Licence:** [Licence Ouverte 2.0 Etalab](https://www.etalab.gouv.fr/licence-ouverte-open-licence) — commercial use and modification permitted; attribution required.

**Quota:** 1 000 requests/hour, ~10 req/s sustained. Rate limiting is enforced server-side via a `fixed_window` limiter.

**Registration:** [https://www.datatourisme.fr/](https://www.datatourisme.fr/) — free sign-up, personal API key delivered by email.

To enable DataTourisme integration, set the following environment variables:

```env
DATATOURISME_API_KEY=your-api-key
DATATOURISME_ENABLED=true
```

When `DATATOURISME_ENABLED=false` (the default) or the API key is absent, all DataTourisme calls are skipped and the application falls back to OpenStreetMap data exclusively.

### Wikidata

[Wikidata](https://www.wikidata.org) enriches POI, accommodation, and event data already returned by other sources that carry a Wikidata Q-ID (via OSM tag `wikidata=Q12345` or DataTourisme property `owl:sameAs`). Coverage is **European**. Licence is **CC0** — no attribution required.

Fields added: description, Wikimedia Commons thumbnail, Wikipedia article link, and structured opening hours when available.

Enrichment runs **at provision time**, not at request time (ADR-040/041): the provisioner batches the Wikidata SPARQL queries over the bounded set of Q-IDs imported into PostGIS and stores the result in the `osm.*` / `tourism.*` columns, behind a persistent cache (`provisioner.wikidata_cache`). The API reads the enriched columns from the local database — no runtime Wikidata call, no configuration required. A Wikidata outage degrades only the next provisioning refresh, never a user request.

---

## Contributing

Contributions are welcome! Please read the [Contributing Guide](docs/contributing.md) before submitting a pull request.

```bash
make start-dev    # Boot Docker environment
make qa           # Run full QA suite (linting, static analysis, formatting)
make test         # Run all tests (QA + PHPUnit + Playwright)
```

---

## License

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE).

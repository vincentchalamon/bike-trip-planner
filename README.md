# Bike Trip Planner

*[Version francaise](README.fr.md)*

A local-first bikepacking trip planner. Paste a Komoot, Strava, or RideWithGPS route URL (or upload a GPX file), get a structured day-by-day roadbook with pacing, elevation alerts, and accommodation suggestions -- all without an account or cloud storage.

---

## Features

### Route ingestion

- **Magic Link** -- Paste a Komoot tour/collection, Strava route, or RideWithGPS route URL; the backend fetches the route, parses elevation data, and computes a full trip plan asynchronously.
- **GPX upload** -- Drag and drop or select a GPX file directly (up to 15 MB). The file is stream-parsed with constant memory usage.
- **Shareable link** -- A `?link=` query parameter auto-creates a trip from any supported URL, allowing you to share a direct link to a trip plan.

### Trip planning

- **Pacing engine** -- Distributes distance across days accounting for cumulative fatigue and elevation gain, with configurable maximum distance per day, fatigue factor, elevation penalty, and average speed.
- **E-bike mode** -- Dedicated mode that adjusts range calculations for electric bicycles (effective range = 80 km minus elevation/25).
- **Date range picker** -- Set departure and return dates to enable calendar-aware alerts (public holidays, Sundays) and weather forecasts.
- **Departure hour** -- Configure the daily start time to enable sunset arrival alerts.
- **Rest day insertion** -- Add rest days between stages; a nudge alert reminds you every N consecutive cycling days.
- **Stage editing** -- Split, merge, add, or delete stages. Rename stage start and end locations via inline editing with geocoding. Adjust stage distances with a visual editor.
- **Undo/redo** -- Full undo/redo history for all trip modifications (Ctrl+Z / Ctrl+Y).

### Interactive map

- **Route visualization** -- Interactive Leaflet map showing the full route with color-coded stages.
- **Elevation profile** -- Synchronized elevation chart with hover cross-referencing between map and profile.
- **Stage markers** -- Click a stage on the map or the timeline to focus on it; click again to reset to the global view.
- **View modes** -- Three layouts: timeline only, map only, or split (timeline + map side by side). Defaults to timeline on mobile, split on desktop.

### Weather and environment

- **Weather forecast** -- Open-Meteo integration providing temperature, precipitation, wind speed, and weather conditions per stage.
- **Comfort index** -- Combined score (0-100) of temperature, wind, humidity, and rain for each stage.
- **Relative wind** -- Calculates headwind/tailwind/crosswind based on stage bearing and wind direction.

### Alert engine

The backend runs a pipeline of analyzers on each stage. Three severity levels:

| Level | Color | Description |
|-------|-------|-------------|
| `critical` | Red | Blocking issue requiring immediate attention |
| `warning` | Orange | Significant issue to watch |
| `nudge` | Blue | Informational suggestion |

Rules are executed in priority order (lower = higher priority):

| Rule | Priority | Severity | Trigger |
|------|----------|----------|---------|
| **Continuity** | 5 | critical | Gap > 500 m between consecutive stages |
| **Continuity** | 5 | warning | Gap 100-500 m between stages |
| **Elevation** | 10 | warning | Elevation gain > 1 200 m on a stage |
| **Steep gradient** | 20 | warning | Sustained >= 8 % gradient over >= 500 m |
| **Surface** | 20 | warning | Unpaved section >= 500 m (gravel, dirt, mud, grass, sand...) |
| **Surface** | 20 | warning | OSM surface data missing on >= 30 % of ways |
| **Traffic** | 20 | critical | Primary/trunk road without cycle infrastructure >= 500 m |
| **Traffic** | 20 | warning | Secondary road, no cycleway, speed limit > 50 km/h |
| **Traffic** | 20 | nudge | Secondary road, speed limit <= 50 km/h |
| **E-bike range** | 20 | warning | Day distance > effective range (80 km - elevation / 25) |
| **Sunset** | 20 | warning | Estimated arrival time exceeds civil twilight end at stage end point |
| **Rest day** | 100 | nudge | Every N consecutive cycling days without a rest day (default: every 3 days) |
| **Calendar** | -- | nudge | Stage falls on a French public holiday |
| **Calendar** | -- | nudge | Stage falls on a Sunday (businesses may be closed) |
| **Wind** | -- | warning | Headwind >= 25 km/h on >= 60 % of stages with weather data |
| **Comfort** | -- | warning | Poor comfort index (< 40/100) on at least one stage |
| **Bike shops** | -- | nudge | No repair shop within 2 km of stage midpoint (trips > 5 stages) |
| **Bike shops** | -- | nudge | Nearby shop sells bikes but does not offer repair service |
| **Resupply** | -- | nudge | Stage >= 40 km with no food/resupply POI along the route |
| **Resupply** | -- | warning | All resupply POIs on the stage are closed at estimated passage time |
| **Accommodation** | -- | warning | All detected accommodations on the stage are likely closed due to seasonality |
| **Water points** | -- | nudge | Stretch > 30 km without a detected drinking water source |
| **Cultural POI** | -- | nudge | Museum, monument, castle, church, viewpoint, or attraction within 500 m of route -- includes an "add to itinerary" action triggering route recalculation |

**Terrain rules** (Continuity, Elevation, Steep gradient, Surface, Traffic, E-bike range, Sunset, Rest day) implement `StageAnalyzerInterface` and are auto-discovered via `#[AutoconfigureTag('app.stage_analyzer')]`. Rules with `--` priority (Calendar, Wind + Comfort, Bike shops, Resupply, Accommodation, Water points, Cultural POI) are separate async Symfony Message handlers; Comfort is co-located with Wind inside `AnalyzeWindHandler`.

### Points of interest

- **Accommodation scanner** -- Queries OpenStreetMap Overpass for bivouac spots, refuges, and gites near each stage end, with heuristic pricing. Filter accommodations by type.
- **Supply timeline** -- Visual timeline showing water and food resupply points along each stage, with clustering for readability.
- **Bike shops** -- Detects repair shops near each stage midpoint.
- **Cultural POIs** -- Museums, monuments, castles, churches, viewpoints, and attractions near the route with an "add to itinerary" action.

### Exports

- **GPX export** -- Download each stage as an individual GPX file with enriched waypoints (POIs, water points, food stops, accommodations).
- **FIT export** -- Download each stage as a Garmin-compatible FIT file with course points.
- **Full trip GPX** -- Download the entire trip as a single GPX file.
- **Text export** -- Copy/paste-friendly plain text summary of the entire trip (stages, distances, elevations, accommodations).

### User experience

- **Onboarding tour** -- Guided 4-step tour on first visit using driver.js, walking through the core workflow.
- **Keyboard shortcuts** -- Navigate stages (J/K), undo/redo (Ctrl+Z/Y), toggle help (?), close panels (Esc).
- **Dark mode** -- Theme toggle with system preference detection.
- **Internationalization** -- Full French and English UI via next-intl.
- **Responsive design** -- Mobile-first with adaptive view mode (timeline/map/split).
- **Swipe navigation** -- Swipe between stages on mobile devices.

---

## Architecture overview

<!-- markdownlint-disable MD040 -->
```
Browser (Next.js 16)           PHP Backend (API Platform 4.2)
  Zustand + Immer (in-memory)    Stateless computation
  Zod validation                 GPX parsing + pacing engine
  openapi-fetch (typed)          OSM Overpass + weather APIs
  Mercure SSE (real-time)  <--   Async workers (Symfony Messenger)
                                 Redis cache + Mercure publisher
                                 |
                            Headless Chromium via Twig (PDF)
```

The frontend sends a trip request via REST; the backend processes it asynchronously across multiple workers and pushes status updates via Mercure SSE. No database -- Redis cache for transient state, filesystem cache for external API responses.

Type safety is enforced end-to-end: PHP DTOs define the schema -> API Platform exports an OpenAPI spec -> `npm run typegen` generates TypeScript types -> `openapi-fetch` provides type-safe API calls. A schema change on the backend intentionally causes a TypeScript compilation failure.

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.5, Symfony 8, API Platform 4.2, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| State | Zustand + Immer (in-memory), Mercure SSE (real-time) |
| Map | Leaflet, react-leaflet |
| Styling | Tailwind CSS, shadcn/ui |
| Testing | PHPUnit 13 (backend), Playwright 1.58 (E2E) |
| Quality | PHPStan level 9, PHP-CS-Fixer, Rector, ESLint, Prettier |
| Async | Symfony Messenger, Redis transport, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, Node) |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Getting Started](docs/getting-started.md) | Requirements, installation, and local setup |
| [Contributing](docs/contributing.md) | Development workflow, standards, and tooling |
| [Architecture Decisions](docs/adr/) | ADRs explaining every major technical choice |
| [Claude Code Tooling](docs/claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |

---

## Quick start

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
make start
```

The app is available at `https://localhost` (PWA) and `https://localhost/docs` (API).

See [Getting Started](docs/getting-started.md) for prerequisites and detailed setup.

---

## Supported route sources

| Source | URL pattern |
|--------|-------------|
| Komoot tour | `https://www.komoot.com/tour/<id>` |
| Komoot collection | `https://www.komoot.com/collection/<id>` |
| Strava route | `https://www.strava.com/routes/<id>` |
| RideWithGPS route | `https://ridewithgps.com/routes/<id>` |
| GPX file upload | Drag and drop or file picker (up to 15 MB) |

---

## License

MIT

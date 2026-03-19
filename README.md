# Bike Trip Planner

A local-first bikepacking trip planner. Paste a Komoot tour URL, get a structured day-by-day roadbook with pacing, elevation alerts, and accommodation suggestions — all without an account or cloud storage.

---

## What it does

- **Magic Link ingestion** — Provide a Komoot tour/collection URL; the backend fetches the route, parses elevation data, and computes a full trip plan asynchronously.
- **Pacing engine** — Distributes distance across days accounting for cumulative fatigue and elevation gain, with a configurable minimum daily threshold.
- **Alert engine** — Rule-based system with three severity levels (`critical`, `warning`, `nudge`) covering continuity gaps, elevation, steep gradients, surface type, traffic danger, and e-bike range. See [Alert engine](#alert-engine) for the full rule catalogue.
- **Accommodation scanner** — Queries OpenStreetMap Overpass for bivouac spots, refuges, and gîtes near each stage end, with heuristic pricing.
- **Local-first** — Trip data lives in-memory during a session. No account required, no persistent cloud database — computation is on-demand.

## Alert engine

The backend runs a pipeline of analyzers on each stage. Three severity levels are used:

| Level | Color | Description |
|-------|-------|-------------|
| `critical` | Red | Blocking issue requiring immediate attention |
| `warning` | Orange | Significant issue to watch |
| `nudge` | Blue | Informational suggestion |

Rules are executed in priority order (lower = higher priority):

| Rule | Priority | Severity | Trigger |
|------|----------|----------|---------|
| **Continuity** | 5 | critical | Gap > 500 m between consecutive stages |
| **Continuity** | 5 | warning | Gap 100–500 m between stages |
| **Elevation** | 10 | warning | Elevation gain > 1 200 m on a stage |
| **Steep gradient** | 20 | warning | Sustained ≥ 8 % gradient over ≥ 500 m |
| **Surface** | 20 | warning | Unpaved section ≥ 500 m (gravel, dirt, mud, grass, sand…) |
| **Surface** | 20 | warning | OSM surface data missing on ≥ 30 % of ways |
| **Traffic** | 20 | critical | Primary/trunk road without cycle infrastructure ≥ 500 m |
| **Traffic** | 20 | warning | Secondary road, no cycleway, speed limit > 50 km/h |
| **Traffic** | 20 | nudge | Secondary road, speed limit ≤ 50 km/h |
| **E-bike range** | 20 | warning | Day distance > effective range (80 km − elevation / 25) |
| **Calendar** | — | nudge | Stage falls on a French public holiday |
| **Calendar** | — | nudge | Stage falls on a Sunday (businesses may be closed) |
| **Wind** | — | warning | Headwind ≥ 25 km/h on ≥ 60 % of stages with weather data |
| **Comfort** | — | warning | Poor comfort index (< 40/100) on at least one stage (combined score of temperature, wind, humidity, rain) |
| **Bike shops** | — | nudge | No repair shop within 2 km of stage midpoint (trips > 5 stages) |
| **Bike shops** | — | nudge | Nearby shop sells bikes but does not offer repair service |
| **Resupply** | — | nudge | Stage ≥ 40 km with no food/resupply POI along the route |
| **Resupply** | — | warning | All resupply POIs on the stage are closed at estimated passage time |
| **Accommodation** | — | warning | All detected accommodations on the stage are likely closed due to seasonality |
| **Water points** | — | nudge | Stretch > 30 km without a detected drinking water source (cemeteries used as proxy — water tap required by French law) |
| **Rest day** | 100 | nudge | Every N consecutive cycling days without a rest day (default: every 3 days) |
| **Sunset** | 20 | warning | Estimated arrival time exceeds civil twilight end at stage end point |
| **Cultural POI** | — | nudge | Museum, monument, castle, church, viewpoint, or attraction within 500 m of route — includes an "add to itinerary" action triggering route recalculation |

**Terrain rules** (Continuity, Elevation, Steep gradient, Surface, Traffic, E-bike range, Sunset, Rest day) implement `StageAnalyzerInterface` and are auto-discovered via `#[AutoconfigureTag('app.stage_analyzer')]`. Rules with `—` priority (Calendar, Wind + Comfort, Bike shops, Resupply, Accommodation, Water points, Cultural POI) are separate async Symfony Message handlers; Comfort is co-located with Wind inside `AnalyzeWindHandler`.

## Architecture overview

<!-- markdownlint-disable MD040 -->
```
Browser (Next.js 16)           PHP Backend (API Platform 4.2)
  Zustand + Immer (in-memory)    Stateless computation
  Zod validation                 GPX parsing + pacing engine
  openapi-fetch (typed)          OSM Overpass + weather APIs
  Mercure SSE (real-time)  ←─    Async workers (Symfony Messenger)
                                 Redis cache + Mercure publisher
                                 ↓
                            Headless Chromium via Twig (PDF)
```

The frontend sends a trip request via REST; the backend processes it asynchronously across multiple workers and pushes status updates via Mercure SSE. No database — Redis cache for transient state, filesystem cache for external API responses.

Type safety is enforced end-to-end: PHP DTOs define the schema → API Platform exports an OpenAPI spec → `npm run typegen` generates TypeScript types → `openapi-fetch` provides type-safe API calls. A schema change on the backend intentionally causes a TypeScript compilation failure.

## Tech stack

| Layer    | Technology                                             |
|----------|--------------------------------------------------------|
| Backend  | PHP 8.5, Symfony 8, API Platform 4.2, Caddy            |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| State    | Zustand + Immer (in-memory), Mercure SSE (real-time)    |
| Styling  | Tailwind CSS                                           |
| Testing  | PHPUnit 13 (backend), Playwright 1.58 (E2E)            |
| Quality  | PHPStan level 9, PHP-CS-Fixer, ESLint, Prettier        |
| Async    | Symfony Messenger, Redis transport, 5 workers           |
| Runtime  | Docker (Caddy, Mercure, Redis, Node)                   |

## Documentation

| Document                                           | Description                                                |
|----------------------------------------------------|------------------------------------------------------------|
| [Getting Started](docs/getting-started.md)         | Requirements, installation, and local setup                |
| [Contributing](docs/contributing.md)               | Development workflow, standards, and tooling               |
| [Architecture Decisions](docs/adr/)                | ADRs explaining every major technical choice               |
| [Claude Code Tooling](docs/claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |

## Quick start

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
make start
```

The app is available at `https://localhost` (PWA) and `https://localhost/docs` (API).

See [Getting Started](docs/getting-started.md) for prerequisites and detailed setup.

## License

MIT

# Bike Trip Planner

A local-first bikepacking trip planner. Paste a Komoot tour URL, get a structured day-by-day roadbook with pacing, elevation alerts, accommodation suggestions, and a downloadable PDF — all without an account or cloud storage.

---

## What it does

- **Magic Link ingestion** — Provide a Komoot tour or collection URL; the backend fetches the GPX, parses elevation data, and computes a full trip plan.
- **Pacing engine** — Distributes distance across days accounting for cumulative fatigue and elevation gain, with a configurable minimum daily threshold.
- **Alert engine** — Rule-based system that flags dangerous passes, exposed ridges, remote segments with no services, and weather windows.
- **Accommodation scanner** — Queries OpenStreetMap Overpass for bivouac spots, refuges, and gîtes near each stage end, with heuristic pricing.
- **PDF roadbook** — Exports a printable roadbook (via Gotenberg) with stage maps, elevation profiles, and alert summaries.
- **Local-first** — All trip data is stored in the browser's `localStorage`. No account required, no data leaves your machine unless you request a plan computation.

## Architecture overview

```
Browser (Next.js 16)          PHP Backend (API Platform 4.2)
  localStorage (Zustand)  ←→   Stateless computation
  Zod validation               GPX parsing + pacing engine
  openapi-fetch (typed)        OSM Overpass + weather APIs
                               ↓
                          Gotenberg (PDF)
                          Headless Chromium via Twig
```

The backend exposes a single stateless API: send a trip request, receive a computed trip response. No database. No sessions. The frontend orchestrates state, persistence, and presentation.

Type safety is enforced end-to-end: PHP DTOs define the schema → API Platform exports an OpenAPI spec → `npm run typegen` generates TypeScript types → `openapi-fetch` provides type-safe API calls. A schema change on the backend intentionally causes a TypeScript compilation failure.

## Tech stack

| Layer       | Technology                                              |
|-------------|--------------------------------------------------------|
| Backend     | PHP 8.5, Symfony 8, API Platform 4.2, FrankenPHP       |
| Frontend    | Next.js 16 (App Router), React 19, TypeScript (strict) |
| State       | Zustand + Immer, localStorage persistence              |
| Styling     | Tailwind CSS                                           |
| PDF         | Gotenberg 8 (headless Chromium), Twig templates        |
| Testing     | PHPUnit 13 (backend), Playwright 1.58 (E2E)            |
| Quality     | PHPStan level 9, PHP-CS-Fixer, ESLint, Prettier        |
| Runtime     | Docker (FrankenPHP, Node, Gotenberg)                   |

## Documentation

| Document | Description |
|----------|-------------|
| [Getting Started](docs/getting-started.md) | Requirements, installation, and local setup |
| [Contributing](docs/contributing.md) | Development workflow, standards, and tooling |
| [Architecture Decisions](docs/adr/) | ADRs explaining every major technical choice |
| [Claude Code Tooling](docs/claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |

## Quick start

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
make install
make start
```

The app is available at `https://localhost` (PWA) and `https://localhost/api` (API).

See [Getting Started](docs/getting-started.md) for prerequisites and detailed setup.

## License

MIT

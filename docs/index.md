# Bike Trip Planner

**Plan your bikepacking adventures with confidence.**

Paste a Komoot URL or upload a GPX file, and get a structured day-by-day roadbook
with smart pacing, safety alerts, and accommodation suggestions.

Bike Trip Planner has a decoupled architecture: a PHP backend (API Platform on Symfony 8)
provides stateless computation via async workers, and a Next.js 16 frontend manages
presentation and state.

## Quick start

```bash
git clone https://github.com/vincentchalamon/bike-trip-planner.git
cd bike-trip-planner
make start-dev
```

The app is available at:

- **<https://localhost>** — Web application
- **<https://localhost/docs>** — API documentation (Swagger UI)

See [Getting Started](getting-started.md) for prerequisites and detailed setup instructions.

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
<!-- markdownlint-enable MD040 -->

The frontend sends a trip request via REST; the backend processes it asynchronously across multiple workers and pushes status updates via Mercure SSE. PostgreSQL 18 persists trip configuration and stages; Redis handles transient computation state, Messenger transport, and external API caches.

Type safety is enforced end-to-end: PHP DTOs define the schema -> API Platform exports an OpenAPI spec -> `npm run typegen` generates TypeScript types -> `openapi-fetch` provides type-safe API calls. A schema change on the backend intentionally causes a TypeScript compilation failure.

See [Architecture](architecture.md) for the full picture and the reasoning behind each choice.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.5, Symfony 8, API Platform 4.3, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| State | Zustand + Immer (in-memory), Mercure SSE (real-time) |
| Styling | Tailwind CSS |
| Testing | PHPUnit 13 (backend), Playwright 1.62 (E2E) |
| Quality | PHPStan level 9, PHP-CS-Fixer, ESLint, Prettier |
| Async | Symfony Messenger, Redis transport, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, PostgreSQL, Node) |

## Explore the documentation

| Section | For |
|---|---|
| [Features](features.md) | What the planner does |
| [Getting Started](getting-started.md) | Requirements, installation, and local setup |
| [Architecture](architecture.md) | System overview and the reasoning behind the ADRs |
| [Supported route sources](route-sources.md) | Accepted Komoot / Strava / RideWithGPS / GPX inputs |
| [Supported accommodation tags](accommodations.md) | OSM logical types and pricing heuristics |
| [Alert engine](alert-engine.md) | Canonical alert-rule table (severity, priority, trigger) |
| [External data sources](external-data-sources.md) | OSM, DataTourisme, Wikidata |
| [Contributing](contributing.md) | Development workflow, standards, and tooling |
| [Deployment](deployment.md) | CI/CD pipeline, required secrets, rollback procedure |
| [Architecture Decision Records](adr/adr-001-global-architecture-and-separation-of-concerns.md) | Every major technical choice, with context and alternatives |
| [Runbooks](runbooks/README.md) | On-call playbooks: workers, DB, Redis, Mercure, releases |
| [Claude Code Tooling](claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |
| [Legal & Licensing](legal-and-licensing.md) | Project licence, data attribution, and GDPR posture |

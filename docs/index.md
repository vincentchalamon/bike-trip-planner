# Bike Trip Planner

**Plan your bikepacking adventures with confidence.**

Paste a Komoot, Strava or RideWithGPS link, or upload a GPX file, and get a day-by-day
roadbook with smart pacing, safety alerts, accommodation suggestions and weather. Take it
on the road with the web app or the native mobile app, or let an AI agent plan it for you
over MCP.

New here? Follow [Plan your first trip](plan-your-first-trip.md). Setting up the code?
Start with [Getting Started](getting-started.md).

## Architecture overview

<!-- markdownlint-disable MD040 -->
```
Web (Next.js 16) / Mobile (Expo)      PHP backend (API Platform 5 on Symfony 8.1)
  @btp/core: shared types + SSE         REST API + MCP server (OAuth 2.1)
  reducers                              GPX parsing + pacing engine
  Zustand + Immer (in-memory)           Valhalla routing + PostGIS reference data
  openapi-fetch (typed)                 Async workers (Symfony Messenger)
  Mercure SSE (real-time)        <--    Mercure publisher, Open-Meteo weather
                                        PostgreSQL 18 + Redis
```
<!-- markdownlint-enable MD040 -->

The clients send trip requests over REST; the backend computes the stage breakdown, then
enriches it asynchronously across `WORKER_REPLICAS` workers and pushes updates over Mercure
SSE. PostgreSQL persists accounts, trips and stages, and holds the OpenStreetMap /
DataTourisme reference index (PostGIS); Redis holds transient computation state, the
Messenger transport and external API caches.

Type safety is enforced end-to-end: PHP DTOs define the schema -> API Platform exports an
OpenAPI spec -> `npm run typegen` generates TypeScript types into the shared `@btp/core`
package -> `openapi-fetch` provides type-safe API calls in both the web and mobile apps.

See [Architecture](architecture.md) for the full picture and the reasoning behind each choice.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.5, Symfony 8.1, API Platform 5.0, Doctrine ORM 3, FrankenPHP (Caddy + Mercure) |
| Data | PostgreSQL 18 + PostGIS, Redis 8, Valhalla (routing) |
| Web | Next.js 16 (App Router), React 19, TypeScript (strict), Tailwind CSS 4 |
| Mobile | Expo SDK 57, React Native 0.86, MapLibre React Native |
| State | Zustand + Immer (in-memory), Mercure SSE (real-time) |
| Testing | PHPUnit 12 (`api/`) and 13 (`provisioner/`), Playwright 1.63, Jest (mobile) |
| Quality | PHPStan level 9, PHP-CS-Fixer, Rector, ESLint, Prettier |
| Async | Symfony Messenger, Redis transport, `WORKER_REPLICAS` workers (2 by default) |

## Explore the documentation

| Section | Page | For |
|---|---|---|
| Tutorials | [Plan your first trip](plan-your-first-trip.md) | Riders: from a route link to an exported roadbook |
| | [Getting Started](getting-started.md) | Developers: run the stack locally |
| How-to guides | [Connect an AI agent](connect-an-ai-agent.md) | Plug Claude or another MCP client into your account |
| | [Contributing](contributing.md) | Development workflow, standards, and tooling |
| | [Deployment](deployment.md) | CI/CD pipeline, secrets, rollback |
| | [Runbooks](runbooks/README.md) | Operations playbooks: workers, DB, Redis, Mercure, zones, releases |
| Reference | [Features](features.md) | What the product does, by area |
| | [Supported route sources](route-sources.md) | Accepted Komoot / Strava / RideWithGPS / GPX inputs |
| | [Accommodation types](accommodations.md) | Logical types, OSM tags, pricing heuristic |
| | [Alert engine](alert-engine.md) | Every alert code: severity, priority, trigger |
| | [External data sources](external-data-sources.md) | OSM, DataTourisme, OpenAgenda, Wikidata, Open-Meteo, Nominatim |
| | [MCP tools](mcp-tools.md) | The tools an AI agent can call |
| | [Mobile Mercure auth](mobile-mercure-auth.md) | SSE authentication for the native app |
| | [Claude Code tooling](claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |
| | [Legal & Licensing](legal-and-licensing.md) | Licence, data attribution, GDPR posture |
| Explanation | [Architecture](architecture.md) | System overview and the reasoning behind it |
| | [Architecture Decision Records](adr/adr-001-global-architecture-and-separation-of-concerns.md) | Every major technical choice, with context and alternatives |
| | [MCP feasibility spike](spikes/mcp-feasibility-2026-09-17.md) | Time-boxed investigation behind the MCP server |
| | [OAuth2 server feasibility spike](spikes/oauth2-server-feasibility-2026-09-23.md) | Time-boxed investigation behind the MCP authorization server |
| | [DataTourisme flux audit](datatourisme-flux-audit.md) | What the DataTourisme feed actually carries |

<h1 align="center">Bike Trip Planner</h1>

<p align="center">
  <strong>Plan your bikepacking adventures with confidence.</strong>
</p>

<p align="center">
  Paste a Komoot, Strava or RideWithGPS link, or upload a GPX file, and get a day-by-day roadbook<br />
  with smart pacing, safety alerts, accommodation suggestions and weather.
</p>

<p align="center">
  <a href="https://github.com/vincentchalamon/bike-trip-planner/actions/workflows/ci.yml"><img src="https://github.com/vincentchalamon/bike-trip-planner/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI" /></a>
  <a href="https://github.com/vincentchalamon/bike-trip-planner/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue.svg" alt="License" /></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white" alt="PHP 8.5" />
  <img src="https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white" alt="Symfony 8.1" />
  <img src="https://img.shields.io/badge/Next.js-16-000000?logo=next.js&logoColor=white" alt="Next.js 16" />
  <img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black" alt="React 19" />
  <img src="https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white" alt="TypeScript" />
  <img src="https://img.shields.io/badge/API%20Platform-5.0-38B2AC?logo=api-platform&logoColor=white" alt="API Platform 5.0" />
  <img src="https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white" alt="Docker" />
</p>

---

## Screenshots

> **Desktop** — Split view with day-by-day timeline, contextual alerts, and interactive map.

![Desktop - Split view](docs/assets/screenshots/desktop-split-view.png)

> **Mobile** — Day-by-day timeline on a phone-sized screen.

<p align="center"><img src="docs/assets/screenshots/mobile-timeline.png" alt="Mobile - Timeline" width="320" /></p>

---

## Overview

Bike Trip Planner is a bikepacking trip planner with a decoupled architecture: a PHP
backend (API Platform 5 on Symfony 8.1) computes trips through async workers, and two
clients, a Next.js 16 web app and an Expo native mobile app, manage presentation and state.

It imports a route from Komoot, Strava, RideWithGPS or a GPX upload, then builds a
day-by-day roadbook with:

- a fatigue- and elevation-aware pacing engine, with editable stages and rest days;
- a rule-based safety and comfort [alert engine](docs/alert-engine.md) (terrain, traffic,
  weather, calendar, services);
- accommodation, cultural POI and local event discovery from OpenStreetMap, DataTourisme
  and OpenAgenda, and per-stage Open-Meteo forecasts;
- an in-ride nearby-search assistant (no AI);
- GPX / FIT export and read-only share links;
- magic-link accounts with data export and account erasure;
- an MCP server so an AI agent can plan trips on your behalf, under OAuth 2.1 with
  revocable access.

See the [feature overview](docs/features.md), or the rider tutorial
[Plan your first trip](docs/plan-your-first-trip.md).

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

## Mobile app

Bike Trip Planner has two surfaces on one API: the web app above (discovery,
sharing, planning on a large screen) and a **native mobile app** for in-the-field
use: offline consultation of upcoming trips, in-ride assistance and push
notifications ([ADR-053](docs/adr/adr-053-mobile-strategy-native-app.md)).

The codebase is an npm-workspaces monorepo:

| Workspace | Role |
|---|---|
| [`core/`](core/) (`@btp/core`) | Framework-free shared package: OpenAPI-derived types, Zod schemas, Mercure wire types, and the pure SSE reconciliation reducers used by both web and mobile |
| [`pwa/`](pwa/) | Next.js 16 web app |
| [`mobile/`](mobile/) | Expo / React Native app (Android first, iOS later) |

The mobile foundation is documented in
[ADR-054 (design system)](docs/adr/adr-054-mobile-design-system.md),
[ADR-055 (state architecture)](docs/adr/adr-055-mobile-state-architecture.md), and
[ADR-056 (Mercure header-auth)](docs/adr/adr-056-mercure-header-auth-non-browser.md).

```bash
npm install                          # install all workspaces from the repo root
npm run android --workspace mobile   # build & launch on an Android device/emulator (expo run:android)
npm run start --workspace mobile     # start the Expo dev server
```

---

## Documentation

Full documentation is published with MkDocs Material at
**<https://vincentchalamon.github.io/bike-trip-planner/>**, and the sources live in [`docs/`](docs/).

| Document | Description |
|---|---|
| [Plan your first trip](docs/plan-your-first-trip.md) | Rider tutorial: from a route link to an exported roadbook |
| [Getting Started](docs/getting-started.md) | Developer tutorial: requirements, installation, and local setup |
| [Features](docs/features.md) | What the product does, by area |
| [Connect an AI agent](docs/connect-an-ai-agent.md) | Plug an MCP client into your account |
| [MCP tools](docs/mcp-tools.md) | The tools an AI agent can call |
| [Supported route sources](docs/route-sources.md) | Accepted Komoot / Strava / RideWithGPS / GPX inputs |
| [Accommodation types](docs/accommodations.md) | Logical types, OSM tags, pricing heuristic |
| [Alert engine](docs/alert-engine.md) | Every alert code: severity, priority, trigger |
| [External data sources](docs/external-data-sources.md) | OSM, DataTourisme, OpenAgenda, Wikidata, Open-Meteo, Nominatim |
| [Architecture](docs/architecture.md) | System overview and the reasoning behind the ADRs |
| [Architecture Decisions](docs/adr/) | 82 ADRs explaining every major technical choice |
| [Contributing](docs/contributing.md) | Development workflow, standards, and tooling |
| [Deployment](docs/deployment.md) | CI/CD pipeline, required secrets, rollback procedure |
| [Runbooks](docs/runbooks/) | Operations playbooks: workers, DB, Redis, Mercure, zones, releases |
| [Claude Code Tooling](docs/claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |
| [Legal & Licensing](docs/legal-and-licensing.md) | Project licence, data attribution, and GDPR posture |

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

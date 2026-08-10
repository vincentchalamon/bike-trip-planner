<h1 align="center">Bike Trip Planner</h1>

<p align="center">
  <strong>Plan your bikepacking adventures with confidence.</strong>
</p>

<p align="center">
  Paste a Komoot URL or upload a GPX file, and get a structured day-by-day roadbook<br />
  with smart pacing, safety alerts, and accommodation suggestions.
</p>

<p align="center">
  <a href="https://github.com/vincentchalamon/bike-trip-planner/actions/workflows/ci.yml"><img src="https://github.com/vincentchalamon/bike-trip-planner/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI" /></a>
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

## Overview

Bike Trip Planner is a bikepacking trip planner with a decoupled architecture: a PHP
backend (API Platform on Symfony 8) provides stateless computation via async workers, and a
Next.js 16 frontend manages presentation and state. It imports a route from Komoot, Strava,
RideWithGPS, or a GPX upload, then builds a day-by-day roadbook with a smart pacing engine,
a rule-based safety/comfort [alert engine](docs/alert-engine.md), accommodation and
cultural-POI discovery, real-time processing over Mercure SSE, optional bring-your-own-token
AI analysis, and multi-format export. See the [feature overview](docs/features.md).

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

## Documentation

Full documentation is published with MkDocs Material at
**<https://vincentchalamon.github.io/bike-trip-planner/>**, and the sources live in [`docs/`](docs/).

| Document | Description |
|---|---|
| [Features](docs/features.md) | Product feature overview |
| [Getting Started](docs/getting-started.md) | Requirements, installation, and local setup |
| [Architecture](docs/architecture.md) | System overview and the reasoning behind the ADRs |
| [Supported route sources](docs/route-sources.md) | Accepted Komoot / Strava / RideWithGPS / GPX inputs |
| [Supported accommodation tags](docs/accommodations.md) | OSM logical types and pricing heuristics |
| [Alert engine](docs/alert-engine.md) | Canonical alert-rule table (severity, priority, trigger) |
| [External data sources](docs/external-data-sources.md) | OpenStreetMap, DataTourisme, Wikidata |
| [Contributing](docs/contributing.md) | Development workflow, standards, and tooling |
| [Deployment](docs/deployment.md) | CI/CD pipeline, required secrets, rollback procedure |
| [Architecture Decisions](docs/adr/) | 51 ADRs explaining every major technical choice |
| [Runbooks](docs/runbooks/) | On-call playbooks: workers, DB, Redis, Mercure, releases |
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

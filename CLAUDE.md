# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bike Trip Planner — a local-first bikepacking trip planner. Decoupled architecture: PHP backend (API Platform on Symfony 8) provides stateless computation via async workers, Next.js 16 frontend manages presentation and in-memory state. No persistent cloud database; trip data lives in-memory (Zustand) during a session and is recomputed on demand.

## Tech Stack

- **Backend:** PHP 8.5, API Platform 4.2, Symfony 8, Caddy (Docker)
- **Frontend:** Next.js 16 (App Router), React 19, TypeScript (strict), Zustand + Immer, Tailwind CSS
- **Testing:** PHPUnit 13 (backend), Playwright 1.58 (E2E)
- **Quality:** PHPStan Level 9, PHP-CS-Fixer (PSR-12/Symfony), ESLint, Prettier

## Common Commands

All orchestrated via Makefile. Run `make help` for full list.

```bash
make start-dev          # Boot Docker environment (php, pwa)
make qa                 # Full QA: PHP-CS-Fixer + Rector + PHPStan + ESLint + Prettier + TS checks
make test               # Full suite: QA → PHPUnit → Playwright
make test-php           # PHPUnit 13 only
make test-e2e           # Playwright E2E only
make php-shell          # Bash inside PHP container
make pwa-shell          # Bash inside Node container
```

Running specific tools inside containers:

```bash
make phpstan
make phpunit
make phpunit -- --filter=TestClassName
make test-e2e
make test-e2e -- tests/specific-test.spec.ts
```

When backend DTOs change, regenerate frontend types:

```bash
cd pwa && npm run typegen
```

Pre-commit hook runs `make qa` automatically; commit aborts on failure.

## Architecture

### Type Contract (Single Source of Truth)

Backend PHP DTOs define the schema → API Platform exports OpenAPI spec → `npm run typegen` (openapi-typescript) generates TypeScript types → openapi-fetch provides type-safe API calls. Schema changes on backend intentionally cause frontend compilation failures to prevent data drift.

### Directory Layout

- `api/` — PHP backend (API Platform/Symfony)
  - `src/ApiResource/` — DTOs (TripRequest, TripResponse, Stage, Alert, Accommodation)
  - `src/State/` — API Platform State Processors & Providers
  - `src/Spatial/` — GPX parsing, decimation
  - `src/Pacing/` — Stage generation
  - `src/Osm/` — Overpass queries
  - `src/Pricing/` — Heuristic accommodation pricing
  - `src/RouteFetcher/` — URL fetchers (Komoot Tour, Komoot Collection, Google MyMaps)
  - `src/RouteParser/` — Route parsers (GPX, KML)
  - `src/Weather/` — Weather providers (OpenMeteo, OpenWeather)
  - `src/Analyzer/` — Rule-based alert engine using Chain of Responsibility with Symfony tagged services (`#[AutoconfigureTag('app.stage_analyzer')]`)
  - `src/Engine/` — Pluggable computation engines (auto-discovered via `#[AutoconfigureTag]`)
  - `src/MessageHandler/` — Async message handlers (Symfony Messenger)
  - `src/Mercure/` — SSE event publisher
  - `templates/` — Twig templates (PDF roadbook)
- `pwa/` — Next.js frontend
  - `src/store/` — Zustand stores (in-memory, Immer middleware)
  - `src/lib/api/` — Generated types (`schema.d.ts`) and openapi-fetch client
  - `src/lib/mercure/` — Mercure SSE client and event types
  - `src/lib/validation/` — Zod schemas (manually aligned with PHP DTOs)
  - `tests/fixtures/` — Playwright fixtures, API mocks, SSE helpers, mock data
  - `tests/mocked/` — Deterministic E2E tests (mocked API + injected SSE events)
  - `tests/integration/` — Smoke test against real backend

### Key Patterns

- **Stateless backend:** No Doctrine ORM. State Providers/Processors instead of controllers+repositories. DTOs use `Request`/`Response` suffixes.
- **Local-first frontend:** Zustand (in-memory, no persist) + Zod validation. Trip state is managed entirely in-memory via Zustand + Immer; computation results arrive via Mercure SSE events.
- **GPX processing:** XMLReader stream parsing (O(constant) memory) → elevation smoothing (3m threshold) → Douglas-Peucker decimation (20m tolerance, ~25k→1.5k points).
- **Async processing:** Symfony Messenger with Redis transport. Trip computations run asynchronously across 5 workers; status updates are pushed to the frontend via Mercure SSE.
- **External API caching:** Redis cache for trip state (30min TTL). Filesystem cache for OSM data (24h) and weather (3h). Scoped HTTP clients prevent SSRF.
- **Pacing formula:** `target_day_n = base_target * (0.9 ^ (n-1)) - (elevation_gain / 50)` with 30km minimum threshold.
- **Alert engine:** New rules implement `StageAnalyzerInterface` and are auto-discovered via `#[AutowireIterator]`. Priority integers control execution order.
- **E2E testing:** Mocked tests use `page.route()` for API interception and `CustomEvent('__test_mercure_event')` for SSE injection. Integration smoke test runs against the real backend.

### Security Constraints

- Supported source URLs (Strategy pattern via `RouteFetcherRegistry`):
  - Komoot tour: `^https://www\.komoot\.com/([a-z]{2}-[a-z]{2}/)?tour/\d+`
  - Komoot collection: `^https://www\.komoot\.com/([a-z]{2}-[a-z]{2}/)?collection/\d+`
  - Google MyMaps: `^https://www\.google\.com/maps/d/` and `^https://maps\.app\.goo\.gl/`
- HTTP clients scoped to specific base URIs, max 2 redirects, 10s timeout
- XMLReader hardened with `LIBXML_NONET` + `LIBXML_NOENT` (XXE prevention)
- Upload limits: 15MB (Caddy + PHP), 128MB PHP memory limit

## ADR Documentation

Architecture Decision Records in `docs/adr/` document all major technical choices with context, alternatives considered, and rationale. Consult these before proposing architectural changes.

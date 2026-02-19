# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bike Trip Planner — a local-first bikepacking trip planner. Decoupled architecture: PHP backend (API Platform on Symfony 8) provides stateless computation, Next.js 16 frontend manages presentation and browser-based state. No persistent cloud database; all trip data lives in browser localStorage.

## Tech Stack

- **Backend:** PHP 8.5, API Platform 4.2, Symfony 8, FrankenPHP (Docker)
- **Frontend:** Next.js 16 (App Router), React 19, TypeScript (strict), Zustand + Immer, Tailwind CSS
- **PDF:** Gotenberg 8 microservice (headless Chromium via Twig templates)
- **Testing:** PHPUnit 13 (backend), Playwright 1.58 (E2E)
- **Quality:** PHPStan Level 9, PHP-CS-Fixer (PSR-12/Symfony), ESLint, Prettier

## Common Commands

All orchestrated via Makefile. Run `make help` for full list.

```bash
make start              # Boot Docker environment (php, pwa, gotenberg)
make install            # Install Composer + NPM dependencies
make qa                 # Full QA: PHPStan + PHP-CS-Fixer + ESLint + Prettier + TS checks
make test               # Full suite: QA → PHPUnit → Playwright
make test-php           # PHPUnit 13 only
make test-e2e           # Playwright E2E only
make php-shell          # Bash inside PHP container
make pwa-shell          # Bash inside Node container
```

Running specific tools inside containers:

```bash
docker compose exec php vendor/bin/phpstan analyse -l 9 src/
docker compose exec php vendor/bin/phpunit
docker compose exec php vendor/bin/phpunit --filter=TestClassName
docker compose exec pwa npx playwright test
docker compose exec pwa npx playwright test tests/specific-test.spec.ts
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
  - `src/Analyzer/` — Rule-based alert engine using Chain of Responsibility with Symfony tagged services (`#[AutoconfigureTag('app.stage_analyzer')]`)
  - `templates/` — Twig templates for PDF roadbook
- `pwa/` — Next.js frontend
  - `src/store/` — Zustand stores with persist middleware (localStorage)
  - `src/lib/api/` — Generated types (`schema.d.ts`) and openapi-fetch client
  - `src/lib/validation/` — Zod schemas (manually aligned with PHP DTOs)
  - `tests/` — Playwright E2E tests

### Key Patterns

- **Stateless backend:** No Doctrine ORM. State Providers/Processors instead of controllers+repositories. DTOs use `Request`/`Response` suffixes.
- **Local-first frontend:** Zustand persist + Zod validation. Data migrations via Zustand `version` + `migrate` callback. Corrupted localStorage is wiped gracefully rather than crashing.
- **GPX processing:** XMLReader stream parsing (O(constant) memory) → elevation smoothing (3m threshold) → Douglas-Peucker decimation (20m tolerance, ~25k→1.5k points).
- **External API caching:** Symfony CachingHttpClient with FilesystemAdapter. OSM data cached 24h, weather 3h. Scoped HTTP clients prevent SSRF.
- **Pacing formula:** `target_day_n = base_target * (0.9 ^ (n-1)) - (elevation_gain / 50)` with 30km minimum threshold.
- **Alert engine:** New rules implement `StageAnalyzerInterface` and are auto-discovered via `#[TaggedIterator]`. Priority integers control execution order.
- **PDF export:** Backend renders Twig → sends HTML to Gotenberg → returns PDF stream. Template uses Tailwind CDN.

### Security Constraints

- Komoot URLs allowlisted via regex: `^https://www\.komoot\.com/(tour|collection)/\d+`
- HTTP clients scoped to specific base URIs, max 2 redirects, 10s timeout
- XMLReader hardened with `LIBXML_NONET` + `LIBXML_NOENT` (XXE prevention)
- Upload limits: 15MB (Nginx + PHP), 128MB PHP memory limit

## ADR Documentation

Architecture Decision Records in `docs/adr/` document all major technical choices with context, alternatives considered, and rationale. Consult these before proposing architectural changes.

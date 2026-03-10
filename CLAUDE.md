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
make rector
make phpunit
make phpunit -- --filter=TestClassName
make test-e2e
make test-e2e -- tests/specific-test.spec.ts
make typegen                # Regenerate TS types from backend OpenAPI spec
```

Pre-commit hook runs `make qa` automatically; commit aborts on failure.

### Claude Code Skills

```bash
/pick <issue-number> [base-branch]  # Implement a GitHub issue end-to-end
/code-review <pr-number>            # Code review a PR (multi-agent, inline comments)
/qa                                 # Run full QA pipeline and fix issues
/typegen                            # Regenerate TS types from backend
```

## Git Conventions

Commit messages **must** follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
<type>(<optional scope>): <description>
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`
- **Scopes** (optional): subsystem or area, e.g. `feat(overpass):`, `fix(pacing):`, `chore(deps):`
- **Description:** imperative mood, lowercase, no trailing period
- Breaking changes: add `!` after type/scope, e.g. `feat!: remove legacy endpoint`

## Architecture

### Type Contract (Single Source of Truth)

Backend PHP DTOs define the schema → API Platform exports OpenAPI spec → `npm run typegen` (openapi-typescript) generates TypeScript types → openapi-fetch provides type-safe API calls. Schema changes on backend intentionally cause frontend compilation failures to prevent data drift.

### Directory Layout

- `api/` — PHP backend (API Platform/Symfony)
  - `src/Accommodation/` — Accommodation discovery, scraping, and pricing heuristics
  - `src/Analyzer/` — Rule-based alert engine using Chain of Responsibility with Symfony tagged services (`#[AutoconfigureTag('app.stage_analyzer')]`)
  - `src/ApiResource/` — DTOs (TripRequest, TripResponse, Stage, Alert, Accommodation)
  - `src/ComputationTracker/` — Cache-based async computation state tracking (pending/running/done/failed)
  - `src/Controller/` — HTTP controllers (AccommodationScraper, Geocode)
  - `src/DependencyInjection/` — Symfony compiler passes for tagged service registries
  - `src/Engine/` — Pluggable computation engines (auto-discovered via `#[AutoconfigureTag]`)
  - `src/Enum/` — PHP enums (ComputationName, AlertType, SourceType)
  - `src/Mercure/` — SSE event publisher
  - `src/Message/` — Symfony Messenger message classes (async tasks)
  - `src/MessageHandler/` — Async message handlers (Symfony Messenger)
  - `src/Repository/` — In-memory cache repository for trip state
  - `src/RouteFetcher/` — URL fetchers (Komoot Tour, Komoot Collection, Google MyMaps)
  - `src/RouteParser/` — Route parsers (GPX, KML)
  - `src/Routing/` — Pluggable routing providers (Valhalla)
  - `src/Scanner/` — OSM Overpass scanning with local/public fallback and caching
  - `src/Serializer/` — GPX/FIT normalizers, encoders, unified WaypointMapper
  - `src/State/` — API Platform State Processors & Providers
  - `src/Symfony/` — ObjectMapper transformers for DTO conversion
  - `src/Weather/` — Weather providers (OpenMeteo, OpenWeather)
  - `templates/` — Twig templates (PDF roadbook)
- `pwa/` — Next.js frontend
  - `src/store/` — Zustand stores (in-memory, Immer middleware)
  - `src/lib/api/` — Generated types (`schema.d.ts`) and openapi-fetch client
  - `src/lib/geocode/` — Place search and reverse geocoding client
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

## PR & Quality Standards

Before every PR, follow this protocol:

### 0. Code Quality Principles

All code must follow these principles as much as possible:

- **SOLID** — Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Law of Demeter** — Only talk to immediate collaborators; avoid deep chaining through objects
- **Design patterns over quick & dirty** — Prefer well-known patterns (Strategy, Chain of Responsibility, etc.) to ad-hoc solutions
- **Documented, tested, maintainable** — Unit tests, functional tests, E2E tests, and Diataxis-style documentation (tutorials, how-to, reference, explanation)

If any of these principles cannot be followed in a specific case, **document the reason** in a code comment explaining why the deviation was necessary.

### 1. Diff Analysis

Run `git diff` and review all changes for:

- Leftover `console.log`, `dump()`, `dd()`, or debug statements
- Stale TODO/FIXME comments
- Dead code (unused methods, unreachable branches, orphaned imports)
- Unintended technical debt

### 2. Review Checklist

- [ ] Code respects the project architecture (stateless backend, local-first frontend, DTO contract)
- [ ] SOLID principles and Law of Demeter are followed (deviations documented)
- [ ] Design patterns are used where appropriate (no unjustified quick & dirty)
- [ ] Tests cover new/changed cases: unit (`make test-php`), E2E (`make test-e2e`)
- [ ] No dead code (unused methods, unreachable branches, orphaned imports)
- [ ] No lingering TODO/FIXME comments (resolve or create a ticket)
- [ ] Documentation (PHPDoc, JSDoc, Diataxis docs) is up to date for modified public APIs
- [ ] Dependent tickets (if applicable) are accounted for

### 3. PR Protocol

1. Create the PR as **Ready for review** (only use Draft if changes are still pending and not yet pushed)
2. Wait for CI to pass: `gh pr checks --watch`

### 4. Auto-critique

Include an **Auto-critique** section in the PR body listing what was verified.

## Review Comment Format

All code review comments (CI workflow, `/code-review`, `/review`) follow [Conventional Comments](https://conventionalcomments.org/):

```text
<label> (<decoration>): <subject>

<body>
```

### Labels

| Severity | Label |
|----------|-------|
| Critical (blocking) | `issue (blocking): <subject>` |
| Warning | `issue: <subject>` |
| Info / suggestion | `suggestion (non-blocking): <subject>` |
| Nitpick | `nitpick (non-blocking): <subject>` |
| Positive feedback | `praise: <subject>` |

### Inline Comments

- Each code-level finding gets its own **inline thread** on the relevant line(s)
- ALWAYS include a concrete fix using a GitHub ` ```suggestion ` block when applicable
- Keep suggestions minimal: only change what is necessary

### Review Body

The review submission body contains only PR-level findings:

- Concise summary (1-3 sentences)
- PR title conventional commit check (if issue found)
- Review checklist (checked/unchecked items)
- Count of inline comments posted
- Footer: "Generated with [Claude Code](https://claude.ai/code)"

### Conventional Commits on PRs

Since PRs are **squash-merged**, only the **PR title** must follow Conventional Commits format. Do NOT check individual commit messages.

## ADR Documentation

Architecture Decision Records in `docs/adr/` document all major technical choices with context, alternatives considered, and rationale. Consult these before proposing architectural changes.

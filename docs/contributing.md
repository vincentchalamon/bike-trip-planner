# Contributing

This guide covers everything you need to contribute to Bike Trip Planner: setting up your development environment, configuring AI-assisted tooling, and following the project's quality standards.

---

## Local development environment

Follow [Getting Started](getting-started.md) to install and run the application. For development purposes, use:

```bash
make start-dev
```

This boots multiple services in development mode:

| Service     | URL                                         | Description                 |
|-------------|---------------------------------------------|-----------------------------|
| `php`       | `https://localhost/docs`                    | API Platform backend        |
| `pwa`       | `https://localhost`                         | Next.js frontend            |
| `worker`    | Internal only                               | Async messages worker       |
| `mercure`   | `https://localhost/.well-known/mercure/ui/` | Server-push microservice    |
| `redis`     | Internal only                               | Cache microservice          |
| `caddy`     | Internal only                               | Web server microservice     |

> **TLS:** Caddy generates a self-signed certificate for `localhost`. Accept the browser warning on first load, or install the certificate into your system trust store.

Once it is running, you have a fully functional development environment — both the PHP backend and the Next.js frontend support hot-reload out of the box.

### Useful development commands

```bash
make php-shell    # Enter the PHP container
make pwa-shell    # Enter the Node container
make qa           # Run the full QA pipeline
make test         # Run QA + PHPUnit + Playwright
make php-shell    # Bash inside the PHP container
make pwa-shell    # Bash inside the Node container
```

See `make help` for the full list of available targets.

---

## Development workflow

### 1. Work on a feature branch

```bash
git checkout -b feat/my-feature
```

### 2. Make changes, then verify

```bash
make qa           # Must pass before every commit
```

The pre-commit hook runs `make qa` automatically. A commit is rejected if QA fails.

### 3. Run targeted tests

```bash
# PHP unit tests only
make test-php -- --filter=MyTestClass

# A specific Playwright test
make test-e2e -- tests/my-feature.spec.ts
```

#### E2E testing architecture

Playwright tests are split into two categories under `pwa/tests/`:

| Directory       | Purpose                                    | Backend required? |
|-----------------|--------------------------------------------|-------------------|
| `mocked/`       | Deterministic tests with mocked API + SSE  | No                |
| `integration/`  | Smoke test against the real backend        | Yes               |

**Mocked tests** are the primary E2E strategy. They intercept all HTTP calls and inject Mercure events programmatically, making them fast, deterministic, and CI-friendly.

##### Fixtures (`pwa/tests/fixtures/`)

All mocked tests extend a custom Playwright fixture defined in `base.fixture.ts` that provides:

| Fixture          | Description                                                              |
|------------------|--------------------------------------------------------------------------|
| `mockedPage`     | A `Page` with all API routes pre-mocked and navigated to `/`            |
| `injectEvent`    | Injects a single Mercure SSE event into the page                         |
| `injectSequence` | Injects an ordered sequence of events with configurable delay            |
| `submitUrl`      | Fills the URL input and submits, waiting for the trip skeleton to appear |
| `createFullTrip` | Shortcut: submits a URL then injects the full event sequence             |

##### API mocking with `page.route()`

`api-mocks.ts` registers Playwright route handlers for every backend endpoint (POST/PATCH/DELETE trips, stages, geocoding, accommodations, etc.). Each handler returns a static JSON response, and options allow simulating errors:

```typescript
// Example: create a test with a failing stage deletion
test.use({ mockOptions: { deleteStageFail: true } });
```

The real Mercure SSE endpoint (`/.well-known/mercure`) is **aborted** via `page.route()` to prevent the frontend from opening a real EventSource connection.

##### SSE injection via `CustomEvent`

Since the real SSE connection is aborted, events are injected from test code through the browser's `CustomEvent` API:

```typescript
// sse-helpers.ts — injects a typed MercureEvent into the page
await page.evaluate((evt) => {
  window.dispatchEvent(
    new CustomEvent("__test_mercure_event", { detail: evt }),
  );
}, event);
```

The `MercureClient` class in `src/lib/mercure/client.ts` listens for these custom events in addition to real SSE messages, enabling seamless test injection without any production code changes.

##### Mock data (`mock-data.ts`)

Provides factory functions for every Mercure event type (`routeParsedEvent()`, `stagesComputedEvent()`, `weatherFetchedEvent()`, etc.), assembled into a `fullTripEventSequence()` that simulates a complete trip computation lifecycle.

##### Writing a new mocked E2E test

```typescript
import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

test("my feature works", async ({ mockedPage, submitUrl, injectEvent }) => {
  await submitUrl();
  await injectEvent(routeParsedEvent());
  await injectEvent(stagesComputedEvent());
  // Assert on the rendered page
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
});
```

### 4. After changing backend DTOs, regenerate types

```bash
make start-dev                  # Backend must be running
make typegen
```

TypeScript compilation will fail until types are regenerated — this is intentional. The type contract (see [ADR-002](adr/adr-002-interface-contract-and-strict-typing.md)) enforces frontend/backend consistency at compile time.

---

## Code quality standards

### PHP

| Tool         | Standard                 | Command             |
|--------------|--------------------------|---------------------|
| PHPStan      | Level 9 (strict)         | `make phpstan`      |
| PHP-CS-Fixer | PSR-12 + Symfony rules   | `make php-cs-fixer` |
| PHPUnit      | Unit + integration tests | `make test-php`     |

**Key conventions:**

- State Providers and Processors, never controllers with repositories (stateless backend)
- DTOs use `Request`/`Response` suffixes (`TripRequest`, `TripResponse`)
- New alert rules implement `StageAnalyzerInterface` and are tagged with `#[AutoconfigureTag('app.stage_analyzer')]`; no other registration needed
- HTTP clients must be scoped to a base URI — never allow free-form URL fetching (SSRF prevention)

### TypeScript / Frontend

| Tool       | Standard        | Command                      |
|------------|-----------------|------------------------------|
| TypeScript | Strict mode     | `make tsc`                   |
| ESLint     | Next.js ruleset | `make eslint`                |
| Prettier   | Project config  | `make prettier -- --write .` |
| Playwright | E2E tests       | `make test-e2e`              |

**Key conventions:**

- All API calls go through the `openapi-fetch` client — never use `fetch` directly
- State lives in Zustand stores (in-memory, Immer middleware); never in component state for trip data
- Computation results arrive via Mercure SSE events and are dispatched through `CustomEvent('__test_mercure_event')` in tests
- Zod schemas in `src/lib/validation/` must stay manually aligned with PHP DTOs

### Architecture decisions

Before proposing a significant architectural change, read the relevant ADR in `docs/adr/`. If no ADR covers your change, write one. ADRs document the context, alternatives considered, and rationale — not just the decision.

---

## Claude Code setup (AI-assisted development)

The project is configured for [Claude Code](https://claude.ai/code). The setup below gives Claude accurate, project-specific context and automates repetitive tasks.

### Hooks (auto-configured)

The `.claude/settings.json` file configures three hooks that run automatically:

| Hook                 | Trigger                              | Effect                                                            |
|----------------------|--------------------------------------|-------------------------------------------------------------------|
| `PostToolUse` (PHP CS Fixer) | Any `.php` file written/edited       | Runs `php-cs-fixer` on the file                                   |
| `PostToolUse` (Rector)       | Any `.php` file written/edited       | Runs `rector` on the file                                         |
| `PostToolUse` (Prettier)     | Any `.ts`/`.tsx` file written/edited | Runs `prettier` on the file                                       |
| `PreToolUse` (guard)         | Any write/edit                       | Blocks edits to `.env`, `schema.d.ts`, `vendor/`, `node_modules/` |

These hooks are project-scoped and apply to all contributors who use Claude Code on this project.

### Skills (slash commands)

Two custom skills are available in Claude Code:

| Command                              | Description                                                                  |
|--------------------------------------|------------------------------------------------------------------------------|
| `/pick <issue-number> [base-branch]` | Implements a GitHub issue end-to-end (branch, code, test, PR, CI monitoring) |
| `/sprint <sprint-number>`            | Implements all sprint issues in parallel via worktree agents                 |

### GitHub automation

Claude is also available directly from GitHub, without needing a local Claude Code session:

| Trigger                                   | Where           | What happens                                                                              |
|-------------------------------------------|-----------------|-------------------------------------------------------------------------------------------|
| `@claude pick [base-branch]`              | Issue comment    | Claude implements the issue end-to-end: creates branch, codes, opens PR, monitors CI     |
| `@claude <instruction>`                   | Issue/PR comment | Claude follows the free-form instruction                                                 |
| Automatic (on PR open/sync)               | Pull requests    | Claude performs an automated code review (`claude-code-review.yml`)                       |

The workflows are defined in `.github/workflows/claude.yml` and `.github/workflows/claude-code-review.yml`.

### MCP servers

The `.mcp.json` at the project root configures the **Apidog MCP server**, which loads the live OpenAPI spec from `https://localhost/docs.json` as Claude context. This enables:

- Validating frontend code against the current API contract
- Generating type-safe API client code from endpoints
- Catching DTO/TypeScript drift without running the full QA pipeline

> **Requirement:** The PHP backend must be running (`make start-dev`) for the Apidog MCP server to fetch the spec.

### Recommended additional tools

See [docs/claude-code-tooling.md](claude-code-tooling.md) for the full guide, including:

- **GitHub MCP Server** — manage PRs and issues from within Claude Code
- **Context7** — query up-to-date Symfony, Next.js, and API Platform documentation (already installed)
- **Playwright MCP** — browser automation for UI validation and E2E debugging (already installed)

---

## Project structure reference

<!-- markdownlint-disable MD040 -->
```
bike-trip-planner/
├── api/                          # PHP backend
│   ├── src/
│   │   ├── ApiResource/          # API Platform DTOs (single source of truth)
│   │   ├── State/                # State Providers & Processors
│   │   ├── Spatial/              # GPX parsing, decimation
│   │   ├── Pacing/               # Stage generation algorithm
│   │   ├── Osm/                  # Overpass API queries
│   │   ├── Pricing/              # Accommodation heuristic pricing
│   │   └── Analyzer/             # Alert engine (Chain of Responsibility)
│   └── templates/                # Twig templates
├── pwa/                          # Next.js frontend
│   ├── src/
│   │   ├── app/                  # Next.js App Router pages
│   │   ├── store/                # Zustand stores (in-memory, Immer)
│   │   ├── lib/
│   │   │   ├── api/              # Generated types (schema.d.ts) + openapi-fetch client
│   │   │   └── validation/       # Zod schemas
│   │   └── components/           # React components
│   └── tests/                    # Playwright E2E tests
│       ├── fixtures/             # Test fixtures, API mocks, SSE helpers
│       ├── mocked/               # Deterministic tests (mocked API + SSE)
│       └── integration/          # Smoke test against real backend
├── docs/
│   ├── adr/                      # Architecture Decision Records
│   ├── getting-started.md
│   ├── contributing.md           # This file
│   └── claude-code-tooling.md
├── .github/
│   └── workflows/
│       ├── claude.yml              # @claude pick + free-form on issues/PRs
│       └── claude-code-review.yml  # Automated PR code review
├── .claude/
│   ├── settings.json             # Hooks (auto-formatting, file protection)
│   └── skills/                   # Custom slash commands (pick, sprint)
└── .mcp.json                     # MCP server config (Apidog OpenAPI)
```

---

## Submitting changes

1. Ensure `make qa` passes locally
2. Write or update tests for any changed behavior
3. If you changed backend DTOs, include the regenerated `pwa/src/lib/api/schema.d.ts`
4. Open a pull request with a clear description of what changed and why
5. Reference any relevant ADR if the change affects architecture

For significant changes, open an issue first to discuss the approach before investing in implementation.

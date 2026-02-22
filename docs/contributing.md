# Contributing

This guide covers everything you need to contribute to Bike Trip Planner: setting up your development environment, configuring AI-assisted tooling, and following the project's quality standards.

---

## Local development environment

Follow [Getting Started](getting-started.md) to install and run the application. Once it is running, you have a fully functional development environment — both the PHP backend and the Next.js frontend support hot-reload out of the box.

### Useful development commands

```bash
make php-shell    # Enter the PHP container
make pwa-shell    # Enter the Node container
make qa           # Run the full QA pipeline
make test         # Run QA + PHPUnit + Playwright
```

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

### 4. After changing backend DTOs, regenerate types

```bash
make start                      # Backend must be running
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
- State lives in Zustand stores with `persist` middleware; never in component state for trip data
- Zod schemas in `src/lib/validation/` must stay manually aligned with PHP DTOs
- When removing a field from the store, bump the Zustand `version` and add a `migrate` callback

### Architecture decisions

Before proposing a significant architectural change, read the relevant ADR in `docs/adr/`. If no ADR covers your change, write one. ADRs document the context, alternatives considered, and rationale — not just the decision.

---

## Claude Code setup (AI-assisted development)

The project is configured for [Claude Code](https://claude.ai/code). The setup below gives Claude accurate, project-specific context and automates repetitive tasks.

### Hooks (auto-configured)

The `.claude/settings.json` file configures three hooks that run automatically:

| Hook                 | Trigger                              | Effect                                                            |
|----------------------|--------------------------------------|-------------------------------------------------------------------|
| `PostToolUse` (PHP)  | Any `.php` file written/edited       | Runs `php-cs-fixer` on the file                                   |
| `PostToolUse` (TS)   | Any `.ts`/`.tsx` file written/edited | Runs `prettier` on the file                                       |
| `PreToolUse` (guard) | Any write/edit                       | Blocks edits to `.env`, `schema.d.ts`, `vendor/`, `node_modules/` |

These hooks are project-scoped and apply to all contributors who use Claude Code on this project.

### Skills (slash commands)

Two custom skills are available:

| Command    | Description                                                                         |
|------------|-------------------------------------------------------------------------------------|
| `/qa`      | Runs `make qa`, parses output, and proposes fixes for each issue                    |
| `/typegen` | Regenerates TypeScript types from the backend OpenAPI spec and verifies compilation |

### MCP servers

The `.mcp.json` at the project root configures the **Apidog MCP server**, which loads the live OpenAPI spec from `https://localhost/docs.json` as Claude context. This enables:

- Validating frontend code against the current API contract
- Generating type-safe API client code from endpoints
- Catching DTO/TypeScript drift without running the full QA pipeline

> **Requirement:** The PHP backend must be running (`make start`) for the Apidog MCP server to fetch the spec.

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
│   └── templates/                # Twig templates for PDF roadbook
├── pwa/                          # Next.js frontend
│   ├── src/
│   │   ├── app/                  # Next.js App Router pages
│   │   ├── store/                # Zustand stores (localStorage persistence)
│   │   ├── lib/
│   │   │   ├── api/              # Generated types (schema.d.ts) + openapi-fetch client
│   │   │   └── validation/       # Zod schemas
│   │   └── components/           # React components
│   └── tests/                    # Playwright E2E tests
├── docs/
│   ├── adr/                      # Architecture Decision Records
│   ├── getting-started.md
│   ├── contributing.md           # This file
│   └── claude-code-tooling.md
├── .claude/
│   ├── settings.json             # Hooks (auto-formatting, file protection)
│   └── skills/                   # Custom slash commands (qa, typegen)
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

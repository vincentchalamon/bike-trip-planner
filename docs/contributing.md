# Contributing

Task-oriented recipes for changing Bike Trip Planner. If you have never run the project, follow
[Getting Started](getting-started.md) first. For the reasoning behind the design, read
[Architecture](architecture.md) and the ADRs it links to.

## Choose the right local stack

| Command              | Stack                                                                 | Use it to                                   |
|----------------------|-----------------------------------------------------------------------|---------------------------------------------|
| `make start-dev`     | `compose.yaml` + `compose.dev.yaml`: sources mounted, hot reload, Mailcatcher, Xdebug available | develop                                     |
| `make start`         | `compose.yaml` only, with generated local secrets: the production image and its fail-closed checks | reproduce a production-only behaviour       |
| `make start-recette` | `compose.yaml` + `compose.recette.yaml`: iso-prod plus Mailcatcher    | run the manual recette or `make test-recette` |
| `make routing-up`    | adds the Valhalla service, once a graph exists (`make routing-build <country>`) | work on stage rerouting                     |

`make stop` stops the containers, `make clean` also deletes the volumes. `make help` lists every
target.

## Make a change

1. Create a branch from `main`.
2. Commit with [Conventional Commits](https://www.conventionalcommits.org/):
   `<type>(<optional scope>): <description>`, imperative and lowercase, for example
   `fix(pacing): keep the last stage above the minimum distance`.
3. Before opening a pull request, run the quality checks and the tests below.

## Run the quality checks

| Stack      | Tool         | Standard                    | Command             |
|------------|--------------|-----------------------------|---------------------|
| PHP        | PHPStan      | level 9                     | `make phpstan`      |
| PHP        | PHP-CS-Fixer | PSR-12 + Symfony rules      | `make php-cs-fixer` (fixes in place) |
| PHP        | Rector       | automated refactoring       | `make rector` (fixes in place) |
| TypeScript | tsc          | strict mode                 | `make tsc`          |
| TypeScript | ESLint       | Next.js rules               | `make eslint`       |
| TypeScript | Prettier     | project config              | `make prettier` (check only) |
| TypeScript | i18n check   | every key in every locale   | `make i18n-check`   |
| Docs       | markdownlint | `.markdownlint.yaml`        | `make markdownlint` |
| Docs       | link check   | internal links and anchors  | `make link-check`   |

The PHP targets run on both `api/` and `provisioner/`. `make qa` chains all of the above except
the link check. On a laptop, `make qa` can be killed for lack of memory during Rector or PHPStan
(the `php` service is capped at 768 MB); run the legs one by one if that happens. CI runs every
check on each pull request and is the gate that counts.

## Run the tests

| Suite                              | Command                                          | Needs            |
|------------------------------------|--------------------------------------------------|------------------|
| PHPUnit, `api/` and `provisioner/` | `make test-php`                                  | dev stack        |
| One PHPUnit test                   | `docker compose exec php vendor/bin/phpunit --filter=MyTest` | dev stack |
| Vitest (web unit tests)            | `make test-pwa`                                  | dev stack        |
| Playwright E2E                     | `make test-e2e`                                  | a stack on `https://localhost` |
| One Playwright spec                | `make test-e2e -- tests/mocked/my-feature.spec.ts` | a stack on `https://localhost` |
| BDD recette scenarios (Gherkin)    | `make test-recette`                              | recette stack    |
| OpenAPI lint                       | `make openapi-lint`                              | dev stack        |
| Security advisories                | `make security-check`                            | dev stack        |
| Mobile type check and Jest         | `npm run typecheck --workspace mobile` then `npm test --workspace mobile` | `npm install` at the repo root |

`make test` runs `qa`, PHPUnit, Playwright, the OpenAPI lint and the security check in sequence.

## Write a mocked end-to-end test

Playwright tests live in `pwa/tests/`. `mocked/` holds the deterministic suite, which runs without
a real backend computation; `integration/` holds a smoke test against the real backend.

A mocked test extends the fixture in `pwa/tests/fixtures/base.fixture.ts`:

| Fixture          | What it gives you                                                        |
|------------------|--------------------------------------------------------------------------|
| `mockedPage`     | a page with every API route mocked (`api-mocks.ts`), opened on `/`       |
| `injectEvent`    | injects one Mercure event into the page                                  |
| `injectSequence` | injects an ordered list of events with a delay between them              |
| `submitUrl`      | fills the route URL input, submits and waits for the trip skeleton       |
| `createFullTrip` | `submitUrl`, then the whole event sequence of a computation              |
| `mockOptions`    | per-test switches in `api-mocks.ts`, for example `{ deleteStageFail: true }` |

The real hub (`/.well-known/mercure`) is aborted. Events are injected with a
`CustomEvent('__test_mercure_event')`, which `pwa/src/lib/mercure/client.ts` listens to alongside
real SSE messages. Event factories (`routeParsedEvent()`, `stagesComputedEvent()`,
`fullTripEventSequence()`, ...) are in `pwa/tests/fixtures/mock-data.ts`.

```typescript
import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

test("my feature works", async ({ mockedPage, submitUrl, injectEvent }) => {
  await submitUrl();
  await injectEvent(routeParsedEvent());
  await injectEvent(stagesComputedEvent());
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
});
```

Add a matching mock in `api-mocks.ts` when your feature calls a new endpoint.

## Change the API contract

The PHP resources are the single source of truth for the schema
([ADR-002](adr/adr-002-interface-contract-and-strict-typing.md)).

1. Change the DTO or resource in `api/src/ApiResource/`.
2. With the dev stack running, run `make typegen`. It exports the OpenAPI document to
   `pwa/openapi.json` and regenerates `core/schema.d.ts`, shared by the web and mobile apps.
3. Fix the TypeScript errors that follow: they are the point.
4. Commit the regenerated `core/schema.d.ts`. The CI job `openapi-typegen-drift` fails if it does
   not match the backend.

Backend conventions: custom State Providers and Processors rather than Doctrine auto-CRUD,
`Request`/`Response` suffixes on DTOs, and HTTP clients scoped to a fixed base URI (no free-form
URL fetching, [ADR-011](adr/adr-011-security-input-validation-and-ssrf-prevention-for-gpx-url-ingestion.md)).
Frontend conventions: API calls through the typed `openapi-fetch` client, trip state in the Zustand
stores, shared schema, Mercure types and reconciliation logic in `@btp/core` rather than copied per
platform.

## Add or change an alert rule

1. Implement `App\Analyzer\StageAnalyzerInterface`. The interface carries the
   `app.stage_analyzer` tag, so no registration is needed; `getPriority()` orders execution.
   Checks that need their own async step live in `api/src/MessageHandler/` instead.
2. Add a case to `App\Enum\AlertCode` for each rule variant.
3. Add exactly one row per code to the table in [alert-engine.md](alert-engine.md).

`AlertDocumentationTest` scans `api/src` and fails in both directions: a code without a row, or a
row without a code. Rewording a message never changes its code, because clients key dismissal on
it.

## Regenerate the documentation screenshots

After a visible UI change, with the dev stack running:

```bash
make screenshots
```

The script (`pwa/tests/screenshots/capture.spec.ts`) drives the mocked harness and writes to
`docs/assets/screenshots/` and `pwa/public/images/`. Review the images before committing them.

## Record an architecture decision

Read the related ADRs in `docs/adr/` before proposing a structural change. If none covers it, add
the next numbered `adr-NNN-<slug>.md` with its context, the options considered and the rationale,
and add it to the `nav` of `mkdocs.yml` (the docs build is strict).

## Open a pull request

1. Quality checks and tests pass, and new behaviour has tests.
2. A contract change includes the regenerated `core/schema.d.ts`.
3. The description says what changed and why, links the ADR when the architecture moves, and
   closes the issue with an English keyword (`Closes #123`).

Every pull request gets an automated review from `claude-code-review.yml`, and same-repository
pull requests get a preview deployment (see [Deployment](deployment.md)). For AI-assisted
development, see [Claude Code tooling](claude-code-tooling.md).

## Repository layout

| Path           | Content                                                                 |
|----------------|-------------------------------------------------------------------------|
| `api/`         | Symfony + API Platform backend, workers, MCP server                     |
| `provisioner/` | CLI that imports OpenStreetMap, DataTourisme and OpenAgenda data into PostGIS |
| `pwa/`         | Next.js web app and its Playwright suites                               |
| `mobile/`      | Expo / React Native app                                                  |
| `core/`        | `@btp/core`: generated schema, Mercure types, shared reducers           |
| `.docker/`     | Dockerfiles, Caddyfile, auxiliary stacks                                |
| `deploy/`      | Compose overlays for production, previews and the shared Valhalla       |
| `ansible/`     | Provisioning of the production VM                                       |
| `docs/`        | This documentation, ADRs and runbooks                                   |

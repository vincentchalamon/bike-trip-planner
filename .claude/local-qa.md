# Local QA & test recipes

Operational recipes for running QA and tests on a dev machine (and in `/sprint` worktrees).
Loaded on demand by the `check` and `sprint` skills — kept out of `CLAUDE.md` so it is not
re-prefilled into every session. Read this only when you actually run QA/tests locally.

## Local QA: `make qa` cannot complete on a dev machine

**`make qa` dies at the `rector` leg with `Child process error: Killed` (exit 137). This is not your diff.** The `php` service is capped at `deploy.resources.limits.memory: 768M` in `compose.yaml`, Rector spawns ~13 parallel workers, and it has no `--no-parallel` flag. Same root cause for `make phpstan`. In a worktree, `make qa-pwa` additionally fails with `eslint: not found` because each compose project gets its own **empty** `pwa_node_modules` named volume.

Do not spend turns diagnosing this, and do not conclude the branch is broken. Run the legs individually instead, and paste **their** output as the evidence:

```bash
# Rector + PHPStan: one-off container outside the compose memory cap
docker run --rm -m 4g -v "$PWD/api:/app" -w /app --entrypoint sh bike-trip-planner-php:dev \
  -c "vendor/bin/rector process --dry-run"
docker run --rm -m 4g -v "$PWD/api:/app" -w /app -e APP_ENV=dev --entrypoint sh bike-trip-planner-php:dev \
  -c "bin/console cache:warmup -e dev >/dev/null && vendor/bin/phpstan analyse -c phpstan.dist.neon --memory-limit=3G --no-progress"

# PHP-CS-Fixer (fine inside compose)
docker run --rm -v "$PWD/api:/app" -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 --entrypoint php \
  bike-trip-planner-php:dev vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

# Frontend legs: run the binaries directly (needs a populated pwa/node_modules)
# NB: the npm script is `i18n:check` with a COLON. The hyphen belongs to the Makefile
# target (`make i18n-check`) and to the .mjs filename; `npm run i18n-check` fails with
# `Missing script`. In a worktree the Make target is unusable anyway — it goes through
# `docker compose run --no-deps pwa`, i.e. the empty node_modules volume.
cd pwa && node_modules/.bin/tsc --noEmit && node_modules/.bin/eslint <files> \
  && npx --yes prettier@3.9.6 --check <files> && npm run i18n:check
```

Run each leg so you actually see its output. Piping through `| tail` hides an `npm error Missing script` behind the pipe's zero exit status, which reads as a silent pass — that trap produced two unfounded "i18n green" claims during Sprint 44.

**PHPUnit including the Functional and Integration suites** needs Postgres + Redis + a JWT keypair. Point a throwaway container at the *main* stack's network (`make start-dev` in the main checkout) rather than booting a second stack:

Every command below runs **from the worktree root** — the `docker run` resolves `$PWD/api` and `$PWD/docs`, so a stray `cd` into `api/` silently points the mounts at `api/api` and `api/docs`. The keypair step is wrapped in a subshell for that reason.

```bash
# One-time, inside the worktree: a test keypair with the passphrase CI uses
(cd api && openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:test \
  && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem -passin pass:test)

docker run --rm --network bike-trip-planner_default -e APP_ENV=test -e XDEBUG_MODE=off -e JWT_PASSPHRASE=test \
  -e DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18&charset=utf8" \
  -e REDIS_URL="redis://redis:6379" -e FRONTEND_URL="https://localhost" \
  -v "$PWD/api:/app" -v "$PWD/docs:/docs:ro" \
  -w /app --entrypoint vendor/bin/phpunit bike-trip-planner-php:dev --no-coverage tests/Unit tests/Integration
```

Two traps in that command:

- **`-v "$PWD/docs:/docs:ro"` is required.** `AlertDocumentationTest` reads the alert-engine table at `docs/alert-engine.md` relative to the project root, i.e. `/app/../docs/alert-engine.md`. Mounting only `api/` makes it fail with `docs/alert-engine.md not found at project root` — a false alarm that looks like a real regression.
- The test database is auto-suffixed `_test` (`doctrine.php dbname_suffix`), so this never touches the dev data.

`make test-e2e` needs the PWA built and served on `https://localhost`; a worktree's changes are not in the main stack's bundle. **CI is the real gate for Playwright** — say so plainly rather than implying local coverage you did not have. Since the pre-commit hook replays `make qa`, commit with `git -c core.hooksPath=/dev/null commit` once the legs are individually green.

## Test & CI gotchas that pass locally but fail in CI

These bit Sprint 51 — each was green on a dev machine and red in CI, or vice-versa. Check them before writing the test, not after the round-trip.

- **Timezone-fragile PHPUnit.** CI runs PHP in **`Europe/Paris`**; a throwaway local container defaults to **UTC**. Any test asserting a formatted offset or RFC 3339 / ATOM string (e.g. `closesAt?->format(DateTimeInterface::ATOM)`) must build its `DateTimeImmutable` with an **explicit `new \DateTimeZone('UTC')`**, or pass `-e TZ=Europe/Paris` + `php -d date.timezone=Europe/Paris` when reproducing locally. A hard-coded `+00:00` expectation is green in UTC and red in CI. `closesAt`/interval times inherit the timezone of the `$now` you pass in — pin `$now`.
- **API error-contract codes are not what you'd guess.** Object-level authorization denials are **masked as 404, never 403** (ADR-038) — a non-owner hitting an item operation gets 404, so a test expecting 403 is wrong. A request body carrying an **unknown backed-enum value fails denormalization and surfaces as 422** (a validation violation), **not 400** — API Platform 4.3 collects the type error as a constraint violation. Assert 404 / 422 accordingly, and document 422 (not 400) in the operation's `openapi` responses.
- **French Gherkin needs a language header.** A `.fr.feature` using `Fonctionnalité:` / `Contexte:` / `Scénario:` / `Étant donné` **must** start with `# language: fr`; without it `bddgen` parses in English mode and dies at the first `Scénario:`. Validate with `npx bddgen --config playwright.bdd.config.ts` before pushing — it parses every feature and exits non-zero on a syntax error, which no unit leg catches.
- **Simulating a geolocation failure in Playwright.** `context().clearPermissions()` does **not** make `navigator.geolocation.getCurrentPosition()` reject in headless Chromium — the previously `setGeolocation()`'d fix is still returned, so `geo.error` never fires. To exercise a denial/timeout path, stub it in the page: `page.evaluate(() => { navigator.geolocation.getCurrentPosition = (_ok, err) => err?.({ code: 1, ... } as GeolocationPositionError); })` (toggle via a `window` flag to restore success for a retry assertion).
- **Asserting *absence* via a shared `data-testid` in Playwright.** A `data-testid` reused across sibling items (e.g. `accommodation-source-badge`, present on every scanned candidate) makes `getByTestId(id).toHaveCount(0)` wrong the moment any sibling is on screen — the assertion sees the neighbours, not the thing you removed. Scope to the specific instance with `.filter({ hasText: "<label>" })` (or a parent locator) before `toHaveCount(0)`. Sprint 60 burnt a full ~15 min CI cycle on exactly this (a manual-accommodation 422 test that a DataTourisme candidate's badge failed).
- **A stacked PR whose base you retarget may not re-trigger CI.** After a squash-merge of a parent, retargeting the child's base to `main` **and** force-pushing sometimes fires no workflow run at all (the head shows "no checks reported"). **Close and reopen the PR** to re-trigger — no noise commit needed.
- **Editing an `#[ApiResource]` class docblock drifts `core/schema.d.ts`.** The class-level PHPDoc becomes the resource `description` in the exported OpenAPI, so changing it — even just prose — fails the `OpenAPI → TS drift` CI job until you regenerate (same for any DTO shape change, e.g. a field becoming required → `enabled: boolean | null`). Regen: `bin/console api:openapi:export > pwa/openapi.json` (php container) then `npm run typegen --workspace pwa` (writes `../core/schema.d.ts`), and commit the result.
- **Bind a uuid-column parameter as `Uuid`, never a raw string.** A DQL comparison against a `uuid` FK (`IDENTITY(x.user) = :userId`) needs `->setParameter('userId', Uuid::fromString($userId))` — a raw string skips the `uuid` DBAL conversion and **silently matches nothing**, not a loud error. A unit test that stubs the `QueryBuilder` can't catch it (the value never round-trips); cover it with a `KernelTestCase` + `#[ResetDatabase]` integration test against real Postgres.
- **The full mobile `jest` suite flakes locally on `notifications`/`ShareSheet`.** Running every suite in the dev docker env non-deterministically fails those two (act-warning/timing under parallel load); each passes in isolation and the **same two fail on a clean `origin/main`**. It is an environment artifact, not your diff — don't conclude the branch is broken. CI is the gate; confirm a suspected regression by running the affected suite alone.
- **`landing-page.spec.ts:193` (#649) is a known Playwright flake.** The "stale cookie (refresh fails) falls back to the landing" test intermittently hits `strict mode violation: getByTestId('landing-page') resolved to 2 elements` (SSR + client `<main>` both briefly mounted during the fallback). Red then green across identical runs, unrelated to any diff — re-run rather than chase it.

## Passing `--flags` through a `make` target

A bare option is claimed by `make` itself and dies before the recipe runs:

```bash
$ make provision corse --allow-unrouted-zone
make : l'option « --allow-unrouted-zone » n'a pas été reconnue
```

Use a `--` separator: it stops `make`'s own option parsing, and the `$(ARGS)` targets
strip the `--` (`filter-out --`, see the top of the Makefile) so the flag reaches the
container intact. This is the intended mechanism for every `ARGS_TARGETS` entry (e.g.
`make phpunit -- --filter=Foo`):

```bash
make provision corse -- --allow-unrouted-zone
```

Calling the container directly still works if you prefer:

```bash
docker compose --profile provisioning run --rm provisioner corse --allow-unrouted-zone
```

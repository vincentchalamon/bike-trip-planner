# ADR-071 — Dependency version policy and the ceilings we actually hit

**Status:** accepted
**Date:** 2026-09-21
**Builds on** [ADR-009](adr-009-quality-assurance-and-automated-testing-strategy.md) (the QA
gates that make an upgrade verifiable) and [ADR-037](adr-037-docker-dev-prod-convergence.md)
(the base image a version bump can force us to rebuild).

## Context

Until this ADR the repository had **no** written rule about dependency versions, and two
pins carried no explanation at all: `rector/rector: ~2.5.9` and an exact
`playwright-core: "1.63.0"` override. Nobody could tell whether they guarded against a known
break or were leftovers, so nobody dared move them — the classic way a stack silently ages.

At the same time the ops side had drifted badly: Traefik was four minors behind with five
security advisories open against it, cloudflared was over a year old, and four images ran
with no tag at all (`hadolint/hadolint`, `davidanson/markdownlint-cli2`, `alpine`,
`oskarstark/php-cs-fixer-ga`), so two runs of the same commit could use different tools.

## Decision

### A pin without an ADR is a pin to lift

Any constraint narrower than the ecosystem default (`~` where `^` would do, an exact version,
an `overrides` entry) must name the incompatibility it guards against, here or in a comment
next to it. If it cannot, it gets lifted at the next upgrade pass. Both undocumented pins
above were lifted; nothing broke.

### Everything is pinned by digest where a digest exists

Tag-only pins are invisible to Dependabot, which is how the provisioner image went unwatched
while `php`/`pwa` were tracked. Compose files get their own `docker-compose` Dependabot
entry, because the `docker` ecosystem only reads Dockerfiles — that gap is why Traefik and
cloudflared aged unnoticed.

### Ceilings are recorded, not rediscovered

Each entry below is a version we tried, measured, and backed out of. Re-attempt on the named
condition, not on a hunch.

| Held at | Blocker | Lift when |
|---|---|---|
| `phpunit/phpunit ^12.5` (`api/` only) | `api-platform/test` — required by `api-platform/symfony` 5's `ApiTestCase` since the 5.0 package split — requires `phpunit/phpunit ^11.5 \|\| ^12.2`, although its classes run unchanged on 13 | [api-platform/core#8537](https://github.com/api-platform/core/pull/8537) is released. `provisioner/` has no API Platform dependency and stays on PHPUnit 13. |
| `typescript ^5.9` (`pwa`) | `openapi-typescript` 7.13 declares `typescript: ^5.x`, on `latest` and on `next` | openapi-typescript widens its peer range |
| `typescript ~6.0` (`mobile`) | `typescript-eslint` refuses TS 7 outright (`Error: typescript-eslint does not support TS 7.0`). npm hoists one TypeScript for the workspace, so mobile's choice decides what `eslint-config-next` parses with in `pwa`. | [typescript-eslint#10940](https://github.com/typescript-eslint/typescript-eslint/issues/10940) |
| `eslint ^9` | `eslint-plugin-react`, pulled in by `eslint-config-next`, calls `context.getFilename()`, removed in ESLint 10 (`TypeError: contextOrFilename.getFilename is not a function`). The peer ranges permit ESLint 10 — they are wrong. | eslint-plugin-react ships an ESLint 10-compatible release |
| `react ~19.2.8` (whole workspace) | Expo SDK 57 pins React 19.2 and `jest-expo` ships `react-test-renderer@19.2.3`; React 19.3 breaks every mobile render test. Hoisting makes this workspace-wide, not per-package. | Expo SDK 58 (still `preview` at the time of writing) |
| Expo SDK 57 / React Native 0.86 | SDK 58 is preview-only | SDK 58 is released |
| Symfony `8.1.*` | Nothing — 8.1 is the current stable | 8.2 is released |

Note the shape of three of these: **the constraint that bites is not the one you declare.**
`~19.2.8` on React and `~6.0` on TypeScript exist because of how npm arranges the workspace,
not because of what any manifest asks for. Check the resolved tree (`npm ls <pkg>`), not the
manifests.

### Shared tooling is declared at the workspace root

When two workspaces ask for different versions of the same package, npm stops hoisting it and
nests a copy in each. Anything that *is* hoisted then cannot resolve it at all. That is not
hypothetical: with `typescript` declared only in `pwa` (5.9) and `mobile` (6.0), a clean
`npm ci` left the root without one, and the hoisted `openapi-typescript` died with
`Cannot find package 'typescript'`. The same happened to `eslint`, declared only in `pwa`,
which the hoisted `eslint-plugin-react` could not find.

Worse, it is unstable rather than simply broken: whether npm hoists one of the two or neither
depends on resolution order, so the same manifests can produce a working tree and a broken one.
An incremental `npm install` and a clean `npm ci` disagreed here, which is exactly how this
reached CI green locally and red in the pipeline.

So `eslint` and `typescript` are declared in the **root** `devDependencies` — the version the
hoisted tooling should see (TypeScript 5.9, which `openapi-typescript` and `typescript-eslint`
both accept). Workspaces that need a different one, like `mobile` on TypeScript 6, keep their
own nested copy and are unaffected. `react`/`react-dom` were already at the root for the same
reason.

Rule of thumb: if a package is consumed by something npm hoists, declare it at the root.
Reproduce with `rm -rf node_modules && npm ci`, never with an incremental install.

## Consequences

`api/` and `provisioner/` run different PHPUnit majors on purpose, and `api/rector.php`
carries a comment saying why. The asymmetry is the price of not regressing a project that has
no reason to wait for API Platform.

Lifting the two undocumented pins cost nothing, which is the point: an unexplained pin is
usually stale, not protective.

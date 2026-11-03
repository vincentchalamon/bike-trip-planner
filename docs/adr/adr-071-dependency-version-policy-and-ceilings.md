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
| `typescript ^5.9` (`pwa`) | `openapi-typescript` 7.13 declares `typescript: ^5.x`, on `latest` and on `next` | openapi-typescript widens its peer range |
| `typescript ~6.0` (`mobile`) | `typescript-eslint` refuses TS 7 outright (`Error: typescript-eslint does not support TS 7.0`). npm hoists one TypeScript for the workspace, so mobile's choice decides what `eslint-config-next` parses with in `pwa`. | [typescript-eslint#10940](https://github.com/typescript-eslint/typescript-eslint/issues/10940) |
| `eslint ^9` | `eslint-plugin-react`, pulled in by `eslint-config-next`, calls `context.getFilename()`, removed in ESLint 10 (`TypeError: contextOrFilename.getFilename is not a function`). The peer ranges permit ESLint 10 — they are wrong. | eslint-plugin-react ships an ESLint 10-compatible release |
| `react ~19.3.1` (whole workspace) | Expo SDK 58 pins React 19.3 and the mobile render tests run on `react-test-renderer`, which must match React exactly (jest-expo 58 no longer ships it, so `mobile` declares it). Hoisting makes this workspace-wide, not per-package. | Expo SDK 59 |
| Expo SDK 58 / React Native 0.88 | SDK 59 is not released | SDK 59 is released |
| `symfony/mcp-bundle ^0.14` | 0.15 requires `mcp/sdk ^0.9`, `api-platform/mcp` 5.0.5 requires `mcp/sdk ^0.8` | `api-platform/mcp` allows `mcp/sdk ^0.9` |
| Symfony `8.1.*` | Nothing — 8.1 is the current stable | 8.2 is released |

Note the shape of three of these: **the constraint that bites is not the one you declare.**
`~19.3.1` on React and `~6.0` on TypeScript exist because of how npm arranges the workspace,
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

Lifting the two undocumented pins cost nothing, which is the point: an unexplained pin is
usually stale, not protective.

## Addendum (2026-11-03): upgrade pass

- PHPUnit 13 in `api/`: `api-platform/test` 5.0.5 accepts `^13.0`, so both PHP projects are
  on the same major again.
- The React and Expo rows moved to SDK 58 (React 19.3, React Native 0.88).
- New ceiling on `symfony/mcp-bundle`, held by `api-platform/mcp`'s `mcp/sdk` range.
- `provisioner/` still pinned `rector/rector: ~2.5.9`, which this ADR had lifted for `api/`
  only; lifted there too.
- SDK 58 replaces the global `fetch` with `expo/fetch` by default, which rejects React Native's
  `{ uri, name, type }` FormData parts (the GPX upload failed on a device). The app keeps
  React Native's fetch through `EXPO_PUBLIC_USE_RN_FETCH`, set in `mobile/metro.config.js` and
  `mobile/jest.config.js`. Moving to `expo/fetch` is its own piece of work (FormData parts,
  401 retry, encoding), to be validated on a device.
- Sentry 11 replaces `sendDefaultPii` with `dataCollection`, whose defaults all collect; the
  strict posture of ADR-031 is restated field by field (`pwa/src/lib/sentry-scrub.ts`).

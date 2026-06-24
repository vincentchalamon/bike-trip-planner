# ADR-046: Temporary Masking of the AI Feature Behind a Build Flag

- **Status:** Accepted
- **Date:** 2026-06-24
- **Depends on:** ADR-042 (Optional Multi-Provider AI, BYO Token), ADR-045 (Conversational AI Trip-Brief Chat)
- **Supersedes (in part):** ADR-042 — only its "AI features are always present in the build / no environment flag" stance. The per-user BYO-token activation model is unchanged.

## Context and Problem Statement

The AI surface (the generation-assistant chat, the in-ride chat bubble, the per-stage briefings + trip-level overview, and the account provider/token configuration) is being **put on hold** for the recette (#649). Two facts drive this:

1. **Provider-side reliability.** During the recette the only configured provider (Gemini) returned transient `503 "high demand"` and, on a free-tier key, `429` quota errors with `limit: 0`. #760 made the calls resilient (retry 5xx, bounded timeout, actionable error mapping), but the end-user experience still depends on a provider/quota the project does not control.
2. **Feature maturity.** The conversational generation flow (ADR-045) and the in-ride error mapping (#761) still need work before they are good enough to ship to users.

We want the feature **hidden from users now**, **without deleting the code** — it will be re-enabled once a reliable provider/quota and the remaining polish are in place.

ADR-042 deliberately removed every env kill-switch (`OLLAMA_ENABLED`, the instance-wide `AI_ENABLED`) on the grounds that "AI is always present, per-user activated." That holds for *capability* gating, but it leaves **no way to put the whole feature on hold for a release** — which is what the recette now needs.

## Decision Drivers

- **Hide now, keep the code** — a single reversible switch, not a removal (preserve ADR-045 + the #760 resilience work and their tests).
- **Fail-safe masking** — default-off, so a fresh prod/recette deploy never exposes the on-hold feature by omission.
- **Keep the AI code exercised** — dev, CI E2E and Vitest keep the flag on so the components and their tests do not rot.
- **No backend change** — the per-user gate already prevents any AI work without a configured token.

## Considered Options

### Option A — Front-end build flag, default-off (chosen)

A single `NEXT_PUBLIC_ENABLE_AI` build flag read by `isAiFeatureEnabled()` (`pwa/src/lib/constants.ts`); every AI mount point is gated on it. Idiomatic for Next.js (`NEXT_PUBLIC_*` are build-time inlined, like `NEXT_PUBLIC_SENTRY_DSN` and the Plausible vars).

### Option B — Backend kill-switch (revive `AI_ENABLED`)

Rejected. Heavier, and ADR-042 removed it on purpose. The goal is to hide the **UI**; the backend is already inert without a configured per-user token, so a backend switch adds surface area for no user-visible gain.

### Option C — Delete the AI code

Rejected. The feature will be re-enabled; deleting it would lose the conversational chat (ADR-045), the resilience work (#760), and the test coverage, and force a re-implementation later.

### Option D — Runtime flag

Rejected. `NEXT_PUBLIC_*` are inlined into the standalone bundle at build time; a build flag is the idiomatic and simplest mechanism, consistent with the existing public client config.

## Decision

Introduce a single front-end flag **`NEXT_PUBLIC_ENABLE_AI`** (default **`false`**).

- `isAiFeatureEnabled()` (`pwa/src/lib/constants.ts`) returns `true` only when the value is exactly `"true"`. Every AI mount point is gated on it:
  - the **generation-assistant card** (`card-selection.tsx`);
  - the **in-ride chat bubble**, the **trip-level AI overview**, and the **AI-unavailable notices** (`trip-planner.tsx`);
  - the **per-stage briefing** (`stage-card.tsx` falls back to the rule-based alerts when off);
  - the **account provider/token section** (`account/settings/page.tsx`).
  - The AI availability/settings fetches (`use-ai-settings.ts`, the availability effect) are skipped when off, so no AI request is made.
- **Default-off / fail-safe:** prod and the iso-prod recette build mask AI with **no env set**. The flag is set to `"true"` only where the AI code must stay exercised:
  - dev — `compose.dev.yaml` (runtime env, `next dev`);
  - CI E2E — `ci.yml` bake args on the `Playwright` and `Playwright BDD Recette` jobs;
  - Vitest — `vitest.config.ts` (`test.env`).
- **Backend untouched.** AI computation is already gated on a configured per-user provider/token: `AllEnrichmentsCompletedHandler` short-circuits to `TRIP_READY` when `llmResolver->resolveForTrip()` returns no client, with a defense-in-depth re-check in the analyze handlers. With the configuration UI hidden, no new token can be set, so no AI work is dispatched. The endpoints remain so the kept code is still callable from tests and after re-enable.

## Consequences

- In prod/recette, **no AI is visible anywhere**. The source-URL and GPX-upload paths are unchanged; `card-selection` shows two cards instead of three.
- **To re-enable** (no code change required): set `NEXT_PUBLIC_ENABLE_AI=true` as a build arg/env for the target build — e.g. the Coolify prod build, or locally `NEXT_PUBLIC_ENABLE_AI=true make build`.
- The in-ride error-mapping alignment (#761) stays deferred until re-enable.
- This supersedes only ADR-042's "always present / no env flag" wording; the per-user BYO-token activation model is intact.

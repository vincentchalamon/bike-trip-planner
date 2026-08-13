# ADR-055: Mobile State Architecture — Thin Store Composing `@btp/core`

- **Status:** Accepted
- **Date:** 2026-08-13
- **Depends on:** ADR-053 (Mobile Strategy — Dedicated Native App), ADR-007 (Frontend Local State Management), ADR-056 (Mercure Header-Auth for Non-Browser Clients)

## Context and Problem Statement

The web frontend manages trip state locally in a Zustand + Immer store (ADR-007):
the store hydrates from a `/trips/{id}/detail` payload, then reconciles a stream
of Mercure SSE events into stage slices. That reconciliation is subtle — it
encodes several hard-won race and edge behaviours: concurrency-token handling for
stale recomputing indices (#840), client-only field preservation on a stable
endpoint (#649), and label preservation on a raw resync (#787).

The native app (ADR-053) needs the same live-trip behaviour. Re-implementing the
reconciliation in a second language/runtime would guarantee drift: a fix landed
on one platform silently missing from the other, with the two stores diverging
exactly where the logic is most fragile. The type contract already prevents
schema drift (backend DTO → OpenAPI → TS); we want the same guarantee for the
*reconciliation* logic.

## Decision

Make the mobile store a **thin wrapper** that composes the pure reconciliation
reducers extracted into `@btp/core`, rather than re-deriving the logic.

- **Reconciliation lives in `@btp/core`, once.** `core/reconciliation.ts` holds
  the pure reducers (`reconcileTripReady`, `reconcileStageUpdate`,
  `enrichedPayloadToStageData`) extracted verbatim from the web store, with no
  Zustand/Immer/dayjs dependency. Both platforms import them, so the mapping and
  race handling can never diverge (#1013/#1014). The Mercure wire types
  (`MercureEvent` et al.) live alongside in `core/mercure.ts`, shared by both.
- **The mobile store is a thin Zustand store.** `mobile/src/store/trip-store.ts`
  holds `StageData[]` plus load/lock status and delegates every reconciliation to
  the core reducers (`applyTripReady` → `reconcileTripReady`, `applyStageUpdate`
  → `reconcileStageUpdate`). It carries no reconciliation logic of its own. Immer
  is not needed: the pure reducers return new state.
- **Hydrate, then go live.** A single orchestration
  (`mobile/src/hooks/use-trip-live.ts`) loads the trip from `/detail`, hydrates
  the store, fetches the per-trip Mercure subscriber token (ADR-056), and opens
  the SSE subscription. Incoming `trip_ready` / `stage_updated` events are mapped
  through `enrichedPayloadToStageData` and pushed into the same reducers.
- **Optimistic mutations with SSE as the authority.** A user edit (e.g. stage
  deletion) is applied optimistically to the store (drop + renumber days,
  mirroring the web), the API call runs, and the authoritative state arrives via
  SSE reconciliation. On API failure the caller restores the pre-mutation
  snapshot via `setStages`. This is the same optimistic + SSE-reconciled pattern
  the web uses, now expressed against the shared reducers.

## Consequences

- One reconciliation implementation, two thin stores. A race fix or field-
  preservation rule is written once in `@btp/core` and both platforms inherit it;
  the characterization tests in `core/reconciliation.test.ts` guard the semantics
  for both.
- The stores differ only in glue (Zustand config, RN vs. web hooks), which is
  where they *should* differ. Neither store re-derives domain behaviour.
- `@btp/core` is now the shared source not just for types (schemas, Zod) but for
  runtime domain logic (reconciliation, accommodation constants). It stays free
  of any framework/runtime dependency so both a Next.js server bundle and a React
  Native bundle can consume it.
- The mobile store deliberately does **not** persist (no offline cache yet):
  in-memory, hydrated per trip open, matching the web's local-first posture
  (ADR-007). Offline persistence is a later, separate decision.

## References

- [ADR-053](adr-053-mobile-strategy-native-app.md) — Mobile strategy (native app)
- [ADR-007](adr-007-frontend-local-state-management-and-reactivity.md) — Frontend local state management
- [ADR-056](adr-056-mercure-header-auth-non-browser.md) — Mercure header-auth for non-browser clients
- `core/reconciliation.ts`, `core/mercure.ts` — shared reducers and wire types
- `mobile/src/store/trip-store.ts`, `mobile/src/hooks/use-trip-live.ts` — thin store and orchestration

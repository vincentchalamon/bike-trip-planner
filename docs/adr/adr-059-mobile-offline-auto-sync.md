# ADR-059: Mobile Offline Auto-Sync

- **Status:** Accepted
- **Date:** 2026-08-21
- **Depends on:** ADR-053 (Mobile Strategy — Dedicated Native App), ADR-055 (Mobile State Architecture), #1040 (Mobile map), Sprint 55 (Trip consultation)

## Context and Problem Statement

A bikepacker consults the roadbook where there is no signal: a col, a forest, a
foreign SIM with no data. The native app (ADR-053) must therefore show an
upcoming or in-progress trip — stages, elevation profile, weather, accommodation,
map trace — with the device fully offline, and reconcile silently when
connectivity returns. Today the consultation stack (Sprint 55) is online-only:
`mobile/src/store/offline-store.ts` exposes `isOnline` / `setOnline` and the
mutation gate (`mobile/src/store/gating.ts`) already refuses edits while offline,
but nothing feeds the flag — it is hardcoded `true`, so the gate never fires and
a screen load with no network simply fails.

We need to decide **what** is cached, **how fresh** the rider is told the cache
is, **when** it syncs, and **how much** offline surface the app exposes — before
building the persistence (#1147), the sync engine (#1148), and the offline map
degradation (#1149) on top. This ADR fixes those boundaries and lands the first
brick: real connectivity feeding the store.

## Decision

Offline is **automatic and invisible**: the app caches the trips that matter,
syncs them in the background, and tells the rider only how fresh the data is. It
is not a user-managed feature.

- **Cache perimeter = upcoming and in-progress trips only.** Past trips are
  **online-only**. A rider needs offline data for the trip they are about to ride
  or are riding, never for an archive; bounding the cache to non-past trips keeps
  device storage predictable and the sync set small. A trip transitioning to past
  drops out of the cache on the next sync.
- **Freshness is surfaced, not managed.** Each cached trip carries a last-sync
  timestamp, rendered as a relative "synchronised X ago" label
  (`mobile/src/lib/freshness.ts`: "à l'instant" / "il y a N h" / "hier" /
  "il y a N j"). The helper is **pure** — the reference `now` is injected, never
  read from `Date.now()` internally — so the buckets are deterministic and unit
  tested. This is the only offline status the rider sees.
- **Background sync, no manual control.** Syncing is a background concern; there
  is **no** "download for offline", "cached" toggle, or manual pin in the UI. The
  rider never decides what is offline — the perimeter rule does. This keeps the
  consultation UI (Sprint 55) unchanged for the online case.
- **Real connectivity into the existing store.** `offline-store` is wired to
  `@react-native-community/netinfo`: a boot-time listener
  (`mobile/src/store/use-connectivity.ts`, mounted in `app/_layout.tsx` with
  cleanup on unmount) maps NetInfo state to the flag —
  `isConnected === true && isInternetReachable !== false` (a `null`
  `isInternetReachable`, emitted while NetInfo is still probing, is treated as
  online so the mutation gate does not flap). The store keeps its stable API
  (`isOnline` / `setOnline`) and stays transport-agnostic: no cache logic lives in
  it, so #1147/#1148/#1149 consume the same store without reshaping it.
- **Offline map is degraded, not interactive.** With no network the map shows the
  **route trace and the elevation profile** from cached data; it does **not**
  serve interactive raster/vector tiles (no pan-to-load, no satellite layer). Tile
  caching is out of scope — the rider gets orientation, not a full basemap. Full
  degradation mechanics are #1149.

## State architecture fit (ADR-055)

The connectivity flag stays in its own single-purpose store, separate from the
trip store, so it survives trip switches and is readable from the mutation
runners with no trip loaded — consistent with ADR-055's split of transient
device state from trip state. The freshness helper is a pure `lib` function like
`dates.ts`: no store coupling, injectable clock, fully testable. Persistence of
the cached trips themselves is deliberately **not** decided here (it is #1147);
this ADR only guarantees the store API and the freshness contract those tickets
build on.

## Alternatives considered

- **Cache every trip, including past ones.** Rejected: unbounded device storage
  for data a rider will almost never open offline. Non-past trips are the only set
  with an offline use case; the perimeter rule is the storage bound.
- **A manual "make available offline" toggle per trip.** Rejected: it pushes a
  storage-management chore onto the rider and contradicts the "invisible offline"
  goal. The perimeter rule decides automatically; freshness is the only surfaced
  signal.
- **Absolute last-sync timestamp ("synchronised at 14:32").** Rejected in favour
  of a relative label: "il y a 3 h" answers "can I trust this?" without the rider
  doing date arithmetic, and reads correctly across timezones while travelling.
- **Offline interactive tiles (bundled or cached basemap).** Rejected for this
  epic: the storage and licensing cost of a tile cache dwarfs the value of a
  trace and profile for orientation. Revisit if riders ask for a real offline
  basemap.

## Consequences

- The mutation gate now fires for real: with the device offline, edits are refused
  (`offline` reason) instead of hitting the network and failing — the intended
  Sprint 55 behaviour finally has an input.
- A new mobile dependency (`@react-native-community/netinfo`, SDK-57 aligned) is
  added; CI gates mobile on tsc + jest only, and the listener is not exercised by
  the jest suite (no test imports it).
- The offline perimeter (non-past trips), the freshness contract (pure helper +
  `freshness.*` i18n keys), and the stable `offline-store` API are now fixed
  points that #1147 (persistence), #1148 (background sync), and #1149 (offline
  map) build on without renegotiation.
- Offline map is trace + profile only; a full offline basemap remains out of
  scope.

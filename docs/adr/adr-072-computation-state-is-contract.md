# ADR-072 — Computation state is part of the contract

**Status:** accepted
**Date:** 2026-09-21
**Opens** lot C, after [ADR-070](adr-070-freshness-is-dispatch-completeness.md) closed lot B.
Corrects [ADR-068](adr-068-enrichment-durability-by-group.md) on the unavailable forecast.

## Context

Lot B made the enrichments themselves durable, translated at read, and correctly
re-dispatched. It left the question a client actually asks unanswered: **where is this trip's
computation up to?**

Three defects, one subject.

**Almost nothing was exposed.** `TripDetailProvider` had the tracker injected, read the full
status map, and published exactly one field from it — `weatherStatus`. The five other
categories of `ComputationName::category()` (`route`, `points_of_interest`, `accommodations`,
`terrain_security`, `context`) had no equivalent, although `computationsInCategory()` and
`deriveBlockStatus()` were already written and already generic. They were simply never called
with anything but `'weather'`.

**Failure was invisible in the list.** `TripCollectionProvider::computeStatus()` answered
`analyzed` as soon as a trip had stages, including when *every* computation had failed. A trip
whose enrichment had collapsed was indistinguishable from one that had worked.

**And after thirty minutes the state did not exist at all.** It lived in a single Redis key
per trip under `TTL = 1800` — shorter than the life of a trip, so the common case for anything
older than half an hour. Past it both read paths fell back to "there are stages, so it must be
analysed", which is how the second defect became permanent rather than occasional.

The project had already decided this exact question once. `TripRequest::$version` carries a
docblock explaining that it *replaced a Redis counter*, because the TTL made it vanish and
start again from 1.

## Decision

**Computation state is contract data, so it lives in Postgres.** A `computation_status` JSONB
column on `trip` mirrors the tracked map; Redis stays the hot path.

### A decorator, not a change to the tracker

`App\ComputationTracker\PersistingComputationTracker` decorates `ComputationTrackerInterface`,
the same shape as `LockingTripRequestRepository`. On write it delegates, then mirrors; on read
it delegates, and falls back to the column when the cache returns `null`.

The point of that shape is that **neither provider changes to gain durability**. They ask the
interface; the fallback is underneath. Three Mercure payloads, two DTOs and the frontend read
`getStatuses()`'s shape, and none of them move.

### It mirrors the entry that moved, not the map

The first cut wrote the whole map on every transition, on the reasoning that a full write leaves
the column consistent whatever order the workers settle in. That is only true if each write
lands in the order its read was taken, and nothing makes it so. `ComputationTracker` serialises
its **own** Redis read-modify-write behind a per-trip lock; the decorator's round trip to
Postgres happens outside it. Two of the five workers settling at once can each read the map,
then land their writes in the opposite order — and the one that read first, landing last,
erases the other's entry.

Nothing would show it. While the cache is alive the mirror is never read; the loss only surfaces
once the TTL passes, as a trip reporting success on a computation that failed. That is the bug
this ADR exists to close, reintroduced probabilistically.

So a settling computation writes **its own key**, merged server-side with Postgres's `||`:

```sql
UPDATE trip SET computation_status = computation_status || CAST(:entry AS jsonb) WHERE id = :id
```

One statement, atomic per row, order-independent. The status is read back from the tracker
rather than restated in the decorator, so the two cannot drift on the vocabulary.

`initializeComputations()` is the one wholesale write, because it is the one moment the whole
map changes at once: a generation starting over. Without it the column would keep answering with
the previous generation's verdicts until each was individually overwritten. `resetComputation()`
is mirrored too — it is not a settling, but it undoes one, and a column left claiming `done` for
work about to be redone would outlive the cache saying otherwise.

### Mirrored on settling, not on the gate closing

The obvious anchor — write the snapshot when the trip's computations are all accounted for —
is the wrong one. `TripCompletionGate::evaluate()` returns early while
`completed + failed === total` is false, and that condition **becomes unreachable as soon as a
message goes stale**: a generation bump leaves the in-flight computations at `pending` forever.
This is documented, not hypothetical — `TripBatchRecomputeProcessor` works around it by
re-dispatching the whole pipeline. A snapshot anchored there would never be written for a trip
edited mid-analysis, which is precisely the trip whose state a client most needs.

So the mirror happens on each terminal transition, `markDone` and `markFailed`. Not on
`markRunning`: while a computation runs the cache is alive by construction, the durable copy
only has to answer once it is gone, and the entry it would write is the one initialization
already put there. About eighteen small `UPDATE`s per generation.

### It stores through a narrow interface of its own

`ComputationStatusStore`, not `TripRequestRepositoryInterface`. That one is aliased to the
transient implementation in the `test` environment, so depending on it would mean nothing ever
reached Postgres and the durability this exists for would go untested.

### What the two read paths now say

`TripDetail` gains `categoryStatus`, the per-category map for all six, alongside the existing
`weatherStatus` — which stays, because the frontend reads it. A category with nothing tracked
is left out rather than reported as an outcome.

`TripListItem.status` gains `failed`, returned when the map is terminal, at least one
computation failed and none succeeded. A partial failure still leaves a usable trip and keeps
reading `analyzed`.

### An unavailable forecast is a state of the stage, not of the computation

ADR-068 announced "a third state, next to *failed* and *not yet computed*". That framing was
wrong and is corrected here: **the WEATHER computation succeeds** when it correctly determines
that a stage twenty days out has no forecast. `unavailable` is not a computation status.

Five causes used to collapse into a single null forecast. A stage-level field now says which:

| value | cause | actionable? |
|---|---|---|
| `past` | the stage is behind us | no, short of changing the dates |
| `beyond_horizon` | further out than 16 days | not yet: come back later |
| `unavailable` | empty provider, failed batch, uncovered window | yes, a recompute may help |

Absent when the forecast is there, and absent when nothing has been computed yet — that second
distinction is what `weatherStatus` is for.

It is **derived at read, not stored**. "Too far ahead" is a statement about today: a stored
answer would rot, exactly as the stored verdicts lot B declined to introduce would have. The
horizon constant moves out of `FetchWeatherHandler` onto `WeatherAvailability`, so the fetcher
and the reader cannot disagree about where it falls.

## Consequences

Roughly eighteen single-row `UPDATE`s per generation, against a read path that no longer
guesses. The column is the fallback, never the source of truth while the cache has an answer,
so there is no reconciliation to get wrong.

`TripListItem.status` is a literal union in the generated types, so `failed` breaks `tsc` on
the PWA and on mobile until both handle it. That is the drift detection the type contract
exists for, not an accident.

No backfill: the column defaults to `{}`, which reads as "nothing tracked" — the same answer an
expired key gave, and the same fallback behaviour as before for trips that predate it.

## What this leaves open

- **The reason a computation failed.** Every external source raises `\RuntimeException` with a
  prose message (`src/Osm/`, `src/Weather/`, `src/Tourism/`, `src/RouteFetcher/`); there is no
  taxonomy to derive a stable category from. Building one means an exception hierarchy across
  four adapters — a change of its own. Doing it by string matching would be worse than the
  absence, so `markFailed()` is unchanged and the status says `failed` and no more.
- **`GET /trips/{id}/computations`.** Nothing polls today: progress arrives over Mercure as
  `COMPUTATION_STEP_COMPLETED`. The resource belongs with the client that motivates it, in
  phase 3.
- **`computedAt`** in `alerts_by_group`, which ADR-070 flagged as written and never read, is
  still written and still never read. Lot C found no use for it; it should go.
- **A trip with no start date reports `running` forever** on `weather` and `context`.
  `initializeComputations()` marks the whole pipeline `pending`, and ADR-070's guard then
  withholds `WEATHER`, `CALENDAR` and `EVENTS` from dispatch — so those entries never move.
  The state is accurate (nothing was computed, and nothing will be until dates are set) and
  `weatherAvailability` is correctly absent, but making the state readable is what made this
  visible: three of the six categories now advertise work that is not going to happen. The fix
  belongs with the guard, not with the mirror — either initialization skips what the dispatcher
  will withhold, or the vocabulary gains a term for "withheld".

## Alternatives considered

**Moving the tracker off Redis entirely.** It would remove the dual write, and cost every
progress update a database round trip on the hot path — the tracker is written about eighteen
times per generation and read on every Mercure-driven refresh. The cache is the right store
for the live state; it is only the wrong store for the *lasting* one.

**A terminal snapshot written by `TripCompletionGate`.** Rejected above: the gate does not
close for a trip edited mid-analysis.

**Storing `weatherAvailability` alongside the forecast.** It was the first implementation and
was reverted mid-flight. `beyond_horizon` is true relative to today, so the stored value is
wrong the moment the trip is read on another day — the same failure mode lot B had just spent
an ADR avoiding.

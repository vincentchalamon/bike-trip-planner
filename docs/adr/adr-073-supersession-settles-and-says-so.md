# ADR-073 — Supersession settles, and says so

**Status:** accepted
**Date:** 2026-09-22
**Continues** lot C, after [ADR-072](adr-072-computation-state-is-contract.md) made computation
state readable and durable — and designed around the defect this one fixes.

## Context

ADR-072 had to write, about its own completion gate:

> the obvious anchor is the wrong one […] that condition **becomes unreachable as soon as a
> message goes stale**: a generation bump leaves the in-flight computations at `pending`
> forever.

That is the defect. When an edit bumps a trip's generation, every message in flight is built
against a trip that no longer exists. The worker consumed it and returned in silence:

```php
if ($this->isStale($tripId, $messageGeneration)) {
    $this->logger->info('Discarding stale message.', [...]);
    return;                       // before markRunning, before anything
}
```

No marking, no event, no gate re-evaluation. The computation stayed `pending`, and since
`getProgress()` counts neither `pending` nor `running` towards `completed`/`failed` but counts
both towards `total`, `completed + failed === total` could never hold again. No
`trip_complete`, no `AllEnrichmentsCompleted`, therefore no `trip_ready` — a loader spinning on
both clients for the rest of that trip's life.

It had no owner. The comparison was **decided in three places**: `isStale()` behind
`executeWithTracking()` for nineteen handlers, a direct call in `RecalculateStagesHandler`, and
a copy rewritten by hand in `ResolveStageLabelsHandler` that had already stopped being the same
expression. It was **worked around in two more**, differently: `TripBatchRecomputeProcessor`
re-runs the whole pipeline when the analysis has not settled, while `TripUpdateProcessor`
re-arms only its resolver's subset and abandons the rest with no guard at all. And it was
**reported nowhere**: twenty-five Mercure event types, none of which says a computation was
given up on.

Two further holes made the rest moot on their own:

- **`trip_ready` was silent from the second generation onwards.** `claimReadyPublication()` set
  `trip.{id}.ready_claimed` and nothing ever cleared it — not `initializeComputations()`, not
  `resetComputation()`. The first generation to publish claimed the slot for every generation
  after it.
- **A GPX import had no guard at all.** `GpxUploadService` dispatched its whole fan-out without
  a generation, and a `null` generation reads as "never stale". Half the product's trips were
  outside the mechanism entirely.

### What the exposure actually is

Worth stating narrowly. The seven structural edits bump inside `mutateStages()` and all funnel
through `RecalculateStages`, whose handler re-dispatches the trigger union (ADR-070) — so they
re-arm most of what they invalidate. `TripBatchRecomputeProcessor` guards itself. The real
stranding is `PATCH /trips/{id}`, the tail of an initial analysis, and `WIND`/`FORDS`, which are
only ever dispatched at the end of `FetchWeatherHandler`.

## Decision

### The comparison lives once, in a middleware

`StaleMessageMiddleware` sits on the default bus and drops a superseded message by returning the
envelope without calling `$stack->next()`. Messenger sees a handled message and acks it — not an
exception, which would send something that is not a failure to the retry strategy and then to
`failed`.

Being ahead of `HandleMessageMiddleware` means the handler and its seven dependencies are never
built. That is a real saving and a modest one: the transport has already deserialized the
message before any middleware runs. The reason to be here is that there is one place to read.

It reads the generation through `BelongsToATripGeneration`, an interface declaring the two
properties the messages already had. Twenty-one of the twenty-two implement it; only
`SendPushNotification`, which has no trip, does not. This raises the bar over the project's
existing habit of reaching those fields through a `@var object{tripId: string}` cast, and
deliberately: `MESSAGE_TO_COMPUTATION` covers seventeen messages, and the ones that matter here
include `RecalculateStages`, `RecalculateRouteSegment` and `ResolveStageLabels`, which are not in
it. `MessengerRoutingTest` now refuses a message that carries a `tripId` without implementing
the interface — the guard that would have caught the GPX fan-out.

### The middleware writes nothing

This is the part that is easy to get wrong, and the first draft of this change got it wrong.

The status map is keyed by computation, not by (computation, generation). A worker arriving late
from generation N is therefore writing into a map that describes N+1. Concretely: generation 1
dispatches `ScanPois` and its worker is slow; a `PATCH` bumps to 2, resets `POIS` to `pending`
and re-dispatches; the generation-2 worker finishes and records `done`; *then* the generation-1
worker wakes and marks `superseded` — over a success. The worse ordering has it write before the
newer worker starts, closing the gate on a terminal status while that generation's work is still
queued. That is precisely the failure `executeWithTracking()`'s docblock already warns about for
`failed`.

**So the settling belongs to whoever moved the generation and knows what it re-dispatched.** It
runs before the new work can finish and it needs no compare-and-set to be correct — though
`markSupersededUnlessSettled()` is one anyway, because defence that costs one `if` is worth
having.

Two sites own that decision today, and both already owned it:

- `RecalculateStagesHandler`, after dispatching the trigger union — which covers all seven
  structural edits, since every one of them funnels here;
- `TripUpdateProcessor`, after re-arming its resolver's subset.

`ComputationSupersession` carries the three gestures for both: mark every non-terminal
computation outside the dispatched set, cascade `WEATHER → WIND/FORDS` for the reason
`ComputationFailureSubscriber` already documents, and re-evaluate the gate.

### `/recompute` keeps the opposite strategy

`TripBatchRecomputeProcessor` is untouched. It re-runs the whole pipeline when the analysis has
not settled, so it loses nothing; converting it to "report abandoned" would make the product
worse at the one place that already had it right. Two strategies for one situation, and the
distinction is real: `POST /recompute` is an explicit request to redo the trip, a `PATCH` on
`averageSpeed` is not.

### A fifth status

`superseded`, terminal, counted by a new `settled` alongside `completed` and `failed` — which
stay as they were, because the progress payload the frontend renders distinguishes success from
failure and a superseded computation is neither.

Not `failed`: nothing broke, nothing will be retried, and `TripListItem.status` would have turned
a merely re-edited trip into a failed one — the distinction ADR-072 had just made meaningful.
Not a reset to `pending` with a re-dispatch from the middleware either: that would bypass the
dispatcher's own guards (a trip that has just lost its dates must not be re-dispatched a weather
fetch) and loop for as long as edits keep arriving.

`TripCollectionProvider::computeStatus()` needed no new branch and gets none: a trip whose
computations were abandoned still has the stages the previous generation produced, so it reads
`analyzed`.

### Announced once per bump

`computations_superseded` carries the whole list, published by `ComputationSupersession` at the
moment of settling. Once rather than per computation, and from there rather than from the worker,
for the same reason as above — an announcement from a late worker would report as abandoned work
that has since succeeded.

The payload carries no generation: the envelope already has `version`, and this is the one case
where re-reading it at publish time (`TripUpdatePublisher:34`) gives exactly the right answer —
the version that superseded them.

### The terminal publication is claimed per generation

`AllEnrichmentsCompleted` gains the generation it was the only pipeline message to lack, and the
claim key becomes `trip.{id}.ready_claimed.{generation}`. A terminal publication belongs to a
generation, not to a trip. It also makes the terminal message discardable by the middleware,
which it was not.

## Consequences

An edited trip can announce itself ready again, which it could not since the claim was
introduced. A category whose work was abandoned says so on `/detail` instead of claiming to still
be running.

`categoryStatus` and `weatherStatus` widen by one enum value. That is a widening, so no client
breaks at type-generation — the same blind spot ADR-072 found, handled by hand again.

Both clients stop the spinner and record the outcome; the PWA maps `superseded` to *no* block
status rather than to a failure, because the block has no answer and nothing failed.

## What this leaves open

- **The status vocabulary is five bare strings in fourteen files.** There is no
  `ComputationStatus` enum; adding a fifth value meant five places having to agree by hand. The
  enum is the obvious next cleanup and was kept out of this change to keep it on one subject.
- **The comparison is `<`, strictly**, so a generation *above* the current one passes. Three
  processors (`AnalyzeTripProcessor`, `AccommodationScanProcessor`, `StagePoiWaypointProcessor`)
  read `current()` outside the write lock, which is what makes that reachable at all. Tightening
  it means fixing them first.
- **The other silent returns.** `AnalyzeTerrainHandler`, `FetchAndParseRouteHandler`,
  `GenerateStagesHandler` and most of the `Check*Handler`s return before `executeWithTracking()`
  when a trip has no stages, leaving the computation `pending` — same symptom, different cause.
  `ScanEventsHandler` is the only one that settles itself in the degenerate case, and shows the
  shape of the fix.
- **A trip with no start date still reports `running` forever** on `weather` and `context`
  (ADR-072's open item). The settling fixes it on the bump path only: a trip that is never edited
  keeps those three computations pending, because the dispatcher withheld them and nothing says
  so.
- **`computedAt`**, written and never read, flagged by ADR-070 and again by ADR-072. Still there.

## Alternatives considered

**Marking from the consumer.** The first design, and wrong for the reason set out above. It is
worth recording because it looks obviously right: the middleware knows the message is superseded,
so it seems like the natural place to say so. It is the place with the least information.

**Keying the status map by generation.** `trip.{id}.computation_status.{generation}` would make a
late worker's write harmless by construction, and would have subsumed the claim-key fix as well.
Rejected as too large for this change: it reaches the durable column ADR-072 had just designed,
the Mercure payloads and every reader. It remains the more correct model if the map ever needs
one.

**Re-dispatching the stranded set from the bumper**, generalising what `/recompute` does.
Correct, and it loses no work — but it would duplicate ADR-070's dispatch decision at a second
site, and re-dispatch computations the trigger table had deliberately not invalidated.

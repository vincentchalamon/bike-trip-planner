# ADR-065 — Mercure is an invalidation channel, never a source of truth

**Status:** accepted
**Date:** 2026-09-22
**Amends** [ADR-057](adr-057-progressive-trip-loading.md) and the publish/persist split that
[ADR-068](adr-068-enrichment-durability-by-group.md) began; the number was reserved for this
decision long before it was written, which is why it sits behind 066-074.

## Context

The system treats Mercure as a transport in its comments and as a source of truth in its code.
Ten handlers out of eleven published without persisting; `trip_ready` was rebuilt from
`getStages()`, so even the terminal event did not re-contain the data it announced. The
implicit assumption — *a client is connected at instant T and never disconnects* — is already
false for the public share page (an anonymous visitor, never any SSE), for mobile offline
(ADR-059), for a reload, for a backgrounded tab, and for any network blip.

Lots A to C closed most of that hole by giving the data a durable home. What none of them
addressed is the other direction: **what happens to the work when the hub is unreachable.**

The answer was: it is destroyed. `TripUpdatePublisher::publish()` called
`$this->hub->publish($update)` with no try/catch, and every public method of the class routed
through it. The handlers publish *after* `markDone()` and *outside*
`executeWithTracking()`'s try/catch, so an unreachable hub meant the computation ran, settled,
then threw — the message was retried three times, the whole computation was redone four times
over, and it landed in the `failed` transport that nothing consumes.

Two consequences travelled with it:

- `TripCompletionGate::evaluate()` publishes `trip_complete` **before** dispatching
  `AllEnrichmentsCompleted`, so the terminal message never left: no push notification, no
  final state, for a trip whose computations had all succeeded.
- `publishComputationError()` is called from inside the `catch`, before `throw $throwable`. If
  the hub was down at the moment a handler failed, the rethrown exception was Mercure's — so
  Sentry and `ComputationFailureSubscriber` recorded the wrong cause for every failure during
  the outage.

A hub outage was therefore not a degradation of comfort. It was an outage of the product.

## Decision

**Mercure is a channel of invalidation. Every byte it carries must be retrievable by an
authenticated GET, and publishing must never be able to fail the work that produced the
event.**

Three things follow.

### 1. Publishing cannot throw

A single `try/catch (\Throwable)` around the one `$hub->publish()` call, logged at `error`.
One place, covering the thirty-odd call sites; none of them ever depended on the exception.
`error` and not `warning`, because it is a real infrastructure fault and because production
buffers everything below `error` away (see
[ADR-075](adr-075-readiness-covers-the-consumers.md)).

### 2. Mercure leaves the readiness required list

`HealthController`'s `$required` no longer contains `mercure`. The precedent is `reference_data`
and `postgres_reference` (ADR-040/060): reported, never blocking. A client that misses an event
resynchronises on its next read, so the hub being down costs latency, not correctness, and must
not take an otherwise healthy API to 503.

**The cost, stated rather than discovered later:** nothing alerts on a Mercure outage in the
beta profile, where only UptimeRobot on `/api/healthz` is running and Uptime Kuma is not
deployed. The outage is visible in `deps.mercure` for anyone who looks. Whoever wires deep
monitoring should start there.

### 3. The invariant is enforced, not encouraged

`core/mercure.ts` carries the guard, using the idiom already in that file
(`ALERT_GROUPS_MATCH_THE_SCHEMA`), and the CI job that type-checks `core` is what fails.

**Layer one — classification.** `MERCURE_EVENT_KIND` labels each of the 26 events `data` or
`signal`. No type can decide whether a payload is content or a notification, so this half is
declarative and reviewed in a pull request. What the compiler does enforce is exhaustiveness:
a new event cannot enter the union unclassified. `core/reconciliation.ts` is the cross-check —
everything marked `signal` there leaves trip data untouched, and `computation_step_completed`
returns the same state object outright.

**Layer two — coverage.** Every named payload type and every alert element must have its field
names covered by the resource a GET returns.

The check deliberately sits one level *below* the event. An event's `data` is an addressing
envelope — `alertsByStage`, `affectedStageIds`, a bare `stageId` — which exists to say *where*
an update lands and which no GET returns by design. Comparing at that level would have failed
on nearly every event and degenerated into the hand-maintained exception list this guard exists
to avoid. The alert events are reached generically rather than through a list, so adding one
brings it under the check with nothing to remember.

**Layer three — the emitting side.** `MercureEventContractTest` diffs `MercureEventType` against
the TypeScript union both ways and checks every case is classified. Without it the TypeScript
guards would be trivially bypassable: a case added in PHP and published breaks nothing on the
client, and its payload faces no coverage check at all. Same shape as `AlertDocumentationTest`.

## What the guard proves, and what it does not

**Proves:** no field is published over SSE that no GET can return.

**Does not prove:** that the values agree, that the equivalent read is convenient, or that the
envelopes are covered. `route_segment_recalculated` sends a geometry delta whose equivalent is
the whole of `/trips/{id}/route` — structurally covered, but a client re-reads far more than it
was sent. That gap is named in the contract rather than hidden.

## Consequences

Its first run found exactly one violation across the whole Mercure surface: `calendar_alerts`
published a `date` for which `App\ApiResource\Model\Alert` has no property, so the value was
persisted, pushed, and dropped on read — the one field no GET could return. It was
`startDate + dayNumber - 1`: a rendering of the trip's calendar rather than a fact about the
alert, with both operands already on every client. Removed, per the second guiding principle of
the restructuring programme (persist facts, derive verdicts at read).

**The reciprocal is the real guard rail, and it is the sentence to come back to: if a piece of
data ever exists only on Mercure, this ADR has been violated.**

Left open: `trip_ready` still carries a full payload. Once every client trusts the reads, it can
become a bare "re-read", and `core/reconciliation.ts` can refetch on any hole instead of
maintaining its per-group merge machinery. That is a simplification, not a correction, and it is
not done here.

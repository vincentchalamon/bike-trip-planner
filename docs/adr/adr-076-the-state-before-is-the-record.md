# ADR-076 — The state before the request is the record, not a cached fingerprint

**Status:** accepted
**Date:** 2026-09-23
**Amends** [ADR-067](adr-067-optimistic-concurrency-on-trip-edits.md) on the meaning of 409.

## Context

`PATCH /trips/{id}` dispatched nothing. Not intermittently — for every request, in production,
since the endpoint existed.

`TripRequest` is a Doctrine entity as well as the input DTO. `findTripRequest()` is a bare
`find()`, so it serves the identity map; `TripRequestProvider` hands that managed instance to
API Platform, which deserialises the PATCH body straight into it (`ReadProvider` passes the
operation provider's result to `DeserializeProvider` as `OBJECT_TO_POPULATE`). The processor
then read the same identifier again and got the same, already-mutated object.
`resolve($oldRequest, $data)` therefore compared an object with itself and always answered "no
change": no generation bump, no ETag, no message, no supersession. The trip saved and went
quiet.

No test could see it. The suite ran against a transient repository that deserialises a fresh
copy per read, so the one path production takes was the one path nothing exercised.

A second defect sat on top. `IdempotencyChecker` hashed eight of the twelve editable fields into
a cache entry with a thirty-minute TTL and gated the resolver behind it. It was wrong in both
directions: it omitted `departureHour` and `averageSpeed`, which drive the weather riding window
and the sunset estimate, so editing either saved and recomputed nothing; and past the TTL an
identical replay re-triggered the entire pipeline.

## Decision

**What a request changed is read by comparing the state before it with the state after, and
never from a fingerprint kept somewhere else.**

A cached fingerprint answers falsely twice — too early, when it has forgotten and re-triggers
everything, and too late, when it hides the fields it never hashed. The resolver already
compares field by field; it only needed the right operand.

**That operand is `previous_data`, which the framework already provides.** `ReadProvider` clones
the resource before deserialisation and publishes it as a processor context key that
`ProcessorInterface` documents. We wrote none of it, and the first draft of this work created a
class that reproduced it exactly — worth recording, because the same mistake is available to
anyone who has just diagnosed the aliasing and reaches for a snapshot service.

**The lock reads that same before-image.** Reading the repository instead cut both ways: an edit
moving the start date into the future unlocked the trip it was editing, and an edit moving it
into the past made an ordinary trip refuse its own change.

### 423 is the temporal refusal, declared once

A trip whose start date has arrived no longer accepts the writes that rewrite its contents. That
was enforced by nine hand-written calls and published nowhere: eleven operations could answer
423 and the exported document mentioned it zero times, while the mobile client had been mapping
it to a "locked" failure all along.

It is now a flag on the operation, applied by `TripLockProcessor` and published by
`TripLockMetadataFactory` — the arrangement `If-Match` already used. The flag that enforces the
rule is the flag that documents it, so the two cannot disagree.

Four mutations keep their exemption, and the reasons are not interchangeable:

- **`DELETE /trips/{id}`** — the lock is monotonic, so refusing deletion would make every past
  trip permanently undeletable. That collides head-on with account erasure.
- **`/recompute`** — it is the only way to settle a half-finished pipeline, and a trip locks by
  the mere passing of midnight. Refusing it would freeze such a trip at `pending` for good, with
  the completion gate never closing again (recette #649).
- **`/duplicate`** — it does not touch the source.
- **share create and delete** — sharing a trip while riding it is the point of sharing, and a
  link that can no longer be revoked is a permanent leak.

### 409 means conflict with the current state, and nothing narrower

ADR-067 wrote that "409 Conflict stays what ADR-066 made it: failure to acquire the write lock".
The code had already outgrown it: six sites answer 409 with six different mechanisms behind it.
RFC 9110 §15.5.10 defines 409 as a conflict with the current state of the target resource, and
all six fit without strain.

**That sentence of ADR-067 is replaced.** 409 is the general answer; the mechanism is named in
`detail`, not in the status. The status genuinely reserved to one mechanism is **412**, and the
temporal refusal is **423** — that is the only boundary worth drawing.

## Consequences

`TripUpdateThroughDoctrineTest` is the first functional test to take the production path, and it
fails on the previous code. `TripLockedTest` carries the first end-to-end 423 assertions in this
repository, including what must keep working. `LockCoverageTest` stops the hand-written lock
calls coming back.

Left open, and deliberately: `/analyze`'s 409 still only fires on `running`, never on `pending`.
Widening it was tried and reverted — `pending` here means "initialised, never dispatched", which
is exactly the state a trip sits in when the rider asks for its first analysis, so the widened
guard refused the very first call. The vocabulary has no state for "dispatched, not yet picked
up", and until it does `running` is the only honest signal that guard has.

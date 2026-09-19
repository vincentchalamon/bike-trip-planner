# ADR-067 — Optimistic concurrency on trip edits

**Status:** accepted
**Date:** 2026-09-19
**Supersedes nothing. Builds on** [ADR-066](adr-066-stable-stage-identity.md) (stable stage
identity, the per-trip write lock, and the `trip.version` column).

## Context

ADR-066 gave stages an identity and serialised every write to a trip's stage collection behind
one lock. That closed the race *between two writes*. It did not close the one that matters to a
user: an edit computed against a view of the trip that the server has since moved past.

The window is wide. A rider opens the roadbook, the backend finishes enriching and republishes
the pacing, and the rider — still looking at the old screen — deletes what they believe is day
3. The write is perfectly serialised. It applies to a trip that no longer looks like the one
they were reading.

Nothing in the API let a client say *which* version of the trip it had read.

## Decision

Every operation that moves a trip's **structural version** requires an `If-Match` precondition
carrying that version, and every response that serves or moves it advertises it as an `ETag`.

- **428 Precondition Required** — no `If-Match` at all.
- **412 Precondition Failed** — an `If-Match` the trip has moved past.
- **409 Conflict** stays what ADR-066 made it: failure to acquire the write lock. A precondition
  failure is not a conflict of state, it is a failed precondition, and RFC 9110 separates the two.

`*` is accepted and means "whatever the current state is", per RFC 9110 §13.1.1.

### The perimeter is derived, not listed

An operation requires the precondition exactly when its processor moves the version — when it
calls `mutateStages()` or `increment()`. That is nine operations today: the seven that write the
stage collection, plus `PATCH /trips/{id}` and `POST /trips/{id}/recompute`, which bump the
version without rewriting the collection.

`POST /trips/{id}/stages/{stageId}/poi-waypoint` is deliberately outside it. It dispatches a
reroute that lands through a targeted write, so it never moves the version; requiring the header
would only make it harder to call. The perimeter was not reasoned into that shape — the coverage
test rejected the tenth operation when it was flagged by hand.

`App\Tests\Unit\State\PreconditionCoverageTest` scans the processors and checks the
correspondence **both ways**, so neither an unguarded new write nor a flag left on an operation
that stopped writing can pass.

### The comparison happens inside the write lock

This is the part that is easy to get subtly wrong.

Checking the precondition in the processor decorator alone leaves a time-of-check /
time-of-use window as wide as the processor body — and
`StageAddManualAccommodationProcessor` geocodes an address before it writes anything. Two
requests could both pass a check against version N, then write in turn: the lost update the
precondition exists to prevent, surviving underneath it.

So there are two checks, and they are not redundant:

- `App\State\PreconditionProcessor` — the header's presence (428) and syntax (400), plus a
  fail-fast 412 that spares the expensive processor body;
- `mutateStages()` / `bumpVersion()` — the authoritative comparison, under the lock, where
  nothing can slip between the comparison and the increment.

### The check runs after authorization, never before

`PreconditionProcessor` decorates the **write stage of the processor chain**, which runs after
the provider chain and therefore after `AccessCheckerProvider`.

A `kernel.request` listener would have been simpler and wrong: it runs before any provider, so
it would answer 412 or 428 on a trip the caller has no right to read, turning the pair into a
**version oracle on other people's trips**. Past the provider, a stranger has already been given
the 404 that [ADR-038](adr-038-hide-forbidden-as-not-found.md) masks their 403 as.
`TripPreconditionTest::anIntruderGetsTheSameAnswerWhateverVersionTheySend` pins it.

### The tag is strong, and the responses are `no-store`

RFC 9110 §8.8.3.2 mandates the **strong** comparison function for `If-Match`, under which a weak
validator never matches. `ETag: W/"7"` would have made the header inert — accepted by the
server, ignored by any conforming intermediary.

The tag is therefore `"7"`, strong. The price is honest and worth stating: the version is **not**
a byte-exact representation validator. Weather landing on a stage rewrites `/detail`'s body
without moving the version. Every response carrying the tag is served `Cache-Control: no-store`,
so nothing is in a position to use it as a cache validator — it is a precondition token and
only that.

### Mercure carries the version

Every event envelope carries `version` at its root, next to `correlationId`.

Without it the mechanism breaks on its most common path: a worker regenerating the pacing moves
the version with **no HTTP response** to carry a fresh `ETag`. The client would hold a version
the server has left behind and be refused on everything it tried next, until it reloaded the
page. Mercure is the invalidation channel; the version is the invalidation token.

### Clients pin, and never replay

Both clients keep the version per trip in **module scope, not in the store**. The store is
snapshotted for undo, and a version restored by Ctrl+Z would be one the server has already moved
past — every later edit refused until a reload.

The header is named at each call site rather than injected by a middleware. The generated types
mark `If-Match` required on exactly the guarded operations, so the compiler refuses a call that
forgets it *and* a call that sends it where it does not belong. A middleware would have set it
silently, and gone silently quiet the day a URL stopped matching its pattern.

On 412 the client re-reads the trip and tells the user to redo their change. **It never replays
the edit.** Replaying is the single recovery that could apply the edit to a state it was never
meant for — the exact failure this ADR exists to prevent, reintroduced as a convenience.

## What this does not cover

`PATCH /trips/{id}` writes the trip's settings row outside the stage lock, and only bumps the
version when the change triggers a recomputation. A settings-only edit is therefore still
last-writer-wins between two clients. That is a different aggregate from the stage collection and
a different fix; it is out of scope here rather than solved by omission.

## Alternatives considered

**A dedicated `If-Trip-Version` header.** Unambiguous, and free of the weak/strong subtlety
above. Rejected: it discards a standard that clients, proxies and tooling already understand, to
avoid documenting one deviation we are not in fact making.

**A weak ETag with weak comparison.** Semantically the most honest description of what the
version is. Rejected: it makes `If-Match` a no-op for any conforming intermediary, which is a
worse failure than a strong tag whose caching implications are neutralised by `no-store`.

**Doctrine's `#[ORM\Version]`.** Already rejected in ADR-066, for the same reason it would fail
here: Doctrine does not bump a parent's version when a child changes, and every structural edit
changes `Stage` rows, not the `TripRequest` row.

**Checking the precondition once, in the decorator.** Simpler, and it detects most conflicts.
Rejected because "most" is not the guarantee being claimed: with a seconds-wide TOCTOU the lost
update survives, and an ADR promising optimistic concurrency while shipping a best-effort check
is worse than one that does not promise it.

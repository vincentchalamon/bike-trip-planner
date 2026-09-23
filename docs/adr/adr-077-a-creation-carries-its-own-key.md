# ADR-077 — A creation carries its own key

**Status:** accepted
**Date:** 2026-09-23
**Continues** [ADR-076](adr-076-the-state-before-is-the-record.md), which settled what a *change*
is; this settles what a *repeat* is.

## Context

Of the thirty-one mutating operations, twenty-nine are already safe to repeat. Nine carry
`If-Match`: a replay sends a version the trip has moved past and takes a 412. The rest either
write nothing, converge, or are refused by a business rule.

Two are not. `POST /trips` and `POST /trips/{id}/duplicate` mint the identifier server-side, so
there is nothing for `If-Match` to pin, and the `trip` table has no unique constraint a second
identical creation would violate. A client that lost the response — a dropped connection, a
timeout, a killed app, a double tap — and asked again received **a second complete trip**: new
row, new pipeline, new Redis keys. The only thing in the way was a per-user rate limiter, which
caps the rate of duplicates rather than preventing any.

That is not a coincidence about those two endpoints. It is what a creation *is*: the operation
where the client cannot name what it is creating.

## Decision

**`Idempotency-Key` on the operations that create a trip, and nowhere else.**

An opaque client-minted string, 16 to 255 characters. The server never interprets it, only
compares it. Replaying it returns the trip the first call created rather than making another.

### The key identifies the intent, never the payload

It is not a hash of the body, and this is the load-bearing distinction. Two deliberate imports
of the same route must both succeed — someone who wants two trips from one GPX file is asking
for two trips. A body-derived key would silently collapse them into one and call it a feature.

The body fingerprint exists separately, as `request_digest`, and does one thing: the same key
arriving with a different body is a client contradicting itself, and is answered **409**. It
never identifies the request.

### Required, not optional

An optional header nobody sends is a guarantee that exists on paper. Nothing is deployed, so the
break costs nothing today and never will be this cheap again.

Missing or malformed answers **400**, not 428. "Precondition required" means *make your request
conditional*, and a client that receives it will go looking for an `If-Match` it cannot supply
on a creation. Two required headers, two distinct refusals.

### Postgres, not the cache

The five cache pools fall back to an array adapter under test, one per process, so a
cache-backed guarantee could not be tested at all — and a guarantee nothing can prove is not
one.

The unique index on `(user_id, operation, idempotency_key)` is the mechanism, not the lookup
that precedes it. Concurrent calls carrying the same key both insert; one loses; the loser reads
the winner's row and answers with that trip. This is `TripShareCreateProcessor`'s pattern
without the window its pre-check leaves open — a pattern whose own constraint, incidentally,
existed only in the SQL baseline and is now declared on the entity that depends on it.

Scope is `(user, operation, key)`. Not global, because the key belongs to the client that minted
it and two clients picking the same string must not be handed each other's trip. Not per trip,
because a creation has no trip yet — which is the entire reason this exists.

### The replayed answer is rebuilt, not remembered

Storing the serialised response would mean versioning that serialisation for as long as the keys
live. It is rebuilt from the recorded identifier instead, which costs one read and reports the
computation statuses **as they stand now** rather than a snapshot of the instant of creation —
strictly more useful to a client that is retrying precisely because it does not know what
happened.

Retention is 24 hours, purged by `app:idempotency:purge`. Past that window a repeat is no longer
a retry, it is a new intent.

### Applied in the processors, not a decorator

The lock and the precondition are write-chain decorators, and this was meant to be the third.
It cannot be: no position in that chain is handed the trip that was created — even the innermost
receives an already-serialised `Response`. A rule that only works from one position in a chain
is a rule that breaks the day someone inserts another decorator, so it is called explicitly from
the two creating processors, and `IdempotencyCoverageTest` binds the flag to them in both
directions.

## Consequences

**The rule that makes it worth anything is client-side**: one key per user intent, sent again
unchanged on every retry of it. Minted per HTTP attempt it protects nothing. Both clients
generate it at the call site for that reason, so a caller that owns a retry loop can hold on to
its own and pass it back in.

`POST /trips/gpx-upload` is left out. It is a plain Symfony route, outside the operation
metadata this is declared through, and it remains a third door to creating a trip — a replayable
one only once it goes through API Platform.

This is also the first piece of the API built for a consumer that does not exist yet. An MCP
agent (ADR-064) is episodic by nature: it calls, disappears, and comes back in another
conversation with no memory of whether its last call landed. The header is a draft standard
(`draft-ietf-httpapi-idempotency-key-header`), so such a client sends it without being taught.

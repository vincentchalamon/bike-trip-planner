# ADR-066: Stable Stage Identity

- **Status:** Accepted
- **Date:** 2026-09-18
- **Depends on:** ADR-032 (pre-launch migration baseline reset), ADR-043 (synchronous structural computation, asynchronous enrichments), ADR-057 (progressive trip loading)
- **Relates to:** #252 (Messenger race conditions), recette #649 (the "weather disappears" bug)

## Context and Problem Statement

A stage had no identity. `DoctrineTripRequestRepository::storeStages()` deleted every
`stage` row of a trip and re-inserted the collection, and the DTO-to-entity conversion
built `new Stage($trip)` without reusing the identifier — so the UUID of every row was
regenerated on every write. The only way to name a stage was its position in an array,
renumbered by every insertion, deletion and move.

That is not a latent design concern; it produces defects today.

**A write can land on the wrong stage.** The five targeted enrichment writes (weather,
alerts, resupply, accommodations, labels) address a stage by `dayNumber`. Every structural
edit renumbers those to `$i + 1`. A `FetchWeather` computed before a move therefore writes
its result onto a geographically different stage. This is not a lost write, it is a silent
corruption, and it is invisible to the user until they read a forecast for the wrong day.

**A write can be reverted.** Nine processors read the whole collection, mutate it in
memory and write it back, while eleven enrichment handlers write one column of one stage.
Nothing kept the two apart, so a worker's write landing between a processor's read and its
write was silently undone — the bug recette #649 reported, which the per-column targeted
writes narrowed but never closed.

**Two clients disagree about what the URL means.** The operations declare
`'index' => new Link(toProperty: 'dayNumber')`, but every provider and processor treats the
value as a 0-based array position. The PWA sends the position; the mobile app sends the
1-based `dayNumber`, on the strength of a comment asserting the opposite of what the server
does. Stage export on mobile therefore downloads the following day, and 404s on the last.

**The staleness guard can be switched off by time passing.** The generation stamped on
every async message lived in Redis as a non-atomic get/+1/set with a 30-minute TTL. Two
concurrent edits could be handed the same generation; past the TTL the key vanished and the
counter restarted from 1, so it could go *backwards* while messages were still in flight;
and a missing key reads as "not stale", so past that TTL the guard stopped rejecting
anything at all (#252, RC1 and RC5).

Finally, persisting enrichments per stage — the next unit of work — is not worth doing
while any edit obliterates the row they would live in.

## Decision

**A stage carries a stable identifier, and everything that addresses a stage addresses it
by that identifier.**

- The `Stage` DTO carries `id`, defaulted to a UUIDv7 at construction and never writable
  from a request body. Reconciliation only ever matches within the stages of the trip being
  written, so an identifier supplied by a client could not reach another trip's row.
- `storeStages()` reconciles instead of replacing: it updates the surviving rows, inserts
  the new ones and deletes the disappeared ones. The entity already accepted an identifier
  in its constructor; it had simply never been passed one.
- The five targeted writes and the scalar geometry read address `WHERE s.id = :stageId`.
- The three Messenger messages that named a stage (`RecalculateStages`,
  `ScanAccommodations`, `RecalculateRouteSegment`) carry identifiers, resolved to positions
  when the message is consumed. The handler then acts on the stages the sender meant even
  if they have moved, and skips the ones that no longer exist.
- Writes to the collection are serialised per trip by a repository decorator, so a
  read-modify-write and a targeted write interleave instead of overlapping.
- The generation becomes a `version` column on `trip`, bumped inside the write
  transaction: atomic and monotonic by construction, and it never expires.

### Stability has a boundary, and it is a behaviour, not an accident

A `stageId` is stable **within a pacing generation**. It survives an insertion, a move, a
deletion, a rest day and a distance edit. It does **not** survive a regeneration
(`GenerateStagesHandler`, `GpxUploadService`), which builds a new list from scratch.

This is deliberate. A regeneration does not produce the same stages: different endpoints,
often a different count. Reusing the identifier of whatever sat at position 3 would assert
a continuity that does not exist — and once enrichments are persisted per stage, it would
carry the weather, the scanned accommodations and the accommodation *the rider chose* onto
a day that now ends forty kilometres further on. Preservation would turn an inconvenience
into corruption.

A client holding a dead identifier gets a plain 404. That is an improvement: a stale
position returns 200 with another stage's data. There are no tombstones and no 410 —
tracking dead identifiers would cost forever for a marginal signal.

### What the version counts

The version is bumped by any write of the collection, including the ones a worker performs
when the pacing is regenerated. It is deliberately **not** bumped by targeted enrichment
writes: those are not structural changes, and counting them would make a client's
concurrency token go stale on its own while enrichments land.

## Consequences

### Positive

- Enrichment writes land on the stage they were computed for, across any number of
  intervening edits.
- A stage row survives editing, which is the precondition for persisting enrichments in it.
- The staleness guard stops being disarmed by a 30-minute silence.
- `getStageGeometry()` loses its latent `NonUniqueResultException`: it used
  `getOneOrNullResult()` on a day number nothing constrains to be unique.

### Negative

- Redis is now on the critical path of every stage write, through the lock. `LOCK_DSN` is
  set on `php` and `worker` in `compose.yaml`; it was **not** set in CI, where the lock
  component fell back to a store that hangs the suite rather than failing it. Fixed here,
  and recorded in the local test recipe.
- A write that cannot take the lock within a short deadline is refused with 409 rather than
  waiting. On the HTTP path a blocking acquire would tie up a PHP-FPM worker for as long as
  the holder runs, turning a slow write into an outage.
- `storeStages()` still writes the whole row, enrichment columns included. Making that
  partition explicit is deliberate rather than accidental, and is left to the unit that
  persists enrichments.

### Neutral

- No unique constraint was added on `(trip_id, day_number)` or `(trip_id, position)`. A
  reorder produces transient duplicates across the UPDATE sequence, whose order Doctrine
  does not control, so either would need to be `DEFERRABLE INITIALLY DEFERRED` — and once
  addressing goes through the primary key, neither protects anything.
- The addressing of the HTTP operations and the Mercure payloads is unchanged by this
  decision's first implementation step: the identifier is emitted but not yet consumed, so
  the identities can be observed to be stable before anything is hung off them.

## Notes for whoever implements the rest

Two things were measured rather than assumed, and both changed the design:

- **A re-read inside the critical section sees nothing new without `Query::HINT_REFRESH`.**
  It is served from the identity map, so a lock around a read-modify-write that re-reads
  through the ORM protects nothing at all.
- **The owning `PersistentCollection` is never re-synchronised.** It is initialised by the
  caller's read, so rows inserted or deleted meanwhile stay invisible to it even after a
  refreshing query. Reconciliation is therefore driven by a dedicated query, never by
  `$trip->stages`.

Both are pinned by `DoctrineStageRefreshSemanticsTest`, which exists so that a future
refactor that undoes them fails loudly.

A shared contract test now runs against both repository implementations. It was worth its
cost immediately: it caught the Redis implementation bumping the version on a targeted
write, and the Doctrine implementation serving stale stages from the identity map after
one — two divergences that no functional test could have seen, since the functional suite
only ever exercises Redis (`config/services.php`, the #56 TODO).

## Sources

- [ADR-032: Migrations and Rollback Strategy](adr-032-migrations-and-rollback-strategy.md) — pre-launch baseline reset addendum
- [ADR-043: Synchronous Structural Computation with Per-Block Asynchronous Enrichments](adr-043-synchronous-structural-computation-async-enrichments.md)
- [ADR-057: Progressive Trip Loading](adr-057-progressive-trip-loading.md)

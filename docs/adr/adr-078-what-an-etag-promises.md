# ADR-078 — What an ETag promises

**Status:** accepted
**Date:** 2026-09-23
**Continues** [ADR-067](adr-067-optimistic-concurrency-on-trip-edits.md), which made the trip version a write
precondition. This settles what that same number may and may not be used for on a read.

## Context

A unit planned as "volumetry, cache and performance" turned out to contain no performance work
that can honestly be called that. Nothing is deployed, no environment carries traffic, and a
duration measured on a developer's machine says nothing. What it does contain is **bounding** —
the API did not limit what a caller could ask it to do — and **contract honesty** — it
advertised, in its OpenAPI and in its headers, things that were not true.

Three of them:

- the exported OpenAPI has always said `itemsPerPage` has a maximum of 30, and the runtime
  enforced nothing: the bundle feeds that number to the documentation object and builds the
  `Pagination` service from a different parameter, which carried no maximum at all;
- `ETag` is a representation validator by RFC 9110 §8.8.3, and the one on a trip response is
  not one;
- `cache_headers.vary` was configured with three header names and **none of them ever reached
  the wire** — measured with a probe value, on every operation, including one that declared its
  own list.

## Decision

### A published limit is a limit

`pagination_maximum_items_per_page` is set to the number the contract already publishes.
Nothing that respected the published contract changes; a caller that ignored it stops being
served. This is not an optimisation, it is making the document true.

### The precondition token and the representation validator are two different things

`trip.version` moves on exactly two events: `storeStages()`, which rewrites the stage
collection, and the explicit bump taken by a settings edit or a batch recompute. The eight
targeted enrichment writes — weather, alerts by group, events, the supply timeline, resupply,
accommodations, labels — rewrite the body of `/trips/{id}/detail` and deliberately leave the
version alone. They must: a bump on every finished forecast would fail a client's edit with 412
for something the client did not do.

So on `/detail` the version is a precondition token wearing an `ETag`'s clothes, and what keeps
that honest is `Cache-Control: no-store`. **That header is the invariant, not a setting.**
Relaxing it to gain caching would start serving stale weather under a validator that says the
body has not changed.

**There is exactly one resource where the version is a true validator.** `GET
/trips/{id}/route` returns `{id, stages: [{dayNumber, geometry}]}` and nothing else, and both
mutable fields are written in one place — `applyStageDtoToEntity()`, reachable only from
`storeStages()`, which always bumps. That resource, and its anonymous twin
`/s/{shortCode}/route`, answer **304**.

They carry a second stamp rather than the first: same value, but `private, no-cache` instead of
`no-store`. The distinction is the whole mechanism — `no-store` forbids the client from keeping
a copy, so the conditional request that makes a validator worth anything would never be sent.
The two stamps are separate request attributes precisely so they cannot be confused.

### A conditional answer is an authorization decision

The obvious home for the check was a decorator of `api_platform.state_provider.read`, the same
seam the write chain uses for the precondition and the lock. It is the wrong home, and the
reason generalises.

`/s/{shortCode}/route` is public. What enforces revocation is `findByShortCode()` filtering on
`deletedAt IS NULL`, inside the provider. A decorator answers **before** that, so a client
holding a stale copy of a link the owner has revoked would be told its copy is still current.
Confirming a cached representation says "this is still yours to read", which is an
authorization statement, and it must be made behind whatever decides that.

So the check lives in `TripRouteProvider`, which returns a bare `Response` — a shape the
processor chain already passes through untouched.

*(Recorded because the instinct to reach for the decorator was strong, and because the
priority rule that governs those decorators is inverted from the obvious reading: the
**lowest** priority ends up outermost and runs first. The trip lock had been carrying a
positive priority on the belief that it made it run early, and answered after the precondition
for that reason.)*

### A read is sized to what it serves

`getStages()` was the only way to read the stage aggregate, so every caller paid full
hydration — eight JSONB columns per stage, one object per geometry point, every event and
accommodation mapped — whatever it needed. Two HTTP reads needed obviously less and now say so:
the route reads day numbers and geometry, the stage detail reads one row. Two more queries
joined the aggregate to read four scalars and now read four scalars.

This is not "the root cause is fixed": `getStages()` has around thirty callers, sixteen of them
message handlers that run off the request path and feed writes. Each of those needs its own
argument. What is fixed is the four places on the HTTP path where the need was visibly narrower
than the door.

### What is thrown away, and why

- **No `Last-Modified`.** There is no per-stage timestamp to derive it from, and second
  granularity is a weak validator — the same trap in a different shape.
- **No trigram index on the title filter.** `LOWER(title) LIKE '%…%'` is unindexable, but it
  runs over one user's trips after the owner index. A Postgres extension for that is an
  abstraction for a single use.
- **No index on `(trip_id, day_number)`.** Not because nothing queries by day number —
  `getStageIdByDayNumber()` does — but because `idx_stage_trip_position` already leads with
  `trip_id`, so what is left is a handful of rows.
- **No `docs/perf/postgres-audit.md`.** The audit [#522](https://github.com/vincentchalamon/bike-trip-planner/issues/522)
  asks for `EXPLAIN ANALYZE` over the ten costliest queries from `pg_stat_statements`; its own stated prerequisite is an
  environment deployed iso-prod, and none exists. An audit produced on a developer's machine
  would be theatre. The index half of that issue is done; the audit half waits for traffic.
- **The `vary` configuration**, which never reached a response. Configuration that looks
  load-bearing and is not is worse than none.

## Consequences

**What is measured is structural, and no gain figure is claimed.** On fifty thousand trips for
one owner, `EXPLAIN` on the list query goes from a sequential scan plus a full sort ahead of the
`LIMIT` to an index scan with an incremental sort. That is a plan shape. What it is worth in
milliseconds under real load is not knowable here, and the pull request says so rather than
inventing a percentage.

The severity of what this unit bounds is likewise stated at its real size. `itemsPerPage` and
the account export amplify a caller against **their own** data; the anonymous share needs a
valid short code, which is 48 bits of entropy. None of these is an unauthenticated denial of
service. They are amplifications available to someone with legitimate access, and per-IP and
per-user throttling is the ordinary answer.

**Left for a following unit:** `/s/{shortCode}` is the most cacheable surface in the product —
a public, read-only page — and it is explicitly excluded from caching, because the detail
provider stamps a precondition ETag on a response no anonymous reader will ever use as a
precondition, and the listener pairs that stamp with `no-store`. A listener written for the
authenticated write path is switching off the cache of the public page. Naming it here so it is
not rediscovered.

# ADR-074 — A trip has an address, and its answers are true

**Status:** accepted
**Date:** 2026-09-22
**Closes** lot C, after [ADR-072](adr-072-computation-state-is-contract.md) (computation state
is readable and durable) and [ADR-073](adr-073-supersession-settles-and-says-so.md)
(supersession settles). Pays off debts [ADR-070](adr-070-freshness-is-dispatch-completeness.md)
assigned to this lot.

## Context

The programme this lot belongs to is called "restructure the API for every client, then expose
an MCP". This closes it on the most literal reading of that sentence: **the canonical address
of a trip did not answer in JSON.**

`POST /trips` and `PATCH /trips/{id}` return a JSON-LD body carrying `@id: /trips/{id}` — the
functional schema requires it, with the pattern `^/trips/.+$`. The only `Get` operation on
`Trip` declared `outputFormats: ['gpx', 'fit']`. **The IRI the API hands out answered 406 to
`application/ld+json`**, and it did so during content negotiation, ahead of the security stage.

The repository already knew. `TransportAgnosticAuthorizationTest` carried this, as an
explanation of why one of its cases had to ask for GPX:

> the two export operations declare only gpx/fit, and content negotiation rejects anything else
> with a **406 before the security stage**, which would make the denial assertions vacuous

No test had ever asked that address for JSON, so nothing failed.

The bodies had their own problem. `Trip` declared `computationStatus = []` and
`isLocked = false` **as defaults**, and none of its three properties sits in a serialization
group — so a default is never *absent* from a response, it is emitted. `/recompute` answered
`new Trip(id: $tripId)` on both of its paths: it claimed nothing was being computed in the same
breath as dispatching the work. `/analyze`, `/accommodations/scan` and `/duplicate` each
claimed the trip was unlocked without asking. For a duplicate that is not a harmless default —
the clone inherits the source's start date, so duplicating a trip that has already started
produces a locked one and said otherwise.

## Decision

### One operation, three formats

`{._format}` already routes the two downloads. The `Get` gains `jsonld` alongside them:
`/trips/{id}` answers JSON-LD, `/trips/{id}.gpx` and `/trips/{id}.fit` answer exactly as
before. No URL moves, so no client changes.

The first design was three operations, mirroring the share resource — `/s/{shortCode}` plus
explicit `/s/{shortCode}.gpx` and `.fit`. That pattern only holds there because of a line that
is easy to read past: `requirements: ['shortCode' => '[A-Za-z0-9_-]+']`, which excludes the
dot. Without it `/trips/{uuid}.gpx` matches `/trips/{id}` with `id = "uuid.gpx"`, and `Trip`
declares no requirements. `{._format}` is the mechanism that already solves this correctly.

**The substance of the change is the provider.** `TripGpxProvider` returned a bare
`new Trip($id)`, which is all the GPX and FIT normalizers need — they reload the stages
themselves. Serving that on the canonical address would have answered an empty, lying body. It
now fills `computationStatus` and `isLocked`: the canonical read carries what the write
responses already carry.

The two reads do not have the same prerequisites, and that distinction is new. A file with no
stages in it is not a file, so the export still 404s; but a trip whose stages have not been
computed yet is an ordinary trip, and `POST /trips` hands out its `@id` before any stage
exists. Requiring stages for the JSON read would have left the IRI undereferenceable for
exactly as long as it matters most.

Side effect worth naming rather than claiming: this gives the polling `GET /trips/{id}/computations`
that ADR-072 deferred to phase 3, without a new resource.

### The body stops lying by default

`computationStatus` and `isLocked` become **required constructor parameters**. Filling the four
call sites by hand would have left the next processor free to re-emit the default; removing the
defaults makes the compiler name every site. `TripDetail::$isLocked` already had no default —
this is the same shape.

Six sites pass values now, and one of them passes a constant on purpose:
`StageResponseMapper` sets `isLocked: false` because all eight stage processors call
`TripLocker::assertNotLocked()` before writing, so a `StageResponse` only ever exists for a trip
that is provably unlocked. Reading the lock again there would buy a round trip to learn what the
guard has just established.

The four operations that misreported the lock — `/recompute`, `/analyze`,
`/accommodations/scan`, `/duplicate` — misreported it because none of them calls
`assertNotLocked()`. That cause is lot D's; only the body is corrected here.

### `Location`, and not `Retry-After`

A 201 or 202 now points at the resource with `Location`, read off the `@id` the body already
carries. One response listener covers both API Platform's responses and the hand-built JSON-LD
body of `POST /trips/upload-gpx`, without either having to remember to stamp anything.

**What it buys, honestly:** nothing for the clients this project has today, which all read the
body and find the same URI as `@id`. It is for a consumer that does not parse JSON-LD — the
agent this API is being restructured for, a `HEAD`, a proxy. RFC 9110 §15.3.3 asks a 202 to
point at something describing the request's status, and since this ADR that address answers.
It is exposed through CORS next to `ETag`, for the reason ADR-067 discovered: a header the
browser does not expose is one the client silently never reads.

`Retry-After` was in the unit's brief and is **not** shipped. RFC 9110 does not forbid it on a
202, it simply does not list it (§10.2.3 covers 503 and 3xx) — but the argument that decides is
simpler: **nothing polls.** Progress arrives over Mercure and the polling resource is deferred
to phase 3. A delay nobody reads is an invented contract; it ships with the poller.

### `computedAt` is gone

Written on every alert group write, read by nothing, dropped during hydration — flagged by
ADR-070, flagged again by ADR-072. Freshness is answered by dispatch completeness, and "never
computed" versus "computed, found nothing" by the presence of the group key, not by a
timestamp.

The wrapper stays. Both readers key on `['alerts']`
(`DoctrineTripRequestRepository`, `Stage::getAlerts()`), so writing a bare list would have
silently returned no alerts at all. Unifying the Doctrine shape with the transient one is a
separate change, and a larger one than it looks.

### No borrowed calendar for the sunset alert

`SunsetAlertAnalyzer` fell back to `today` when a trip had no start date, so its verdict was
frozen on whichever day the terrain scan happened to run. ADR-070 recorded that it "cannot
simply be withheld" — true of the *dispatch*, since it lives inside `TERRAIN` which the
geometry triggers on its own. Withholding the *verdict* was always available, and it is the
same answer ADR-072 gave for `weatherAvailability`: **no claim without a date.** "You will
arrive after dark" is a statement about a calendar the rider has not set.

### Five statuses, one declaration

`ComputationStatus` replaces bare strings in fourteen files. ADR-073 had to make five of them
agree by hand to add `superseded`; now there is one place to add a sixth, `isSettled()` states
which of them close the completion gate, and PHPStan sees the enum.

**The map is still `array<string, string>`** at the cache, at the mirrored column of ADR-072, on
the wire and in the two DTOs. Carrying the enum end to end would move three Mercure payloads,
`TripDetail::$categoryStatus`, `array_count_values($statuses)` and both clients — a change of
its own, listed below rather than smuggled in here.

## Consequences

`GET /trips/{id}` answers JSON-LD, so `@id` is dereferenceable and the authorization denial on
that operation is reachable in JSON for the first time — `TransportAgnosticAuthorizationTest`
stops working around a 406.

No client changes. The bodies gain honest values in fields they already received, and
`Location` duplicates information the JSON-LD body already carried.

`isLocked` on `/duplicate` and `/recompute` can now be `true` where it was always `false`. No
client reads it on those two.

## What this leaves open

- **`TripGpxProvider` used to write `$context['trip_stages']`** so the normalizers could reuse
  the fetched stages. `$context` is passed by value, so the mutation never propagated and both
  normalizers reload the stages themselves — the docblock promised an optimisation that was
  never in effect. Removed with the docblock; making it real is a different change.
- **`TripLocker::assertNotLocked()` is not called by seven mutating operations**, which is why
  their bodies could misreport the lock at all. Lot D.
- **The status map on the wire is still strings.** The enum stops at the server boundary.
- **The Doctrine and transient alert shapes still differ** (`{group: {alerts: […]}}` versus
  `{group: […]}`), now that `computedAt` no longer explains the difference.

## Alternatives considered

**Three operations, mirroring the share resource.** Rejected once its `requirements` line was
read: `Trip` has no such constraint, so `/trips/{uuid}.gpx` would have been ambiguous.
`{._format}` is the mechanism that exists for this.

**Adding `jsonld` to the existing operation and leaving the provider alone.** That is the
one-line version, and it serves `{id, computationStatus: [], isLocked: false}` on the canonical
address — the exact lie this ADR removes from the write responses, installed on the read.

**Filling the four constructor calls instead of removing the defaults.** Detects today's
lies and none of tomorrow's.

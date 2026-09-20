# ADR-068 — Enrichment durability, by group

**Status:** accepted
**Date:** 2026-09-20
**Builds on** [ADR-066](adr-066-stable-stage-identity.md) (a stage row now survives the next
edit) and [ADR-048](adr-048-in-ride-assistance-without-ai.md) (persist the fact, derive the verdict).

## Context

Of the thirteen alert producers, exactly one wrote to the database. The other twelve —
calendar, wind, bike shops, water points, health services, cultural POIs, railway stations,
border crossings, ferries, fords, and the resupply and accommodation nudges — published over
Mercure and stopped there. So did the stage events and the supply timeline.

That breaks the programme's first principle: **Mercure is an invalidation channel, never a
data channel.** Anything pushed over SSE must be retrievable with a GET.

The consequence is not theoretical. An anonymous visitor to `/s/{shortCode}` never receives
SSE, so the public share page served a roadbook missing twelve families of alerts. A reload
and the mobile offline cache had the same hole.

ADR-066 was the hard prerequisite: persisting an enrichment into a row that the next edit
obliterates would have bought nothing.

## Decision

### One column, thirteen groups

`stage.alerts_by_group` holds `{group: {computedAt, alerts[]}}`. `App\Enum\AlertGroup`
enumerates the producers. The old `alerts` column is gone — a single representation, terrain
included.

A group is not a display category: it names **which computation owns those alerts**, and
therefore the unit in which they are replaced. Re-running the ferry check must replace ferry
alerts and leave the twelve others standing. `ford` covers both the dry and wet variants,
because one re-run replaces both.

**An absent key and an empty list say different things.** Absent means the producer has never
run for this stage; empty with a `computedAt` means it ran and found nothing. Nothing else in
the model carries that distinction, and lot C needs it.

### Stored verbatim, not normalised

Each producer already builds the array it publishes. It now hands **that same array** to the
database and to Mercure. GET/SSE parity holds by construction rather than by vigilance.

Going through `App\ApiResource\Model\Alert` would have dropped `poiName`, `poiType`,
`imageUrl`, `openingHours`, `estimatedPrice`, `wikidataId` and `distanceFromRoute` — fields
only some producers emit and none of which the model declares. `alertToArray`/`arrayToAlert`
and their `_class` discriminator are gone along with the conversion they existed for; the
serialisers are pass-throughs.

Two fields are dropped on the way in. `stageId` becomes the key. **`dayNumber` is not
persisted**: every structural edit renumbers it (`$i + 1`), so freezing it into an alert would
recreate exactly the drift ADR-066 removed. It is derived from the owning stage on read.

### The write takes no lock

One `jsonb_set` UPDATE per stage, merged by Postgres. Under READ COMMITTED a blocked UPDATE
re-evaluates against the row version the winner committed, so a dozen producers finishing at
once all survive.

Deliberately outside the per-trip lock ADR-066 introduced. That lock exists for
read-modify-write sequences; these are not. Routing twelve parallel handlers through a lock
with a three-second bounded acquire would turn a burst of enrichments into failed
computations.

One wrinkle worth recording: an empty PHP array encodes as `[]`, not `{}`, so a stage that has
never been enriched holds a JSON *array*, and `jsonb_set` refuses a text path against one. The
statement normalises with `jsonb_typeof` before merging.

### `storeStages()` stops writing enrichment

The partition ADR-066 deferred. A structural edit writes the structure; the enrichment columns
belong to the producers that compute them. Without it, an edit replays whatever snapshot the
processor happened to read over a worker's write.

The Doctrine implementation gets this by simply not touching those columns. The transient
implementation stores the whole collection as one blob, so it has to carry them over by hand —
**the contract suite is what caught the two disagreeing**, which is the reason that suite
exists.

### The group travels on the wire

Three hydration points tagged every persisted alert `_group: "terrain"`. Harmless while
terrain was the only group persisted; with thirteen, the first `terrain_alerts` event would
have wiped the other twelve. That trap would have cancelled the whole benefit.

The server now stamps the owning group on every alert it serves or publishes, and the clients
read it. `_group` and `group` collapse into one field: there is nothing left to synthesise.

`group` is enumerated in the OpenAPI schema, so `core/schema.d.ts` types it as a literal union
and `ALERT_GROUPS_MATCH_THE_SCHEMA` fails the build when the two lists diverge. **That is why
no cross-language drift test guards them** — a compiler beats a file scanner.

Making that guard real needed two fixes that are worth naming, because both had made it
decorative:

- `additionalProperties: true` on the alert item made the generated type
  `{…} & { [key: string]: unknown }`, so indexing `["group"]` collapsed to `unknown` and the
  comparison was vacuous. The producer-specific fields are enumerated instead.
- `core` had a working `tsconfig.json` that nothing ever ran. Consumed through a workspace
  symlink, tsc treats it as a dependency and skips its sources, so a blatant type error in the
  package **both clients share** went unnoticed. It now has a `typecheck` script in CI.

## Freshness is a read-time question

Persisting raises "how stale is this?". For ten of the thirteen groups the question dissolves,
because what is persisted is a **fact** and what the user reads is a **verdict**.

A water point exists or it does not — ADR-049 already assumes obsolescence for reference data
and declines to issue a staleness verdict. "Is it open right now?" and "does this ferry run on
the stage date?" are recomputed at read time from the stored tag and the clock, which is the
pattern `OpeningHoursParser::status()` has followed since ADR-048.

Two triggers remain, both already computed: **geometry** (terrain and the positional scans,
through `geometryUnchanged()`) and **trip dates** (calendar, events).

The one real exception is **weather**, which is a forecast rather than a fact. Its horizon is
about fourteen days; past that it is not stale but **unavailable** — a third state, distinct
from "failed" and from "not yet computed", to be exposed as such in lot C.

*Scope note: this ADR fixes the storage shape and the group contract. Wiring the read-time
derivation and the two invalidation triggers is the next unit; until then a merged stage can
briefly carry terrain alerts computed on its previous geometry, self-healing because every
structural edit already dispatches a recomputation.*

## Alternatives considered

**A fingerprint per group, keyed on a provisioning version.** Rejected: re-provisioning is a
rare manual operator act (ADR-036), so the trigger would almost never fire, at the cost of a
write on the provisioner side and a cross-database read (PG-app to PG-reference, ADR-060).
More machinery than it earns. A coarse TTL stays an acceptable fallback if some group resists
read-time derivation; it is not the mechanism.

**A cross-language drift test** comparing the PHP enum with `reconciliation.ts`. Superseded by
the schema union above: the same guarantee, enforced by the compiler on both clients, with no
file scanning.

**Normalising alerts into a richer `Alert` model.** It would need a subclass per producer —
the `CulturalPoiAlert` discriminator was the first one, and twelve more would follow. The
payload is already the contract; modelling it twice is what made the fields go missing.

**Keeping `alerts` alongside `alerts_by_group`.** A smaller first step, at the price of two
representations coexisting and every reader having to merge them. There is no deployed
environment, so the migration is free now and will not be later.

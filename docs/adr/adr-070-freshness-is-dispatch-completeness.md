# ADR-070 — Freshness is dispatch completeness

**Status:** accepted
**Date:** 2026-09-21
**Closes** lot B, after [ADR-068](adr-068-enrichment-durability-by-group.md) (enrichments
survive the next edit) and [ADR-069](adr-069-alerts-rendered-at-read.md) (their language is
chosen at read time).

## Context

ADR-068 made enrichments durable. That is what turned freshness into a real question: an
alert computed against something that has since moved no longer disappears on its own.

The plan for this unit was to answer by **deriving verdicts at read time** — recomputing
"are these shops closed when you pass?" on every read rather than storing the answer. Reading
the code first invalidated the premise.

**No verdict in this domain drifts with the clock.**
`ScanPoisHandler::allResupplyPoisAreClosed()` reads the ISO weekday of the *stage date* and a
passage time estimated from `departureHour`/`averageSpeed`; `SeasonalityChecker::isLikelyOpen()`
reads the *month of the stage date*. Neither consults `now`. They become wrong when an
editable field moves, not when time passes. The one thing that genuinely ages is the weather
forecast, and it is out of scope here.

So the defect was never a modelling problem. It was four dispatch tables that had drifted:

| table | role | what it was missing |
|---|---|---|
| `TripAnalysisDispatcher` | the canonical full pipeline | — |
| `RecalculateStagesHandler` | after a structural edit | 7 of 12 |
| `ComputationTracker\ComputationDependencyResolver` | changed field → computation | `startDate` had 2 of 5 |
| `Service\ComputationDependencyResolver` | modification type → messages | `dates` had 4 of 6 |

The same three computations — `POIS`, `ACCOMMODATIONS`, `TERRAIN` — were missing from a date
change in **both** resolvers, independently. That is the signature of duplication, not of an
oversight.

What that cost, concretely: a stage merge concatenates two geometries, and seven groups kept
alerts drawn from a line that no longer existed. A change of start date left the resupply
verdict on the old weekday, the seasonal one on the old month, and the sunset alert on the
old date.

## Decision

**Each computation declares what invalidates it, once.** `ComputationName::triggers()` returns
the `ComputationTrigger`s — `GEOMETRY`, `DATES` — and every dispatch site asks the table
instead of carrying its own list.

### Two triggers, and why not more

`GEOMETRY` is the stage line, its end points, the corridor drawn around it. `DATES` is the
calendar date a stage falls on, `startDate` plus its day number.

They are triggers, not fields, because the same invalidation arrives by different routes: a
`PATCH` on `startDate` and a rest-day insertion both shift every later stage's date, and a
caller should not have to know which computations care.

### Most computations depend on both

This is what made the earlier lists wrong, and why a split into two disjoint sets would have
reproduced the bug in a new shape. A resupply scan reads the corridor *and* the weekday; an
accommodation scan the end point *and* the month; the terrain analysis the line *and* the
date, because the sunset alert lives there. Only `CALENDAR` reads a date and no geometry.

`WIND` and `FORDS` declare nothing: `FetchWeatherHandler` cascades them once the forecast
lands, so they follow `WEATHER`. `ROUTE`, `STAGES` and `ROUTE_SEGMENT` are not enrichments.
`ComputationTriggerTest` asserts that every other case declares at least one trigger, so a
new enrichment cannot be added without deciding — that guard is the point of the table.

### One factory for the message

`EnrichmentMessageFactory` maps a computation to its message. Three callers used to hold their
own mapping, which is how a `PATCH` could dispatch a computation a structural edit never did.
It throws for a computation it has no message for, rather than skipping: the equivalent
fail-fast in `TripUpdateProcessor` is what made the last gap visible, and neutralising it would
have hidden the next.

## Consequences

A stage merge now dispatches twelve computations instead of five, and a start-date change six
instead of two. Weather is geometry-dependent — it is fetched per stage coordinates — so a
merge refetches it, within the 3 h cache of ADR-022, and cascades `FORDS`/`WIND`. The handlers
are idempotent and generation-guarded (`AbstractTripMessageHandler::isStale`), so the cost is
messages, not correctness.

Two dispatches that used to be hand-written disappear: `distance` no longer re-runs the
calendar (the stage count is unchanged, so no stage changed date) and a date change no longer
re-scans cultural POIs (they read no date at all). Both were over-dispatching.

`StageDeleteProcessor` no longer dispatches `AnalyzeTerrain` by hand for a rest-day deletion:
the date set carries it, which is what restores the "consider a rest day" nudge on the
preceding day. `RestDayInsertProcessor` keeps its explicit dispatch — the nudge is a *layout*
concern that holds even on a trip with no dates — and excludes terrain from the date set to
avoid dispatching it twice.

## What this leaves open

- **`computedAt`** is written into `alerts_by_group` (`DoctrineTripRequestRepository:632`),
  **never read**, and dropped during hydration (`:917`) before it reaches the DTO. It is a
  wall-clock timestamp, not a version, so there is nothing to compare it against. ADR-068
  justified it as something lot C would need; with freshness answered by dispatch, nothing
  needs it. It should be removed unless lot C finds a use — the "never computed" versus
  "computed, found nothing" distinction is already carried by an absent key versus an empty
  list.
- **A trip with no start date** falls back to `today` in three places
  (`CheckCalendarHandler:86`, `SunsetAlertAnalyzer:58`, `FetchWeatherHandler:74`), which
  freezes the alert on the day it was computed. This is the only genuine clock drift outside
  the weather, and no trigger can catch it — nothing changed. Lot C.
- **Weather beyond about fourteen days** is not stale but *unavailable*, a third state next to
  "failed" and "not yet computed". Lot C, as ADR-068 already recorded.
- **The seasonal verdict** is the one place where deriving at read would remove a costly
  re-scan: a date change re-scans every stage's accommodations against an external source to
  re-decide a question that only depends on the month. It is the concrete candidate if lot C
  revisits derivation.

## Alternatives considered

**Deriving the two verdicts at read time**, as the unit was originally scoped. Rejected once
it was clear they do not drift with the clock: it would have meant widening what is persisted
(`openingHours` through three serialisations that drop it, plus a `seasonal` tag that is never
stored) and parsing opening hours on every read, to solve by machinery what declaring a
dependency solves. It would also have split the answer in two — dispatch for ten groups,
derivation for two — when all twelve are invalidated by the same kind of event.

**A persisted geometry fingerprint per group**, compared at read. It hides a stale alert
rather than refreshing it, so the reader sees fewer alerts than they should, and it adds the
machinery ADR-068 had already declined.

**A shared helper for the stage date.** The derivation is duplicated six times in two forms,
`dayNumber - 1` and the loop index `$i`. They look like they could diverge, but every
structural edit renumbers `dayNumber = $i + 1` over the ordered list
(`StageMoveProcessor:81`, `RestDayInsertProcessor:86`, `StageCreateProcessor:126`,
`StageDeleteProcessor:80`), so the two always agree. Deduplicating it is worth doing and
changes no behaviour, which is exactly why it does not belong in a change about freshness.

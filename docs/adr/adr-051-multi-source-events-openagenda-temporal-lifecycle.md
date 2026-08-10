# ADR-051: Multi-source Events, OpenAgenda, and the Temporal Lifecycle

- **Status:** Accepted — events become a multi-source layer with a relevance-filtered, deduplicated, distance-ranked read. OpenAgenda is the second source (delivered separately, [#984](https://github.com/vincentchalamon/bike-trip-planner/issues/984)); the weekly temporal refresh is delivered separately ([#985](https://github.com/vincentchalamon/bike-trip-planner/issues/985)). This ADR records the shared decision the three issues build on.
- **Date:** 2026-08-10
- **Depends on:** ADR-040 (Local-first reference data — single PostGIS source), ADR-026 (external data sources and attribution), ADR-049 (Zone opening and import-time completeness).
- **Supersedes / relates to:** ADR-044 (removal of the data.gouv markets source) — the multi-source *shape* this ADR generalises is the one ADR-044 removed for markets; events reintroduce it deliberately, with the deduplication and relevance the markets experiment lacked.
- **Numbering note:** ADR-048 is reserved by [#936](https://github.com/vincentchalamon/bike-trip-planner/issues/936), ADR-049/050 are taken. The next free number is 051.

## Context and Problem Statement

Events were the last reference layer wired to a single source in a way no other layer still is. Concretely, before this decision:

- `tourism.events` had **no `source` column**; the runtime stamped `source: 'datatourisme'` as a hard-coded constant in `ScanEventsHandler`.
- There was **no deduplication**: had a second source existed, the same festival would have appeared twice.
- `EventRepository::findActiveNear` ordered by `start_date`, capped at `LIMIT 100`, with **no link filter and no relevance filter** — it returned up to a hundred events a day, earliest-starting first, including linkless entries a rider cannot act on and categories (a generic fallback, young-audience activities) a bikepacker does not plan a detour around.

Every other curated layer already had the multi-source shape this one lacked: POIs, food POIs, cultural POIs and accommodations each expose a `…SourceInterface` + `…SourceRegistry` tagged-iterator, and the OSM/DataTourisme overlap is collapsed by `NearbyNameDeduplicator` (ADR-040). Events were the exception, and adding OpenAgenda as a second events source ([#984](https://github.com/vincentchalamon/bike-trip-planner/issues/984)) is impossible without first giving events the same shape.

Two further properties of events, absent from the other layers, shape this decision:

1. **Events are dated and perishable.** A POI is there next year; an event that ended last week is dead weight. The append-only, obsolescence-assumed storage model of ADR-040 is correct for places but wrong for events, which need a *temporal* lifecycle — a periodic refresh that adds what is upcoming and drops what has passed ([#985](https://github.com/vincentchalamon/bike-trip-planner/issues/985)).
2. **Relevance is about the rider's detour, not the catalogue.** A curated catalogue lists everything; a stage roadbook should surface the handful of events near where the day ends that a rider would actually reroute for.

## Decision

### 1. `source` becomes a per-row column, not a runtime constant

`tourism.events` gains `source text NOT NULL DEFAULT 'datatourisme'` (forward migration `Version20260810120000`, on top of the collapsed baseline of [#972](https://github.com/vincentchalamon/bike-trip-planner/issues/972)). The default backfills the rows the flux already imported and lets a DataTourisme promotion run without spelling the origin out on every COPY line. The provisioner writes it explicitly all the same (`DataTourismeImporter::TABLE_COLUMNS`, staging DDL), so a second source that writes a different value slots in with no schema change. The runtime reads the column instead of the constant.

### 2. The multi-source runtime mirrors the POI pattern exactly

A tagged-iterator `EventSourceInterface` (`app.event_source`) + `EventSourceRegistry`, copied from `App\Poi\PoiSource*`. `DataTourismeEventSource` adapts the existing `EventRepository`; OpenAgenda joins by implementing the same interface, tagged automatically. The registry merges every source and collapses the same event reported twice through the shared `NearbyNameDeduplicator` (name + 75 m proximity, the curated DataTourisme entry winning on a tie — the same rule already used for places). Reusing the existing deduplicator, rather than inventing an event-specific key, is deliberate: events carry no stable cross-source id, so name-and-proximity is the only identity available, and it is exactly what ADR-040 already settled for the OSM/DataTourisme place overlap.

### 3. Relevance, link and cap are decided once, across sources

The registry applies, after the merge and dedup, the rules that must hold identically whatever the origin:

- **A link is mandatory.** `EventRepository` filters `url IS NOT NULL AND url <> ''` in SQL (efficient, before the per-source cap); the registry re-checks it so a source that forgets cannot leak a linkless event into a stage. An event a rider cannot open is noise.
- **Relevance is a category whitelist.** Only `festival`, `concert`, `exhibition`, `sports`, `fair`, `show` survive — the app-normalised event vocabulary the DataTourisme mapper already produces. The generic `event` fallback and any young-audience / children category a source normalises outside this set are dropped. Because each source normalises its own taxonomy onto this one vocabulary *before* the registry sees it, this single whitelist **is** the shared audience filter across sources: there is no per-source relevance code to keep in sync.
- **Ranked by distance to the stage end point, capped at 20.** A rider cares about what is near where the day ends, not about the earliest start date; and a roadbook shows a handful of events, not a hundred. The repository orders by distance and bounds the per-source read; the registry re-ranks the merged set by distance and caps it.

### 4. The temporal lifecycle is a periodic refresh, not the append-only place model

Events are perishable, so the append-only / obsolescence-assumed storage of ADR-040 does not carry over unchanged. The events layer is refreshed on a schedule (weekly, orchestrated out of band — the detail is [#985](https://github.com/vincentchalamon/bike-trip-planner/issues/985)'s to fix), and a refresh **purges** events that have passed rather than accumulating them. This is the one place the reference-data storage model is intentionally *not* append-only, and it is scoped to events precisely because only events have an expiry. Places keep the ADR-040 model untouched.

## Consequences

### Positive

- Events reach parity with every other curated layer: adding OpenAgenda ([#984](https://github.com/vincentchalamon/bike-trip-planner/issues/984)) is now "implement `EventSourceInterface` and tag it", with dedup, relevance, link filtering and the cap already handled centrally.
- The stage roadbook shows relevant, openable, nearby events instead of up to a hundred date-sorted entries including noise.
- The relevance and audience rules live in exactly one place (`EventSourceRegistry`), so they cannot drift between sources.
- DataTourisme is non-regressed: the column defaults to `datatourisme`, the importer still writes it, and the existing read path returns the same events minus the linkless and irrelevant ones.

### Negative

- The distance cap is applied per source then re-applied on the merge, so a source's nearest-20 is what feeds the union — a globally-21st-nearest event a source did not return in its own top-20 cannot reappear. Accepted: the per-source bound is generous relative to how many relevant, dated, in-radius events a 20 km disc holds on a given day.
- The relevance whitelist is a fixed list in code. A new event category worth surfacing needs both the source's mapping and this whitelist updated. Accepted as the price of deciding relevance once rather than per source.

### Neutral

- The 20 km radius and the 20-event cap are constants this ADR introduces but does not sanctify; they can be tuned without reopening the decision.
- The temporal refresh mechanism (scheduler, purge SQL) is named here as a decision but specified and delivered in [#985](https://github.com/vincentchalamon/bike-trip-planner/issues/985); this ADR fixes only that events are refreshed-and-purged rather than accumulated.

## Sources

- [ADR-040](adr-040-local-first-reference-data-postgis.md) — local-first PostGIS reference index, `NearbyNameDeduplicator`, and the append-only / obsolescence-assumed storage model this ADR narrows for the perishable events layer.
- [ADR-026](adr-026-multi-source-data-integration.md) — external data sources and attribution; the parent of the multi-source data model.
- [ADR-044](adr-044-removal-of-data-gouv-markets-source.md) — the earlier multi-source events experiment (markets), removed; the shape reintroduced here with dedup and relevance.
- [ADR-049](adr-049-zone-opening-and-import-time-completeness.md) — per-zone promotion and import-time completeness, the pipeline the events column and COPY order slot into.
- [Issue #975](https://github.com/vincentchalamon/bike-trip-planner/issues/975) — multi-source events, relevance filtering and cap (this ADR's origin).
- [Issue #984](https://github.com/vincentchalamon/bike-trip-planner/issues/984) — OpenAgenda as the second events source.
- [Issue #985](https://github.com/vincentchalamon/bike-trip-planner/issues/985) — weekly temporal refresh and purge.

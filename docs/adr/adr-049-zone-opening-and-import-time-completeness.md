# ADR-049: Zone Opening and Import-Time Completeness

- **Status:** Accepted — §6 (routing/reference decoupling) landed with [#881](https://github.com/vincentchalamon/bike-trip-planner/issues/881); §1–§5 are the model implemented by the sprint-49 series (#883–#886, #891)
- **Date:** 2026-08-05
- **Depends on:** ADR-040 (Local-first reference data — single PostGIS source), ADR-041 (Provisioner resilience), ADR-017 (Valhalla routing engine)
- **Reformulates:** ADR-040 (§ atomic swap, § cadence, § beta perimeter), ADR-033 and ADR-036 (refresh), ADR-041 (§ R5 freshness), ADR-017 (Valhalla dataset)
- **Numbering note:** ADR-048 is reserved by issue [#527](https://github.com/vincentchalamon/bike-trip-planner/issues/527) (Sprint 39, backup & disaster recovery), not yet written to disk. This work therefore takes 049, and the terrain-attribution decision takes 050.

## Context and Problem Statement

ADR-040 made the provisioner the single aggregator of Tier-1 reference data and the API a local-only reader. It left the *import* model implicit, and the data-quality diagnostic that followed showed the implicit model is the problem, not the sources.

Four properties of the current implementation are in the way:

1. **There is no such operation as "opening a zone".** `make provision` takes no zone argument. `RegionSelectionStore` keeps a **cumulative** list of slugs in `regions.json`, and every run re-downloads, re-merges and re-imports **all** of them. Opening 13 regions one at a time means 13 full re-imports of a growing dataset.
2. **The swap is global.** `PostgisImporter::swap()` runs `DROP SCHEMA osm CASCADE; ALTER SCHEMA osm_staging RENAME TO osm`. Every zone is exposed to every import, and a schema-name desynchronisation between `PostgisImporter` and `tier1.lua` would rename an empty staging schema over the live one — **destroying the data**. The risk is written in a comment at lines 28-35 of that file, which is the tell that the design, not the reader, is at fault.
3. **Completeness is decided nowhere, so it is decided everywhere.** The measurement in [#878](https://github.com/vincentchalamon/bike-trip-planner/issues/878) counted 631 unnamed rows out of 8 824 accommodations (7,2 %) once furniture-grade `shelter` is set aside, and 6 129 of the 8 062 `shelter` rows turned out to be street furniture. Nothing decides at import time whether such a row is worth keeping, so each reader re-decides — or does not.
4. **One file coupled two datasets.** `default.osm.pbf` was both the provisioner's merge output and Valhalla's only input. With one zone per run, opening Brittany would have broken routing in Hauts-de-France.

A fifth property was found while measuring, and it changes the shape of the answer. [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880) established that Geofabrik regional extracts are **clipped**, so a boundary relation whose ways cross the extract border is incomplete and `osm2pgsql` skips it: on the local two-region set, level 2 built **0 of 12** relations, level 4 **0 of 23**, level 6 **11 of 64**, level 8 **4 308 of 4 795**. The `admin_level = 2` union therefore wrote a single `NULL`-geometry coverage row, i.e. no coverage at all. **A zone's own extract cannot be trusted to describe the zone's boundary** — which is why coverage is derived from every imported level, and why the routing perimeter cannot be inferred from the reference perimeter.

## Decision

### 1. Zone opening, one zone per run

A zone is a Geofabrik slug, passed as a **mandatory argument**: `make provision <zone>`. `RegionSelectionStore`, `regions.json` and the interactive selector disappear. The source of truth for what is open becomes a **registry in the database**, which also serves coverage.

Two adjustments follow from the rule rather than bending it:

- **`France (entière)` (4 400 MB) leaves the reference registry.** A whole-country entry defeats "one zone per run" — it is the routing grain, not the reference grain.
- **Belgium, the Netherlands and Luxembourg exist at country level only** on Geofabrik. The rule is therefore "the finest grain Geofabrik publishes", and these three are a documented exception, not an entorse.

### 2. Transactional per-zone promotion replaces the global swap

Promotion becomes an `INSERT ... SELECT` in a single transaction, from a staging schema scoped to the zone. `DROP SCHEMA osm CASCADE` disappears.

**Safety increases rather than being merely preserved.** The failure mode that today destroys data — a schema name out of sync — now yields 0 rows or an error. Other zones are never exposed. This is the reverse of the usual trade: the more granular operation is also the safer one, because it no longer needs a destructive step to be atomic.

### 3. A completeness gate at import time, decided once

A row that no resolver can complete is **not imported**. The decision belongs to the gate, and the invariant is carried by a **schema constraint** (`CHECK`), not by a read-time clause. A read filter would duplicate the decision and, worse, **mask** the gate's bugs instead of revealing them.

The constraint is **per category**, and generalising would do damage: "unnamed means useless" holds for a bookable accommodation, not for a water point, a ford or a ferry whose coordinates are enough to act on. The `shelter` / `wilderness_hut` case is arbitrated by the sprint-47 measurement: completeness required on every accommodation category **except `shelter`**, with **no exemption for `wilderness_hut`** (6,3 % unnamed, no possible recovery, and 5 of the 20 affected huts are `access=private`). `shelter` is filtered on `shelter_type` instead — [#927](https://github.com/vincentchalamon/bike-trip-planner/issues/927) already removed it from the lodging vocabulary while keeping its rows for the in-ride reader.

### 4. Complete without rewriting

Two cases, neither requiring a rewrite:

- **Row present, incomplete on a non-identifying field:** fill by **COALESCE only**, never replacing an existing value. This is completion, not rewriting, and the repository already made it the norm — `WikidataEnrichmentPass:138` writes exactly `SET website = COALESCE(t.website, c.payload->>'website'), ...`.
- **Row rejected by the gate, therefore absent:** the cache holds it as `status = 'insufficient'`, which makes re-opening cheap but would deny it a better resolver later. **`resolver_version` therefore lives in the cache**, not on the live tables and not on the registry. The anti-join against live tables stays purely identity-based (hence cheap), and retroactivity is carried where it costs least.

This mechanism does **not** correct a wrong value already in the database — excluded by §5.

### 5. Append-only, with obsolescence assumed

A row removed from OSM **stays in the database**. That is the direct consequence of "never overwritten, never rewritten", and it is **assumed**: total reliability of a listing is not reachable, and the person who books is the person who verifies. One exception, `last_seen_at` — **metadata only, never the payload**.

Two consequences: **no staleness guard** — `STALE_THRESHOLDS` is deleted and not replaced (done in [#926](https://github.com/vincentchalamon/bike-trip-planner/pull/926)), age remains an internal metric that is not displayed; and the verification link (website, phone) stops being cosmetic and becomes the rider's tool.

### 6. Routing perimeter and reference perimeter, decoupled

Routing gets its own stock and its own calendar (landed in #881: the one-shot `valhalla-builder`, `make routing-build <slug>`, a serve-only `valhalla`). The invariant that replaces the file coupling: **the routing perimeter encompasses the reference perimeter**, enforced by refusing to open a zone the graph does not cover, and asserted in `/api/health`.

No synchronisation, no cross-dependency. The inverse asymmetry is healthy: routing may cover a country with no reference data — a trip there is routable and displayable but not enriched, the degradation ADR-040 already provides for.

Note what the coverage measurement above forbids here: the check cannot be "does the reference extract's country polygon exist in the graph", because a regional extract has no country polygon. The invariant is asserted between the **registry** (what has been opened, and its geometry as actually imported) and the **routing perimeter** (the national slugs built into the graph) — two explicit lists, not two inferred geometries.

### 7. What this model does not make incremental

Rebuilding the graph stays O(routing perimeter), SRTM elevation included; the `gis-ops` image cannot add a country to an existing graph. The separation makes the rebuild **non-blocking and schedulable, not incremental**. Named here rather than left to be discovered.

## Consequences

### Positive

- Opening a zone becomes a bounded operation whose cost is proportional to that zone, instead of a full re-import of everything opened so far.
- The destructive failure mode disappears: the worst outcome of a promotion bug is 0 rows or an error, never a dropped live schema.
- The completeness decision is in one place and is enforced by the database, so a gate bug surfaces as a rejected insert instead of quietly thinning a reader's results.
- Re-opening an unchanged zone is cheap (identity anti-join, no enrichment network calls), which makes frequent re-opening affordable — the property the cumulative model destroyed.
- A long or failed graph build can no longer take routing down, nor block a reference import.

### Negative

- **Deleted OSM entries linger.** A closed campsite stays listed until someone notices. Assumed: the alternative is a reconciliation pass that must decide "absent from this extract" versus "outside this extract", and a clipped extract cannot tell the two apart.
- **The gate loses rows silently unless it reports.** A `CHECK` that rejects is invisible without the `rejected.tsv` output and the opening report (#886); the constraint alone would trade one blind spot for another.
- **Two perimeters must be kept consistent by hand.** Nothing derives the routing slugs from the registry; the invariant is checked, not maintained, so the actionable refusal message is the whole user experience of that check.
- **The same OSM data is downloaded twice, at two grains** — `france-latest.osm.pbf` (4,4 GB) for routing, the regional extract (~223 MB) for reference. Disk, not complexity; feeding routing with the union of regional extracts would re-couple the grains to save disk, which is the wrong trade.
- **`resolver_version` in the cache means the cache is now load-bearing.** Losing it does not corrupt the live data, but it turns the next opening into a full re-resolution.
- **Wider admin-level import costs real time**: +3,8 % filtered PBF and +54 s of `osm2pgsql` on the measured two-region set (#880). Not trivial, and acquired in exchange for a coverage polygon that exists at all.

### Neutral

- ADR-040's three-tier model is unchanged; this ADR replaces its *import* mechanics (atomic global swap → per-zone transactional promotion) and its cadence, not its architecture.
- The beta perimeter stays bounded; widening it stays data, not code — but it is now data added zone by zone rather than a list re-imported in full.
- ADR-041's R1–R4 (failure isolation, resumable enrichment, timeouts, memory budget) are untouched; only R5 (freshness) is folded into §5.

## Sources

- [ADR-017](adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md) — Valhalla routing engine (dataset amended by #881)
- [ADR-033](adr-033-osm-data-refresh-strategy.md) — OSM data refresh strategy (superseded by ADR-036, cadence reformulated here)
- [ADR-036](adr-036-manual-osm-data-refresh.md) — Manual OSM data refresh (split into two datasets)
- [ADR-040](adr-040-local-first-reference-data-postgis.md) — Local-first reference data, single PostGIS source (import mechanics reformulated)
- [ADR-041](adr-041-provisioner-resilience.md) — Provisioner resilience (R5 freshness reformulated)
- [#878 audit](../audit/878-hebergements-osm-sans-nom.md) — unnamed OSM accommodations per category, which arbitrates the per-category constraint
- [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880) and its audit (`docs/audit/880-libelles-de-localite-hors-ligne.md`, delivered by PR #941) — admin-level import, clipped-extract measurements and offline locality labels
- [valhalla-routing-graph.md](../runbooks/valhalla-routing-graph.md) — routing-graph build procedure

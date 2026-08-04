# ADR-041: Provisioner Resilience — Failure Isolation, Resumable Enrichment, Detailed Logging

- **Status:** Accepted — all five workstreams (R1–R5) have landed: orchestration hardening, the resumable Wikidata cache, network timeouts/retries, the memory budget guard, and reference-data reporting. **R5 was superseded** (2026-08-04, issue #877): the staleness verdict it introduced is gone, replaced by raw age plus per-table completeness metrics — see [R5 (superseded)](#r5--staleness-alerting-superseded-by-completeness-reporting)
- **Date:** 2026-06-17
- **Depends on:** ADR-040 (Local-first reference data — single PostGIS source), ADR-039 (Beta right-sizing)

## Context and Problem Statement

ADR-040 made the provisioner the single aggregator of Tier-1 reference data: it downloads OSM PBFs, imports them with `osm2pgsql`, imports the DataTourisme flux (~1.87 GB ZIP, ~390k objects), and enriches the Wikidata-bearing rows — all behind atomic schema swaps. As more sources landed (cycle routes, ferries, fords, DataTourisme food layer, Wikidata enrichment), a single `bin/provision` invocation now chains many phases in one one-shot container (2 GB limit). Operationally that surfaces gaps:

- **Long daily run.** DataTourisme is meant to refresh daily, but Wikidata enrichment was coupled to it and re-queried every Q-ID on each run, even though Wikidata's effective cadence is monthly.
- **No resume.** A crash (OOM-kill, fatal, exception) in the middle of enrichment discarded all the network work already done; the next run restarted Wikidata from scratch.
- **Unbounded memory.** The enricher accumulated the whole Q-ID → enrichment map in memory before writing it.
- **Silent best-effort.** A throttled/blocked Wikidata produced zero enrichment with no signal.
- **No concurrency guard.** Two overlapping runs (cron + manual) would both write the same staging schema and race destructively.
- **Thin failure diagnostics.** Errors printed only a short message; the failing command and stderr were not consistently captured, and nothing survived the container logs.
- **All-or-nothing exit.** One source failing aborted the others and produced a single opaque failure.

## Decision

Harden the provisioner so third-party and resource failures degrade only the affected source, never corrupt live data, and always leave enough trace to diagnose later. The target spans five workstreams (R1–R5); this ADR records all five, and the first three are implemented here.

### R1 — Resilient orchestration (implemented)

- **Continue-on-error per source.** Each reference source (OSM, DataTourisme) runs as an independent step; one failing does not abort the others. The command aggregates per-source outcomes into the exit code and prints a summary (`✓`/`✗` per source).
- **Concurrency lock.** A non-blocking exclusive `flock` on `/data/provision.lock`, held for the whole run, serialises overlapping invocations. The OS releases it on process death (including a crash), so a killed run never leaves a stale lock. Scope: same `/data` volume / host (the beta deployment); a cross-host DB-level lock is deferred.
- **Detailed, persistent logging.** Failures are written both to the console and appended (timestamped) to `/data/provisioner.log`, so the cause survives lost container logs. Importer process failures now carry the **failing command and full stderr** in the exception message.

### R2 — Resumable, memory-bounded Wikidata enrichment (implemented)

- **Persistent cache.** `provisioner.wikidata_cache(qid, payload jsonb, fetched_at)` lives in a stable `provisioner` schema (NOT `tourism`/`osm`, which are dropped on every swap). Only Q-IDs absent or older than the TTL (30 days) are re-queried; the live tables are enriched by joining the cache, so previously cached Q-IDs enrich the fresh staging tables with **no network call**. This gives resume after a crash and decouples Wikidata's cadence from the daily DataTourisme refresh.
- **Negative caching.** A Q-ID Wikidata returns no data for is cached as `{}` so it is not re-queried for the TTL. A Q-ID whose batch *failed* (transient outage) is left uncached and retried next run — the two are distinguished so an outage is never negatively cached.
- **Streaming.** The enricher yields one batch (50 Q-IDs) at a time straight into a COPY file; the cache, not a PHP map, is the join source. Memory stays bounded regardless of how many Q-IDs are enriched.

### R3 — Network resilience (implemented)

- **Explicit timeouts.** OSM PBF and DataTourisme flux downloads cap both the per-chunk idle wait and the total transfer (`max_duration`), so a stalled mirror fails fast instead of hanging the run.
- **Retry with backoff.** Wikidata SPARQL batches retry on transient failures (HTTP 429/5xx, transport errors) with exponential backoff before being skipped.

### R4 — Memory budget (implemented)

The streaming enricher (R2) removes the largest avoidable PHP allocation. On top of that, the provisioner entrypoint caps PHP at `memory_limit=512M` so a regression in the streaming / enrichment paths fails with a clean PHP fatal instead of a silent container OOM-kill. The heavy import processes run **outside** PHP: `osm2pgsql` is bounded by `--cache 800` (MB) plus its node cache, and `psql` by the DB config — together they fit the provisioner's 2 GB container alongside the bounded PHP process. The container limit stays the backstop for the native tools.

### R5 — Staleness alerting (superseded by completeness reporting)

**Originally decided:** `/api/health` `reference_data` reported, per source, the refresh `age_seconds` and a `stale` flag (refresh older than the source cadence: OSM 8 days, DataTourisme 36 hours), plus an overall `stale` status when any source was overdue, so an uptime probe could alert on data age rather than only on a failed run.

**Superseded (2026-08-04, issue #877).** The thresholds were removed and not replaced. Two reasons:

- **The signal was dead.** No scheduler refreshes these sources since [ADR-036](adr-036-manual-osm-data-refresh.md) removed `osm-cron`; refreshes are irregular manual runs. The flag was therefore permanently lit in any real deployment, and a signal that is always red carries no information.
- **The verdict has nothing to judge.** Under assumed obsolescence, opening a zone is a deliberate act, the data is dated by construction and the user is the one who verifies (which is why lodgings expose a link and a phone number). Age remains useful as an **internal metric**; a threshold on it does not.

What `reference_data` reports instead, still **non-required** (only `down`, i.e. never provisioned, is a state — and it degrades features without flipping readiness): the refresh timestamp, the raw `age_seconds` with no verdict, the per-table `feature_counts`, and the per-table `completeness` ratios (share of rows with a name, with an exploitable link, with opening hours) with a per-category breakdown for accommodations. `rejections` records what an import discarded and why — today the DataTourisme accommodations whose subtype maps to no app category, and the quality gate's counts once it lands.

Without those ratios no data-quality decision was verifiable over time, and the remaining trade-offs were taken on intuition — which is exactly what produced one false inference during the diagnosis ("a 15 km radius with no pharmacy is an index gap" was a guess). Wikidata freshness rides on the OSM/tourism refresh that re-runs the cache-backed enrichment.

## Consequences

- A Wikidata/DataTourisme/mirror outage degrades only the next refresh of that source; the live dataset and the other sources are unaffected.
- The daily DataTourisme refresh no longer pays the full Wikidata cost; enrichment resumes from the cache after any interruption.
- Provisioner memory is bounded: the PHP process is capped at 512 MB, and the native import tools fit the 2 GB container under their own caps.
- Data age and per-table completeness are readable via `/api/health` (`reference_data`), so a data-quality trade-off can be measured instead of guessed. No staleness verdict is derived from the age (see R5).
- Failures leave a persistent, detailed trace (command + stderr) for later diagnosis.
- The cache adds a stable `provisioner` schema, bootstrapped by a migration and self-created by the provisioner if absent.

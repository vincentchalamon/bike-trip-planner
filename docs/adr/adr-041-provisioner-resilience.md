# ADR-041: Provisioner Resilience — Failure Isolation, Resumable Enrichment, Detailed Logging

- **Status:** Accepted — orchestration hardening, the resumable Wikidata cache and network timeouts/retries have landed; staleness alerting and the remaining memory budget work follow
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

### R4 — Memory budget (partially implemented, rest deferred)

The streaming enricher (R2) removes the largest avoidable PHP allocation. Remaining work: document and validate the `osm2pgsql --cache` vs container-limit budget, and set an explicit PHP `memory_limit`.

### R5 — Staleness alerting (deferred)

`/api/health` already exposes `osm.metadata` / `tourism.metadata` `refreshed_at`. Planned: age thresholds per source (and a Wikidata refresh marker) surfaced as a degraded-feature signal, plus an uptime probe and runbook — so a silently-failing cron is detected by data age, not only by a failed run.

## Consequences

- A Wikidata/DataTourisme/mirror outage degrades only the next refresh of that source; the live dataset and the other sources are unaffected.
- The daily DataTourisme refresh no longer pays the full Wikidata cost; enrichment resumes from the cache after any interruption.
- Provisioner memory stays bounded on the enrichment path; the import path budget is the remaining R4 item.
- Failures leave a persistent, detailed trace (command + stderr) for later diagnosis.
- The cache adds a stable `provisioner` schema, bootstrapped by a migration and self-created by the provisioner if absent.

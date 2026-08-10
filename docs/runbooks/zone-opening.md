# Runbook: opening a reference zone

Opening a zone is **the main recurring operational act of this project**: manual,
deliberate, repeated zone by zone. This runbook is the procedure, for production and for
local development, which do not have the same prerequisites.

**This is the reference dataset, not the routing graph.** Two datasets, two grains, two
calendars, never one command (ADR-049 §6):

| | Reference (this runbook) | Routing ([valhalla-routing-graph.md](valhalla-routing-graph.md)) |
|---|---|---|
| Question | what is near this track? | can we go from A to B? |
| Grain | region (`bretagne`) | country (`france`) |
| Store | `osm` / `tourism` PostGIS schemas | `valhalla-tiles` Docker volume |
| Command | `make provision <zone>` | `make routing-build <slug>` |
| Cadence | often, one zone at a time | rarely, per country |

Opening a zone cannot degrade routing, and a failed graph build cannot block an opening.
The one link between them is an invariant, checked in one direction only: **the routing
perimeter must encompass the zone**.

## Symptômes

Reasons to run this:

- A region has no POI, accommodation or ford data, and `/api/health` reports it as not open.
- Users see a route rendered display-only with an "out of zone" indication.
- An opened zone needs refreshing to pick up what OSM has gained since.
- You are setting up a development machine and need something in the index to work against.

## Diagnostic

What is open, and whether the routing graph covers it:

```bash
curl -s https://<host>/api/health | jq '.deps.reference_data.zones'
```

```json
{
  "open": [{ "slug": "bretagne", "country": "france", "routable": true, "…": "…" }],
  "routing_perimeter": ["france"],
  "routing_containment": { "status": "ok", "uncovered": [] }
}
```

- `open: []` — nothing is open. Every trip is "coverage unknown", never "out of zone".
- `routing_containment.status: "violated"` — a zone is open that the graph does not cover.
  Trips there are displayable but not routable. Build the missing country
  (`make routing-build <country>`); nothing derives one perimeter from the other, so this is
  checked, never maintained.

## Procédure — production

### 1. Make sure the routing graph covers the zone

The opening is **refused** if it does not, before anything is downloaded, with the command
to run. `bretagne` needs `france`; Belgium, the Netherlands and Luxembourg are their own
country.

```bash
make routing-build france   # hours, ~8 GB RAM, ~30 GB disk. See the routing runbook.
```

### 2. Open the zone

```bash
make provision bretagne     # slug or display name
```

One zone per run, and the argument is mandatory — there is no cumulative selection and no
selection file. What happens, in order: the DataTourisme flux is staged, the zone's Geofabrik
extract is downloaded and imported into a staging schema of its own, names are resolved, the
completeness gate refuses what it cannot complete, the OpenAgenda events export is imported
(clipped to the zone), and the survivors of each source are promoted into the live tables in a
single transaction.

**Events come from two sources (ADR-051).** DataTourisme and OpenAgenda both feed
`tourism.events`, each stamping its own `source` column; the runtime deduplicates the overlap
at read time (`EventSourceRegistry`). Both are optional and skipped gracefully when their
credentials are absent — OSM always provisions. The environment they read (mirrors the
DataTourisme pattern, all set on the `provisioner` service in `compose.yaml`):

| Source | Env | Notes |
|---|---|---|
| DataTourisme | `DATATOURISME_FLUX_ID`, `DATATOURISME_APP_KEY` | national flux ZIP; feeds POIs, accommodations and events |
| OpenAgenda | `OPENAGENDA_DATASET` (+ optional `OPENAGENDA_API_KEY`) | Opendatasoft dataset slug of the national JSONL export (e.g. `evenements-publics-openagenda`); events only |

OpenAgenda runs **after** the OSM step (it clips its national export to the zone geometry the
OSM import produces) and **before** the DataTourisme promotion, so the DataTourisme metadata
refresh counts its events in the live totals.

**Opening a second zone keeps the first.** Promotion is an `INSERT ... SELECT` restricted to
keys the live tables do not already hold — never a schema swap — so no other zone is exposed
to the run. Order does not matter; open them in whatever order users need.

Until a zone is opened, a route crossing it is **out of zone**: the frontend renders it
display-only rather than re-routing it, because there is no reference data to enrich it with.
That is a true statement now — coverage is the union of the opened zones, read from the
registry.

### 3. Read the opening report

The console prints what the gate did:

```text
Completeness gate
-----------------
  118 name(s) resolved, of which 37 from the curated flux.
  46 entry(ies) refused, 3 of them as ambiguous matches.
    - no_usable_name_source: 43
    - ambiguous_match: 3
  Ranked by distance to the nearest cycle route in /data/zones/bretagne/rejected.tsv.
```

**A long refusal list is a signal about the code, not a backlog of work.** The resolvers
should absorb most of the volume; when refusals outnumber resolutions the command says so
explicitly. Before spending hours on manual corrections, check whether the fix belongs in
`provisioner/src/NameResolver.php` — a resolver improvement is retroactive across every zone
and survives a rebuild, a hand correction is neither.

### 4. Correct what is worth correcting

`rejected.tsv` is sorted by distance to the nearest signed cycle route, nearest first, so the
useful entries are at the top and you can stop whenever the distances stop being interesting.

```bash
cd .docker/osm/data/zones/bretagne
cp rejected.tsv override.tsv     # keep the useful rows, fill in `name`
make provision-override bretagne
```

**Keep `override.tsv`.** Nothing in the system stores it: the corrections live only in the
live tables, so a database rebuilt from scratch loses every one whose file you did not keep.
That is the accepted price of "no correction table, no versioning" — and the reason a
resolver fix beats a hand correction whenever the pattern generalises.

The full loop, column by column, is in
[zone-opening-corrections.md](zone-opening-corrections.md).

### 5. Re-open a zone to refresh it

Same command:

```bash
make provision bretagne
```

Cheap, and worth understanding why:

- **Re-analysed:** nothing that this resolver version already decided. Entries it refused are
  retried only after `NameResolver::VERSION` is bumped, which is what makes a resolver
  improvement reach zones already open.
- **Not re-analysed, not re-read, not rewritten:** every row already present. The anti-join is
  on identity alone, so re-opening an unchanged zone inserts **0 rows** and the report says
  "0 new entries". That is the proof the gate works, not a sign the run did nothing.
- **Completed, never replaced:** a row present with a NULL field can be filled by `COALESCE`.
  An existing value is never overwritten. The single exception is `last_seen_at`, which is
  metadata.

### 6. Idempotence, as it actually is

The idempotence is that of the **PostGIS insertion step**, not of the whole run. Re-running
`make provision <zone>` inserts 0 rows on the second pass only if all three hold:

- the Geofabrik extract is still in the local cache (`.docker/osm/data/regions/`), otherwise
  it is re-downloaded — hundreds of MB;
- the 30-day TTL of the Wikidata cache has not expired, otherwise the enrichment queries
  Wikidata again for the expired Q-IDs;
- the DataTourisme flux is unchanged — and it is a **national ZIP re-downloaded in full on
  every run**, so this one is never free;
- likewise the OpenAgenda export is a **national JSONL re-downloaded in full on every run**,
  and events are perishable, so a re-open is how upcoming events reach the index (the weekly
  refresh + purge is #985).

Do not describe a re-opening as a no-op. It re-downloads, re-filters and re-imports into
staging; what it does not do is write to the live tables.

### 7. What is not guaranteed

Say these out loud rather than discovering them:

- **No staleness threshold.** `/api/health` reports the index's age; it carries **no verdict**
  and must not grow one. Nothing refreshes these sources on a schedule, so any threshold
  would be permanently red.
- **Obsolescence is assumed.** A closed campsite stays listed until someone notices. Total
  reliability of a listing is not reachable, and the person who books is the person who
  verifies — which is why the verification link (website, phone) is a tool, not decoration.
- **A row removed from OSM stays in the database.** That is the direct consequence of "never
  overwritten, never rewritten": a clipped extract cannot distinguish "deleted upstream" from
  "outside this extract", so nothing tries.

## Procédure — local development

### 1. Do not open `france`

It is not even offered: whole-country entries left the reference registry (ADR-049 §1). Pick
the smallest zone that has what you need. Measured reference point: the merged
nord-pas-de-calais + rhone-alpes set (767 MB of extracts) filters to 191.6 MB and takes about
160 s of `osm2pgsql` (#880). A single small zone is a fraction of that.

| Working on | Suggested zone | Why |
|---|---|---|
| Accommodations, POI, the pricing heuristic | `corse` (32 MB) | small, and dense enough in campsites and gîtes |
| Cycle-route indicators, ferries, fords | `bretagne` (307 MB) | signed véloroutes, real ferry crossings, coastline |
| Locality labels, admin boundaries | any region | communes come with every extract |
| The provisioner itself | `mayotte` (10 MB) | fastest full round-trip through the whole pipeline |

### 2. Skip the routing graph

A dev machine has an **empty** routing volume, which the containment check reads as an
observed empty perimeter and refuses. Building the national graph to work on accommodations
is hours and ~30 GB, so there is an explicit way out:

```bash
make provision corse -- --allow-unrouted-zone
```

The `--` separator is required: a bare `make provision corse --allow-unrouted-zone` lets
`make` claim the flag as one of its own options and fails with
`l'option « --allow-unrouted-zone » n'a pas été reconnue`. With `--`, `make` stops parsing
options and the target forwards the flag to the container. Calling the container directly
(`docker compose --profile provisioning run --rm provisioner corse --allow-unrouted-zone`)
works too.

Trips in that zone will not be routable — everything else works. **Do not** improvise the
alternative of dropping a placeholder extract into the routing volume:
`build-routing-graph.sh` skips downloading an extract that is already present, so the next
real build would silently build from your placeholder.

### 3. Provision from the main checkout, not from a worktree

`docker compose` derives its project name from the directory, so a worktree gets **its own**
containers, its own database and its own volumes. Provisioning from a worktree fills a
database no other stack reads, and the `pwa_node_modules` volume is empty there too.

Open zones from the main checkout. If you need a worktree's provisioner *code* against the
main stack's database, run the container by hand against that network rather than through the
worktree's compose project — the same pattern the PHPUnit recipes in
[CLAUDE.md](../../CLAUDE.md) use.

### 4. Start over

Nothing here is precious except your `override.tsv` files:

```bash
# Drop the reference schemas; the next opening recreates them from the migrations.
docker compose exec database psql -U app -d bike_trip_planner \
  -c 'TRUNCATE osm.zones, osm.routing_perimeter; DROP SCHEMA IF EXISTS provisioner CASCADE;'
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

# Optional, and expensive to undo: drop the cached extracts too (re-downloads on next run).
rm -rf .docker/osm/data/regions/*
```

What it costs: dropping the `provisioner` schema discards the Wikidata **and** name-resolution
caches, so the next opening re-queries Wikidata for every Q-ID and re-runs the resolver over
every anonymous row. Keeping the extracts saves the download; keeping the caches saves the
network.

## Post-action

- `curl -s <host>/api/health | jq '.deps.reference_data.zones'` — the zone appears in `open`
  with `routable: true`, and `routing_containment.status` is `ok`.
- `jq '.deps.reference_data.osm.feature_counts'` — the counts grew.
- `jq '.deps.reference_data.osm.rejections'` — what the gate refused, and how much of it the
  curated flux named.
- Plan a trip crossing the new zone: accommodations and POI appear, and it is no longer
  display-only.
- If refusals outnumbered resolutions, open an issue against the resolvers rather than a
  correction task against the data.

## References

- [ADR-049](../adr/adr-049-zone-opening-and-import-time-completeness.md) — zone opening,
  transactional promotion, import-time completeness, append-only storage
- [ADR-040](../adr/adr-040-local-first-reference-data-postgis.md) — the local-first reference
  index this fills
- [ADR-041](../adr/adr-041-provisioner-resilience.md) — failure isolation between sources
- [zone-opening-corrections.md](zone-opening-corrections.md) — the report and the manual
  correction loop
- [valhalla-routing-graph.md](valhalla-routing-graph.md) — the other dataset

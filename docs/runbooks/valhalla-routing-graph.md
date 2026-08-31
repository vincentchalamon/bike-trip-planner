# Valhalla Routing Graph — Build, Upload, Serve

**Scope: the routing graph only.** This runbook answers "can we go from A to
B?". It never touches the reference data ("what is near this track?": POI,
accommodations, water, admin, cycle networks in the `osm` / `tourism` PostGIS
schemas), which is provisioned separately by `make provision`.

Since #881 the two datasets share nothing — no file, no directory, no schedule:

| | Reference (PostGIS) | Routing (Valhalla) |
|---|---|---|
| Question | what is near this track? | can we go from A to B? |
| Grain | region (`nord-pas-de-calais`) | country (`france`) |
| Store | `.docker/osm/data/regions/` + Postgres | `valhalla-tiles` Docker volume |
| Perimeter | the `osm.zones` registry, one row per opened zone | the extracts present in the `valhalla-tiles` volume, i.e. every slug ever passed to `make routing-build` |
| Cadence | often, one zone at a time | rarely, per country opening (target: monthly refresh) |
| Command | `make provision <zone>` | `make routing-build <slug>` |
| Runbook | [zone-opening.md](zone-opening.md) | this file |

Opening a reference zone therefore **cannot** degrade routing, and a long or
failed graph build **cannot** make routing unavailable. Before #881 both read
`.docker/osm/data/default.osm.pbf`; that file no longer exists.

The whole-France Geofabrik extract (~4.4 GB) produces a graph whose **build** is
expensive: several hours on 4 ARM cores with a multi-GB RAM peak — well over the
6 h CI runner cap. It runs **locally, on demand**, and the result is uploaded to
production. At serving time Valhalla mmaps the tiles without rebuilding.

`osm-cron` (the former nightly rebuild scheduler) was removed in ADR-036; this
runbook is its replacement. It was previously named `osm-france-refresh.md`, a
name that suggested it covered OSM data in general.

## Symptômes

- Scheduled monthly routing refresh is due (data drifts over weeks, not hours).
- New cycleways / road closures missing from routes for several weeks.
- A country is being added to the routing perimeter.
- A fresh production VM needs its `valhalla-tiles` volume seeded (see also
  [oracle-vm-reclaimed.md](oracle-vm-reclaimed.md)).
- `valhalla` exits at boot with `No local PBF files, valhalla_tiles.tar and no
  tile URLs found. Nothing to do.` — the volume holds no graph, and the serving
  container is not allowed to build one. Run step 1.

This is **planned maintenance**, not an incident. For corrupted tiles or a hot
rebuild, see [valhalla-overpass-rebuild.md](valhalla-overpass-rebuild.md). For
empty POI / accommodation results this is the wrong runbook: that is reference
data, re-run `make provision`.

## Diagnostic

Confirm what is currently served:

```bash
# On the production host (SSH with the Ansible-provisioned deploy key), in the
# shared Valhalla project (deploy/valhalla, ADR-061):
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml exec valhalla curl -sS http://localhost:8002/status | jq
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml exec valhalla du -sh /custom_files
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml exec valhalla ls -lh /custom_files
```

A healthy France graph shows `valhalla_tiles.tar` plus `valhalla_tiles/` and
`elevation_data/` under `/custom_files`, and a `du -sh` in the 15-25 GB range.
Locally, one `<country>-latest.osm.pbf` per country of the perimeter sits
alongside them; production only needs the tar.

## Procédure

Build steps run on a local workstation (>= 8 GB free RAM, ~30 GB disk).
Production steps are marked **(prod)** and run on the prod host over SSH (the
Ansible-provisioned deploy key, ADR-061). Steps 3-6 are mechanized end to end
by `make routing-publish <user@host> <slug> [slug...]` (`Makefile`).

### 1. Build the graph locally

```bash
make routing-build france
```

This starts the one-shot `valhalla-builder` (profile `routing-build`), which:

1. downloads `europe/france-latest.osm.pbf` into the `valhalla-tiles` volume if
   it is not already there — delete it first to force a fresh download for a
   monthly refresh;
2. rebuilds tiles, elevation, admin and timezone databases from **every**
   extract present in the volume;
3. packs `valhalla_tiles.tar` and exits.

Adding a country means naming the whole perimeter, because the gis-ops image
cannot extend an existing graph:

```bash
make routing-build france belgium
```

There is **no list of the routing perimeter anywhere in the repository**, and that
is deliberate: a list in `compose.yaml` or in PHP could not be checked against
what the volume actually holds, so a forgotten country would read as covered
while the graph did not include it. The perimeter is whatever extracts sit in the
volume — ask it directly:

```bash
docker compose --profile routing-build run --rm --no-deps --entrypoint ls valhalla-builder -lh /custom_files
```

Record any perimeter change in `TRACKING.md` per project conventions.

The builder has **no memory limit** on purpose: `valhalla_build_tiles` holds the
graph of the whole perimeter in RAM, so any of the per-service caps used
elsewhere in `compose.yaml` would OOM-kill it mid-build. It runs alone and exits.

Follow it, then check the local result:

```bash
docker compose --profile routing-build logs -f valhalla-builder
docker run --rm -v "$(docker volume ls -q | grep valhalla-tiles)":/cf alpine ls -lh /cf
```

### 2. Serve it locally (optional)

```bash
make routing-up
docker compose exec valhalla curl -sf http://localhost:8002/status | jq
```

`make start-dev` does **not** start `valhalla`: routing is opt-in, because a
machine without a graph has nothing to serve. Use `make routing-up`, or export
`COMPOSE_PROFILES=routing` to bring it up with the rest of the stack.

### 3. Package the tiles

```bash
VOL=$(docker volume ls -q | grep valhalla-tiles)   # e.g. <project>_valhalla-tiles
docker run --rm -v "$VOL":/src -v "$PWD":/out alpine \
  tar czf /out/valhalla-france-$(date +%Y%m).tar.gz -C /src valhalla_tiles.tar
ls -lh valhalla-france-*.tar.gz
```

Packaging only `valhalla_tiles.tar` (not the unpacked `valhalla_tiles/` dir, nor
the multi-GB `*.osm.pbf` extracts) keeps the artifact small; the gis-ops
entrypoint re-extracts it on boot.

### 4. Upload to the production VM

Pick whichever transport is available. Direct rsync to the host:

```bash
rsync -avP --partial valhalla-france-YYYYMM.tar.gz \
  user@prod-host:/tmp/valhalla-france.tar.gz
```

Or stage in object storage (OCI / Backblaze B2) and pull on the host:

```bash
# local
rclone copy valhalla-france-YYYYMM.tar.gz remote:bike-trip-planner/valhalla/
# (prod)
rclone copy remote:bike-trip-planner/valhalla/valhalla-france-YYYYMM.tar.gz /tmp/
mv /tmp/valhalla-france-YYYYMM.tar.gz /tmp/valhalla-france.tar.gz
```

### 5. Repopulate the `valhalla-tiles` volume **(prod)**

```bash
# Stop the shared service so nothing reads the volume mid-write:
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml stop valhalla

VOL=$(docker volume ls -q | grep valhalla-tiles)   # valhalla-shared_valhalla-tiles
# Wipe the old tiles, then unpack the new tar into the volume root:
docker run --rm -v "$VOL":/dst alpine \
  sh -c 'rm -rf /dst/valhalla_tiles /dst/valhalla_tiles.tar /dst/tiles'
docker run --rm -v "$VOL":/dst -v /tmp:/in alpine \
  tar xzf /in/valhalla-france.tar.gz -C /dst
docker run --rm -v "$VOL":/dst alpine ls -lh /dst   # expect valhalla_tiles.tar
```

Production only ever serves: it needs the tar, not the extracts. Do not run
`make routing-build` on the production host.

### 6. Restart Valhalla **(prod)**

Restart the shared Valhalla service on the host:

```bash
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml restart valhalla
```

### 7. Wait for the healthcheck **(prod)**

Serving pre-built tiles is an mmap, so the `start_period` is 20s — if `valhalla`
is not healthy within a minute, read the logs rather than waiting it out:

```bash
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml ps valhalla            # STATUS should reach "healthy"
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml logs --tail=50 valhalla
docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml exec valhalla curl -sS http://localhost:8002/status | jq
```

### 8. Smoke-test `/route` **(prod)**

Lille -> Cassel (Hauts-de-France, ~40 km) must route on the France graph:

```bash
docker compose exec php curl -sS -X POST http://valhalla:8002/route \
  -H 'Content-Type: application/json' \
  -d '{"locations":[{"lat":50.6292,"lon":3.0573},{"lat":50.8000,"lon":2.4869}],"costing":"bicycle"}' \
  | jq '.trip.summary'
```

Expect a non-error response with a `length` of roughly 40-60 km. Confirm the
application health endpoint too:

```bash
docker compose exec php curl -sS http://localhost/api/health | jq '.valhalla'
```

## Memory budget

Requalified in #881, when build and serve stopped sharing a container:

- **`valhalla` (serve): 2 GB.** A page-cache budget, not an allocation:
  `valhalla_service` mmaps `valhalla_tiles.tar`, so the limit decides how much of
  the extract stays cached. Anonymous memory is the config plus per-request A*
  search state (tens to a few hundred MB for a long bicycle route). Sizing rule
  when the perimeter grows: `valhalla_tiles.tar` + 512 MB, measured with
  `docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml exec valhalla ls -lh /custom_files/valhalla_tiles.tar`. Setting
  it too low degrades latency through constant page-cache eviction; it does not
  OOM.
- **`valhalla-builder`: uncapped, deliberately.** Its peak scales with the
  extract's node count and exceeds any steady-state limit in `compose.yaml`. It
  runs alone, on demand, then exits.

## Post-action

- `docker compose -p valhalla-shared -f deploy/valhalla/compose.yaml ps valhalla`
  reports `healthy`; `/api/health` shows `valhalla: ok`.
- The Lille -> Cassel `/route` smoke test returns a valid trip.
- Trigger one real trip computation through the app to confirm route + stage
  generation succeed end to end.
- Note the build runtime and the artifact name/date in `TRACKING.md` per project
  conventions; delete the staged `/tmp/valhalla-france.tar.gz` on the host.

## References

- ADR-036 — Manual OSM Data Refresh (supersedes the nightly `osm-cron`); amended
  by #881, which splits the routing and reference calendars
- ADR-017 — Valhalla routing engine (its build/serve coupling is removed by #881)
- ADR-040 — Tier-1 PostGIS reference index (the *other* dataset)
- ADR-061 — Deployment: Ansible + GHA-SSH + Traefik + Tunnel; the shared
  `valhalla-shared` compose project is deployed standalone by Ansible
- [valhalla-overpass-rebuild.md](valhalla-overpass-rebuild.md) — corrupted-tile /
  hot rebuild
- `Makefile` targets `routing-build`, `routing-up` (local build/serve) and
  `routing-publish` (ships the built tar to `valhalla-shared` on the shared-infra
  host, steps 3-6 of this runbook); `provision` is the unrelated reference target
- `compose.yaml` — local `valhalla` (serve-only) and `valhalla-builder`
  (one-shot) services, `valhalla-tiles` volume, used for `routing-build`/
  `routing-up`
- `deploy/valhalla/compose.yaml` — the standalone `valhalla-shared` project
  actually served in production (Ansible-deployed, ADR-061)
- `.docker/valhalla/build-routing-graph.sh` — what the builder actually runs

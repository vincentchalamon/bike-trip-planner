# OSM France Refresh — Monthly Local Build + Upload

Production routing targets **France entière** (ADR-036, manual OSM refresh). The
whole-France Geofabrik extract (~4.4 GB) produces a Valhalla tile set whose
**build** is expensive: several hours on 4 ARM cores with a 4-8 GB RAM peak —
well over the 6 h CI runner cap. `build_elevation` is kept.

The build therefore runs **locally and on demand** (target cadence: monthly).
The resulting tiles are packaged and uploaded to the production VM, the
`valhalla-tiles` volume is repopulated, and Valhalla is restarted. At serving
time Valhalla mmaps the tiles (~1.5-2.5 GB resident, ~15-25 GB disk) without
rebuilding.

`osm-cron` (the former nightly rebuild scheduler) was removed in ADR-036; this
runbook is its replacement.

## Symptômes

- Scheduled monthly OSM refresh is due (data drifts over weeks, not hours).
- New cycleways / road closures missing from routes for several weeks.
- A fresh production VM needs its `valhalla-tiles` volume seeded (see also
  [oracle-vm-reclaimed.md](oracle-vm-reclaimed.md)).

This is **planned maintenance**, not an incident. For corrupted tiles or a hot
rebuild, see [valhalla-overpass-rebuild.md](valhalla-overpass-rebuild.md).

## Diagnostic

Confirm what production is currently serving:

```bash
# On the production host (Coolify SSH), in the application directory:
docker compose exec valhalla curl -sS http://localhost:8002/status | jq
docker compose exec valhalla du -sh /custom_files
docker compose exec valhalla ls -lh /custom_files
```

A healthy France tile set shows `valhalla_tiles.tar` plus `tiles/` under
`/custom_files` and a `du -sh` in the 15-25 GB range.

## Procédure

All build steps run on a local workstation (>= 8 GB RAM free, ~30 GB disk).
Production steps are marked **(prod)** and run on the Coolify host via SSH.

### 1. Build the France tiles locally

```bash
# Configure the provisioner to target France entière (first run is interactive):
make provision
#   -> at the "Which region do you want to add?" prompt, choose:
#        France (entiere) (4400 MB)
#   -> confirm; the whole-France PBF is downloaded to
#      .docker/osm/data/regions/ and merged into
#      .docker/osm/data/default.osm.pbf

# Subsequent monthly refreshes are non-interactive (re-downloads the saved
# selection from .docker/osm/data/regions.json):
make provision-update
```

Build the Valhalla tiles from that PBF by starting the local `routing` profile
once. `build_elevation` is on by default in `compose.yaml`:

```bash
docker compose --profile routing up -d valhalla
# Watch the build (hours for France entière); wait until /status answers:
docker compose logs -f valhalla
docker compose exec valhalla curl -sf http://localhost:8002/status >/dev/null \
  && echo "tiles ready"
```

The gis-ops image writes the built graph to `/custom_files/` and packs it into
`/custom_files/valhalla_tiles.tar`. With `serve_tiles: "True"` a boot that finds
that tar serves it directly, without rebuilding.

### 2. Package the tiles

```bash
VOL=$(docker volume ls -q | grep valhalla-tiles)   # e.g. <project>_valhalla-tiles
docker run --rm -v "$VOL":/src -v "$PWD":/out alpine \
  tar czf /out/valhalla-france-$(date +%Y%m).tar.gz -C /src valhalla_tiles.tar
ls -lh valhalla-france-*.tar.gz
```

Packaging only `valhalla_tiles.tar` (not the unpacked `tiles/` dir) keeps the
artifact small; the gis-ops entrypoint re-extracts it on boot.

### 3. Upload to the production VM

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

### 4. Repopulate the `valhalla-tiles` volume **(prod)**

```bash
# Stop the service so nothing reads the volume mid-write:
docker compose stop valhalla

VOL=$(docker volume ls -q | grep valhalla-tiles)
# Wipe the old tiles, then unpack the new tar into the volume root:
docker run --rm -v "$VOL":/dst alpine \
  sh -c 'rm -rf /dst/valhalla_tiles /dst/valhalla_tiles.tar /dst/tiles'
docker run --rm -v "$VOL":/dst -v /tmp:/in alpine \
  tar xzf /in/valhalla-france.tar.gz -C /dst
docker run --rm -v "$VOL":/dst alpine ls -lh /dst   # expect valhalla_tiles.tar
```

### 5. Restart Valhalla **(prod)**

Coolify: redeploy the `valhalla` service from the dashboard, or on the host:

```bash
docker compose restart valhalla
```

### 6. Wait for the healthcheck **(prod)**

Serving pre-built tiles is fast (mmap, no rebuild). The `start_period` in
`compose.yaml` is sized for the worst case (cold build), so do not wait it out —
poll `/status`:

```bash
docker compose ps valhalla            # STATUS should reach "healthy"
docker compose exec valhalla curl -sS http://localhost:8002/status | jq
```

### 7. Smoke-test `/route` **(prod)**

Lille -> Cassel (Hauts-de-France, ~40 km) must route on the France dataset:

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

## Post-action

- `docker compose ps valhalla` reports `healthy`; `/api/health` shows
  `valhalla: ok`.
- The Lille -> Cassel `/route` smoke test returns a valid trip.
- Trigger one real trip computation through the app to confirm route + stage
  generation succeed end to end.
- Note the build runtime and the artifact name/date in `TRACKING.md` per project
  conventions; delete the staged `/tmp/valhalla-france.tar.gz` on the host.

## References

- ADR-036 — Manual OSM Data Refresh (supersedes the nightly `osm-cron`)
- ADR-017 — Valhalla routing engine
- ADR-020 — Dynamic region provisioning (`provision` command)
- [valhalla-overpass-rebuild.md](valhalla-overpass-rebuild.md) — corrupted-tile / hot rebuild
- `Makefile` targets `provision`, `provision-update`, `ensure-default-pbf`
- `compose.yaml` — `valhalla` service, `valhalla-tiles` volume, healthcheck `start_period`

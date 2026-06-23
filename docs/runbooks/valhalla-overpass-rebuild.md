# Valhalla / Overpass Rebuild

Valhalla provides routing (ADR-017); Overpass usage was moved off the self-hosted instance (ADR-025) — but Valhalla tiles still need periodic rebuild when the source PBF changes or when tiles are corrupted. The PBF/tile bootstrap is owned by the `provisioner` profile (ADR-020).

## Symptômes

- `/api/health` reports `valhalla: 503` or routing requests return 5xx
- Valhalla logs: `tile not found`, `unable to load tile`, `corrupted`
- New regions are missing routes after a fresh provision (Lille stub only)
- POI / accommodation / event results are empty because the `osm` / `tourism` PostGIS schemas were never provisioned

## Diagnostic

```bash
docker compose ps valhalla
docker compose logs --tail=200 valhalla
docker compose exec php curl -sS http://valhalla:8002/status | jq
```

Inspect the tiles volume:

```bash
docker volume ls | grep valhalla-tiles
docker compose exec valhalla du -sh /custom_files
docker compose exec valhalla ls /custom_files | head
```

Check the source PBF expected by the provisioner:

```bash
ls -lh .docker/default.osm.pbf
```

## Procédure

1. **Re-run the provisioner** to download / build the requested regions:

   ```bash
   make provision
   ```

   `make provision` first ensures a default PBF exists (Lille stub by default), then runs the provisioner interactively under the `provisioning` profile (`docker compose --profile provisioning run --rm provisioner`). Follow the prompts to select Geofabrik regions; tiles are written to the `valhalla-tiles` volume.

2. **Force a full Valhalla rebuild** when tiles are corrupted (destructive — clears the volume):

   ```bash
   docker compose down valhalla
   docker volume rm <project>_valhalla-tiles
   make provision
   docker compose up -d valhalla
   ```

3. **Re-warm caches** by issuing a known-good routing request:

   ```bash
   docker compose exec php curl -sS -X POST http://valhalla:8002/route \
     -H 'Content-Type: application/json' \
     -d '{"locations":[{"lat":50.63,"lon":3.06},{"lat":50.64,"lon":3.07}],"costing":"bicycle"}'
   ```

4. **Reference data** — POI / accommodation / event data is no longer fetched from Overpass at runtime; it is served from the local `osm` / `tourism` PostGIS schemas populated by the `provisioner` (ADR-040). If those queries return nothing, it is a provisioning gap, not a routing one: re-run the provisioner (`make provision`, which loads OSM + DataTourisme) rather than rebuilding tiles here.

## Post-action

- `/api/health` reports `valhalla: ok`.
- Trigger a trip computation through a freshly provisioned region; verify route + stage generation succeed.
- Capture provisioner runtime in the incident issue (tile build can take 30+ min for a large region — note it as planned maintenance, not incident downtime).
- Document any region additions in `TRACKING.md` per project conventions.

## References

- ADR-017 — Valhalla routing engine and (former) Overpass integration
- ADR-020 — Dynamic Overpass region provisioning
- ADR-025 — Removal of self-hosted Overpass
- `Makefile` target `provision` (ensures the default PBF, then provisions all reference sources)

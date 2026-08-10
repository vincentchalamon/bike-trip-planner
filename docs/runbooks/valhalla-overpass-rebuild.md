# Valhalla / Overpass Rebuild

Valhalla provides routing (ADR-017); Overpass usage was moved off the self-hosted instance (ADR-025) — but Valhalla tiles still need a rebuild when they are corrupted or when the national extracts change.

**The routing graph is not provisioned by `make provision`** (#881). It is built out of band by the one-shot `valhalla-builder` (`make routing-build <slug>`) from national extracts held in the `valhalla-tiles` volume; `make provision` only fills the `osm` / `tourism` PostGIS reference schemas. Full build + upload procedure: [valhalla-routing-graph.md](valhalla-routing-graph.md). This runbook covers the incident path only.

## Symptômes

- `/api/health` reports `valhalla: 503` or routing requests return 5xx
- Valhalla logs: `tile not found`, `unable to load tile`, `corrupted`
- Routes fail outside the built routing perimeter, or `valhalla` exits with `No local PBF files, valhalla_tiles.tar and no tile URLs found`
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

Check the national extracts the graph was built from (they live in the volume, not in the working tree):

```bash
docker compose exec valhalla ls -lh /custom_files
```

## Procédure

1. **Restart the service first.** It only mmaps `valhalla_tiles.tar`, so a bad load is often fixed without a rebuild:

    ```bash
    docker compose restart valhalla
    ```

2. **Rebuild the graph** when the tiles are genuinely corrupted. On a workstation, not in production (hours, uncapped memory):

    ```bash
    docker volume rm <project>_valhalla-tiles   # destructive: drops tiles AND the extracts
    make routing-build france                   # add every country of the perimeter
    make routing-up
    ```

    In production, do not rebuild: re-upload a known-good `valhalla_tiles.tar` per steps 4-6 of [valhalla-routing-graph.md](valhalla-routing-graph.md).

3. **Re-warm caches** by issuing a known-good routing request:

    ```bash
    docker compose exec php curl -sS -X POST http://valhalla:8002/route \
      -H 'Content-Type: application/json' \
      -d '{"locations":[{"lat":50.63,"lon":3.06},{"lat":50.64,"lon":3.07}],"costing":"bicycle"}'
    ```

4. **Reference data** — POI / accommodation / event data is no longer fetched from Overpass at runtime; it is served from the local `osm` / `tourism` PostGIS schemas populated by the `provisioner` (ADR-040). If those queries return nothing, it is a provisioning gap, not a routing one: open or re-open the zone concerned (`make provision <zone>`, which loads OSM + DataTourisme for that one zone) rather than rebuilding tiles here. See [zone-opening.md](zone-opening.md); the two datasets share nothing (ADR-049).

## Post-action

- `/api/health` reports `valhalla: ok`.
- Trigger a trip computation inside the routing perimeter; verify route + stage generation succeed.
- Capture the build runtime in the incident issue (hours for a country-sized extract — note it as planned maintenance, not incident downtime).
- Document any perimeter change in `TRACKING.md` per project conventions. The perimeter is not listed anywhere in the repository on purpose — it is the set of extracts in the `valhalla-tiles` volume, which [valhalla-routing-graph.md](valhalla-routing-graph.md) shows how to read.

## References

- ADR-017 — Valhalla routing engine and (former) Overpass integration
- ADR-020 — Dynamic Overpass region provisioning
- ADR-025 — Removal of self-hosted Overpass
- [valhalla-routing-graph.md](valhalla-routing-graph.md) — build + upload the routing graph
- `Makefile` targets `routing-build` / `routing-up` (routing graph) and `provision` (PostGIS reference sources)

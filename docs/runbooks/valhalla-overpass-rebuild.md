# Valhalla / Overpass Rebuild

Valhalla provides routing (ADR-017); Overpass usage was moved off the self-hosted instance (ADR-025) — but Valhalla tiles still need periodic rebuild when the source PBF changes or when tiles are corrupted. The PBF/tile bootstrap is owned by the `provisioner` profile (ADR-020).

## Symptômes

- `/api/health` reports `valhalla: 503` or routing requests return 5xx
- Valhalla logs: `tile not found`, `unable to load tile`, `corrupted`
- New regions are missing routes after a fresh provision (Lille stub only)
- POI extension queries fail because Overpass returns 504 / 429 from the public endpoint

## Diagnostic

```bash
docker compose --profile routing ps valhalla
docker compose --profile routing logs --tail=200 valhalla
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

1. **Ensure a default PBF exists** (Makefile bootstrap, Lille stub by default):

   ```bash
   make ensure-default-pbf
   ```

2. **Re-run the provisioner** to download / build the requested regions:

   ```bash
   make provision
   ```

   The provisioner runs interactively under the `provisioning` profile (`docker compose --profile provisioning run --rm provisioner`). Follow the prompts to select Geofabrik regions; tiles are written to the `valhalla-tiles` volume.

3. **Force a full Valhalla rebuild** when tiles are corrupted (destructive — clears the volume):

   ```bash
   docker compose --profile routing down valhalla
   docker volume rm <project>_valhalla-tiles
   make provision
   docker compose --profile routing up -d valhalla
   ```

4. **Re-warm caches** by issuing a known-good routing request:

   ```bash
   docker compose exec php curl -sS -X POST http://valhalla:8002/route \
     -H 'Content-Type: application/json' \
     -d '{"locations":[{"lat":50.63,"lon":3.06},{"lat":50.64,"lon":3.07}],"costing":"bicycle"}'
   ```

5. **Overpass** — the project relies on the public Overpass mirrors via scoped HTTP clients (ADR-025). If those are rate-limited, no rebuild is possible; the only mitigations are:
   - Wait out the 429 (typically minutes)
   - Lower request rate temporarily by reducing concurrent trip computations (scale workers down via Coolify)
   - Switch the configured Overpass endpoint to an alternate mirror in the relevant env var

## Post-action

- `/api/health` reports `valhalla: ok`.
- Trigger a trip computation through a freshly provisioned region; verify route + stage generation succeed.
- Capture provisioner runtime in the incident issue (tile build can take 30+ min for a large region — note it as planned maintenance, not incident downtime).
- Document any region additions in `TRACKING.md` per project conventions.

## References

- ADR-017 — Valhalla routing engine and (former) Overpass integration
- ADR-020 — Dynamic Overpass region provisioning
- ADR-025 — Removal of self-hosted Overpass
- `Makefile` targets `ensure-default-pbf`, `provision`

# Valhalla Unavailable

Valhalla answers "can we go from A to B?" (ADR-017). In production it is the shared
`valhalla-shared` compose project (`deploy/valhalla/compose.yaml`, deployed by Ansible), reached
by every stack as `http://valhalla:8002` on the `btp-shared` network. It only **serves** a graph
built out of band; it never builds one. Valhalla is a required dependency: when it is down,
`/api/health` answers 503 and new trips cannot be routed.

This runbook is the incident path. Building and shipping a graph is planned maintenance:
[valhalla-routing-graph.md](valhalla-routing-graph.md). Empty POI / accommodation / event
results are a reference-data gap, not a routing one: see [zone-opening.md](zone-opening.md).

On the VM, define once per session:

```bash
alias vh='docker compose -p valhalla-shared -f /opt/bike-trip-planner/deploy/valhalla/compose.yaml'
```

Locally, use `docker compose` instead (the `valhalla` service, profile `routing`).

## Symptoms

- `/api/health` answers 503 with `deps.valhalla.status = "down"`, or routing requests return 5xx
- Valhalla logs: `tile not found`, `unable to load tile`, `corrupted`
- `valhalla` exits at boot with `No local PBF files, valhalla_tiles.tar and no tile URLs found`:
  the volume holds no graph
- Routes fail outside the built routing perimeter (`deps.reference_data.zones.routing_containment.status = "violated"`)

## Diagnosis

```bash
vh ps valhalla
vh logs --tail=200 valhalla
vh exec valhalla curl -sS http://localhost:8002/status | jq
```

Inspect the tiles volume (`valhalla-shared_valhalla-tiles` in production):

```bash
vh exec valhalla du -sh /custom_files
vh exec valhalla ls -lh /custom_files
```

A served graph shows `valhalla_tiles.tar` under `/custom_files`. Production only needs the tar;
the national extracts it was built from live on the workstation that built it.

## Procedure

1. **Restart the service first.** It only mmaps `valhalla_tiles.tar`, so a bad load is often
    fixed without a rebuild:

    ```bash
    vh restart valhalla
    ```

2. **Replace the graph** when the tiles are genuinely corrupted or missing. Never build on the
    VM (hours, uncapped memory): re-ship a known-good graph from a workstation that holds one:

    ```bash
    make routing-publish deploy@<vm-host> france    # the slugs only name the artifact
    ```

    If the workstation has no graph either, build it first (`make routing-build <slug>...`,
    see [valhalla-routing-graph.md](valhalla-routing-graph.md)).

3. **Check routing** with a known-good request (Lille, inside the France graph), from any app
    container on `btp-shared`:

    ```bash
    btp-compose exec php curl -sS -X POST http://valhalla:8002/route \
      -H 'Content-Type: application/json' \
      -d '{"locations":[{"lat":50.63,"lon":3.06},{"lat":50.64,"lon":3.07}],"costing":"bicycle"}'
    ```

## Verification and follow-up

- `/api/health` reports `deps.valhalla.status = "ok"` and the overall status `ok`.
- Trigger a trip computation inside the routing perimeter; verify route and stage generation
  succeed.
- If a graph was rebuilt, note the build runtime in the incident issue (hours for a country:
  planned maintenance, not incident downtime), and record any perimeter change in
  `TRACKING.md`.

## References

- ADR-017 - Valhalla routing engine
- ADR-049 - zone opening; the routing perimeter must encompass every open zone
- ADR-061 - deployment; the shared `valhalla-shared` project
- [valhalla-routing-graph.md](valhalla-routing-graph.md) - build and ship the routing graph
- `Makefile` targets `routing-build`, `routing-up`, `routing-publish`

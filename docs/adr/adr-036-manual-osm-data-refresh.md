# ADR-036: Manual OSM Data Refresh — Supersedes ADR-033

- **Status:** Accepted — manual refresh stands, and [ADR-049](adr-049-zone-opening-and-import-time-completeness.md) splits it in two: reference data is refreshed **one zone per run** (`make provision <zone>`), the routing graph on its own calendar (`make routing-build <slug>`). The two commands above are no longer one refresh.
- **Date:** 2026-06-01
- **Supersedes:** ADR-033 (OSM Data Refresh Strategy — Nightly Re-Download via `osm-cron`)
- **Depends on:** ADR-017 (Valhalla Routing Engine), ADR-020 (Dynamic Overpass Region Provisioning)

## Context

ADR-033 introduced `osm-cron`: a dedicated Compose service running `supercronic` that re-downloaded the configured Geofabrik regions every night and restarted Valhalla via a mounted Docker socket. In practice this added a privileged, always-on scheduler whose only job was to call the existing `provision` command on a timer.

The provisioner already auto-detects install vs update from `/data/regions.json` (ADR-020 plus the unified `provision` command), so a refresh is a single command. OSM data for a bikepacking planner does not need daily freshness: road closures and new cycleways evolve over weeks, not hours, and a routing graph stale by a few weeks remains acceptable for trip planning.

## Decision

Drop `osm-cron`. OSM data is refreshed **manually**, on whatever cadence the operator chooses:

```bash
make provision <zone>            # reference data: re-open one zone (see the amendment below)
make routing-build france        # routing graph: rebuild the tiles for the perimeter
```

> **Amended by #883.** The reference command above was `make provision-update`, which
> re-downloaded and re-imported the whole cumulative selection held in
> `/data/regions.json`. That target no longer exists: ADR-049 made the zone a mandatory
> argument, so a refresh is `make provision <zone>` — one zone per run, inserting only
> what that zone did not already hold. The decision itself (manual cadence, no scheduler)
> is unchanged.

> **Amended by #881.** The two commands above used to be one refresh, because the
> provisioner produced the PBF that Valhalla built from. They are now two
> datasets on two calendars: reference data is regional and refreshed often,
> the routing graph is national and rebuilt per country opening. Restarting
> `valhalla` no longer rebuilds anything — it only serves. See
> [valhalla-routing-graph.md](../runbooks/valhalla-routing-graph.md).

The `provisioner` service (Compose profile `provisioning`) remains the single mechanism for both opening a zone and refreshing it — since #883 they are the same command, `make provision <zone>`. It loads all reference sources in one shot (OSM/PostGIS + DataTourisme); there are no per-source flags.

## Rationale

- **Removes the Docker socket mount.** ADR-033's central trade-off was mounting `/var/run/docker.sock` into `osm-cron` — "functionally equivalent to root on the host". A manual restart issued by the operator eliminates that standing privilege and attack surface entirely.
- **Simpler topology.** One fewer service, one fewer image (`supercronic` + `docker` CLI), and no cron schedule to keep in source control.
- **Cadence rarely matters.** Bikepacking routing tolerates data that is days-to-weeks old; an unattended nightly job is over-engineering for the freshness actually required.
- **No loss of capability.** The download + atomic merge logic is unchanged; only the trigger moves from a timer to a human command.

## Consequences

### Positive

- No privileged scheduler, no Docker socket exposure on the production host.
- Fewer moving parts: the `osm-cron` service, its `.docker/osm-cron/` image, and the `OSM_CRON_SCHEDULE` env var are all removed.

### Negative

- Refresh is no longer automatic: an operator must remember to run it. Acceptable given the low freshness requirement.

### Neutral

- If unattended refresh becomes desirable again, prefer an out-of-band scheduler (host `systemd` timer, or a Coolify scheduled task invoking the provisioner) over re-introducing a socket-mounted in-stack container.

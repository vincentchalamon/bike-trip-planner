# ADR-036: Manual OSM Data Refresh — Supersedes ADR-033

- **Status:** Accepted
- **Date:** 2026-06-01
- **Supersedes:** ADR-033 (OSM Data Refresh Strategy — Nightly Re-Download via `osm-cron`)
- **Depends on:** ADR-017 (Valhalla Routing Engine), ADR-020 (Dynamic Overpass Region Provisioning)

## Context

ADR-033 introduced `osm-cron`: a dedicated Compose service running `supercronic` that re-downloaded the configured Geofabrik regions every night and restarted Valhalla via a mounted Docker socket. In practice this added a privileged, always-on scheduler whose only job was to call the existing `provision` command on a timer.

The provisioner already auto-detects install vs update from `/data/regions.json` (ADR-020 plus the unified `provision` command), so a refresh is a single command. OSM data for a bikepacking planner does not need daily freshness: road closures and new cycleways evolve over weeks, not hours, and a routing graph stale by a few weeks remains acceptable for trip planning.

## Decision

Drop `osm-cron`. OSM data is refreshed **manually**, on whatever cadence the operator chooses:

```bash
make provision-update            # re-download configured regions (non-interactive)
docker compose restart valhalla  # rebuild routing tiles from the new PBF
```

The `provisioner` service (Compose profile `provisioning`) remains the single mechanism for both first install (`make provision`) and update (`make provision-update`).

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

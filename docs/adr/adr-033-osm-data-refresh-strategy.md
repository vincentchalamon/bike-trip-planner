# ADR-033: OSM Data Refresh Strategy — Nightly Re-Download via `osm-cron`

- **Status:** Superseded by [ADR-036](adr-036-manual-osm-data-refresh.md) (OSM data is now refreshed manually; `osm-cron` removed)
- **Date:** 2026-05-28
- **Depends on:** ADR-017 (Valhalla Routing Engine), ADR-020 (Dynamic Overpass Region Provisioning), ADR-025 (Removal of Self-Hosted Overpass)
- **Related:** Sprint 33, Issues #477 (unified `provision` command), #479 (`osm-cron` Compose service)
- **Numbering note:** Issue #480 referenced this document as `adr-030-osm-data-refresh-strategy.md`, but ADR-030 was already taken by "symfony/ai Adoption" before this work started. Renumbered to 033 (next available after ADR-032) to avoid renumbering an Accepted ADR. Same convention as ADR-031.

## Context

Valhalla builds its routing tiles from a Geofabrik PBF extract at startup; once the tiles are built, the PBF is never refreshed. Bookings, road closures, surface tags, cycle infrastructure, and turn restrictions in OpenStreetMap evolve daily; without a refresh mechanism the routing graph drifts from reality. After a few months a Bike Trip Planner instance starts producing stale itineraries (e.g. routes through roads now closed to bikes, missing new cycleways, outdated `surface=*` tags feeding the Surface alert engine).

We need a refresh mechanism that:

1. Runs unattended on a nightly schedule.
2. Integrates with the existing Docker Compose deployment (self-contained, no host-level coupling beyond running the Docker daemon).
3. Triggers a Valhalla restart so the new PBF is actually loaded.
4. Reuses the existing provisioner pipeline (Geofabrik download, validation, write to the shared `/data` volume) instead of duplicating logic.

## Decision

**Full nightly re-download** of the configured Geofabrik regions via a dedicated Compose service `osm-cron` (see #479) running `supercronic`. The cron job invokes the **single unified `provision` command** (see #477), which detects install vs update from state: presence of `/data/regions.json` plus the `--no-interaction` flag means "update existing regions, re-download all PBFs, write atomically, then restart Valhalla".

The `osm-cron` service mounts the Docker socket (`/var/run/docker.sock`) to issue `docker restart valhalla` once the new PBF is written.

The cron schedule is configurable via `OSM_CRON_SCHEDULE` (default: `0 3 * * *`, i.e. 03:00 UTC daily).

## Alternatives Considered

### `pyosmium-up-to-date` (incremental `.osc.gz` diffs)

Apply daily Geofabrik diffs (`.osc.gz`) on top of the current PBF rather than re-downloading. Smaller bandwidth (a few MB/day vs ~100-500 MB for a full region), faster apply step on the host.

**Rejected because** it requires:

- Per-region state tracking (`<region>.osmium-state.txt`) shared across `osm-cron` runs.
- Error-recovery logic when a diff fails mid-apply (manual rollback, re-baselining from a fresh full extract).
- An extra Python runtime (`pyosmium`) on top of `osmium-tool` in the container.

Reconsider if bandwidth becomes a bottleneck (multi-region deployments, metered hosting).

### Host crontab

Schedule `docker compose run provisioner && docker compose restart valhalla` directly from the host's `crontab`. No socket exposure inside any container, simplest possible model.

**Rejected because** it couples deployment to the host OS (Debian/Ubuntu user crontab vs `systemd` timer vs other), adds a manual post-deploy step easy to forget when migrating to a new host, and breaks the "everything in Compose" principle of the project. It also makes the refresh schedule invisible in source control.

### Shared-volume sentinel file + Valhalla sidecar watcher

`osm-cron` writes a sentinel file (`/data/refresh.requested`); a sidecar attached to the Valhalla container watches the file with `inotifywait` and sends `kill -1` to the Valhalla process to trigger a reload.

**Rejected because** it adds a custom sidecar image and an exotic restart path that nobody on the team has prior operational experience with. Valhalla's reload-on-SIGHUP behavior is also not well-documented; safer to issue a clean container restart.

### `docker restart` via SSH

`osm-cron` runs an SSH client and connects to the host on `localhost:22` to issue `docker restart valhalla`. No Docker socket mounted in the cron container.

**Rejected because** it trades socket exposure for SSH key management: the cron container needs a private key, the host needs the matching `authorized_keys` entry, and SSH credentials are arguably harder to scope than a Docker socket (the socket can at least be proxied; an SSH session has full shell access by default).

### Compose healthcheck restart policy

Valhalla healthcheck script computes a hash of the current PBF and compares it to the last-loaded hash; if they differ, exit non-zero so Docker restarts the container under `restart: on-failure`.

**Rejected because** Docker Engine does not restart on `unhealthy` natively — only Docker Swarm and Kubernetes do. Reproducing the behavior on plain Compose requires the Autoheal sidecar pattern (`willfarrell/autoheal`), which itself needs the Docker socket. Functionally equivalent trade-off, more moving parts.

### Symfony Scheduler in the API container

Run the refresh as a Symfony Scheduler task inside the API container.

**Rejected because** it couples infrastructure-level data refresh to the API runtime (API restarts cancel the schedule, API memory budget grows by the size of `osmium-tool`'s working set), and it still does not solve the Valhalla restart problem (the API container has no business sending control commands to a sibling container).

## Security Risk — Docker Socket Mount

**Mounting `/var/run/docker.sock` into `osm-cron` is functionally equivalent to root on the host.** Any process inside `osm-cron` that can talk to the Docker API can:

- Start a privileged container with `--pid=host --net=host -v /:/host`, effectively escaping into the host filesystem.
- Stop, restart, or recreate any other container on the host (including the API, the database, error tracking).
- Read all image layers and bind-mounted volumes of every container on the host.

This is the central trade-off of the chosen design and must be acknowledged explicitly. A compromise of `osm-cron` (supply-chain attack on `supercronic`, on the base image, or on Geofabrik) is a compromise of the host.

## Chosen Mitigations

- **Non-root user** inside the container where `supercronic` and the `docker` CLI permit (`supercronic` itself does not require root; the Docker socket only requires group membership matching the socket's GID on the host).
- **Compose profile gating:** `osm-cron` is in the `routing` profile only. It runs only when Valhalla runs; it is not pulled or started in dev or in profiles that do not need routing.
- **No network ingress:** no `ports:` mapping, no inbound Traefik route, no exposed port. The container only makes outbound HTTPS to Geofabrik and local Unix-socket calls to the Docker daemon.
- **Pinned image tags:** the base image and the `supercronic` binary are pinned by digest or by major+minor tag (no `:latest`). Upgrade process documented in the README.
- **Documentation in `README.md`:** hosts running `osm-cron` must already be treated as production-grade infrastructure where Docker daemon access is implicitly trusted (i.e. single-tenant production VMs, not multi-tenant or developer workstations). The README states "do not enable this service on hosts you do not fully control" explicitly.
- **Follow-up upgrade path:** if the deployment grows (multiple environments, larger ops team, compliance requirements), re-evaluate by interposing a socket proxy such as `tecnativa/docker-socket-proxy` configured to allow only `POST /containers/{id}/restart` and to deny everything else. This narrows the API surface from "Docker root" to "restart this one container".

## Trade-Offs

The chosen design — privileged scheduler with Docker socket — is the **standard pattern for Docker-native schedulers**: Ofelia, Watchtower, ouroboros, and `docker-gen` all mount the socket. Accepted because:

1. The host already runs the Docker daemon as root; any container the deployment trusts to run a build or a migration is already inside the trust boundary.
2. The threat model places the trust boundary at the host, not between containers on the same host. Production hosts run only Bike Trip Planner; there is no inter-tenant isolation requirement.
3. Every rejected alternative either shifts the risk elsewhere (SSH keys, host crontab) or adds complexity for the same residual risk (Autoheal, sidecar watcher).
4. The follow-up path to `docker-socket-proxy` is well-known and can be applied later without redesigning the refresh strategy itself.

## Consequences

### Positive

- Self-contained: a fresh `docker compose --profile routing up -d` brings up Valhalla and its refresh cron in one step.
- Schedule is in source control (`OSM_CRON_SCHEDULE` env var, default in `compose.prod.yaml`).
- Reuses the unified `provision` command — no duplicate Geofabrik download logic.
- Failures surface in the standard Docker log stream and (via ADR-031) in GlitchTip.

### Negative

- Docker socket exposure on the host running `osm-cron`. Mitigated as documented above; documented explicitly so operators can opt out by setting `OSM_CRON_SCHEDULE=` (empty) or by not enabling the `routing` profile on hosts where this is unacceptable.
- Full re-download means nightly bandwidth scales linearly with region size and region count. Move to incremental diffs (alternative 1) only if this becomes painful.
- A Valhalla restart causes a brief routing outage (tile rebuild time, typically 30-90 s for a single country). Acceptable at 03:00 UTC; the API gracefully degrades (routing requests fail fast and the worker retries via Symfony Messenger).

### Neutral

- Operators on multi-tenant hosts (rare for this project's audience) must either disable the service or front it with `docker-socket-proxy` before deploying.

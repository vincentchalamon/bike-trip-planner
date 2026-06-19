# Runbooks

Operational playbooks for on-call response. Each runbook targets a "fix in under 2 minutes" path for a known incident class.

## Structure

Every runbook follows the same four sections:

- **Symptômes** — observable signals that should trigger this runbook
- **Diagnostic** — commands to confirm the issue and scope it
- **Procédure** — numbered, idempotent steps to restore service
- **Post-action** — checks, monitoring, post-mortem trigger

## Index

| Runbook | Purpose |
|---|---|
| [severity-levels.md](severity-levels.md) | P1/P2/P3 definitions (used by `incident-create.yml`) |
| [worker-stuck.md](worker-stuck.md) | Messenger workers blocked or failing |
| [database-disk-full.md](database-disk-full.md) | PostgreSQL disk pressure |
| [redis-out-of-memory.md](redis-out-of-memory.md) | Redis `OOM` evictions or refused writes |
| [mercure-disconnected.md](mercure-disconnected.md) | SSE clients cannot reconnect |
| [valhalla-overpass-rebuild.md](valhalla-overpass-rebuild.md) | Routing tiles or POI cache rebuild |
| [osm-france-refresh.md](osm-france-refresh.md) | Monthly France-wide OSM build + tile upload |
| [oracle-vm-reclaimed.md](oracle-vm-reclaimed.md) | Oracle Always Free instance reclaimed |
| [incident-template.md](incident-template.md) | Post-mortem template |
| [release-rollback.md](release-rollback.md) | Roll back a bad deploy via Coolify |
| [release-checklist.md](release-checklist.md) | Pre-release checklist |
| [uptime-monitoring.md](uptime-monitoring.md) | Uptime Kuma + UptimeRobot configuration |
| [secrets-inventory.md](secrets-inventory.md) | Source of truth for every production secret |
| [secrets-rotation.md](secrets-rotation.md) | Rotation policy + per-class procedures |

## Conventions

- All commands assume the working directory is the repository root.
- `make php-shell` / `make pwa-shell` open a bash session inside the relevant container; `bin/console` is always called from there or via `docker compose exec php`.
- Production commands run on the Coolify host via SSH; the compose project is named after the Coolify application.
- Times are UTC unless stated otherwise.

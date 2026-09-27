# Runbooks

Task-oriented guides for operating Bike Trip Planner: responding to an incident, running a
planned operation, cutting a release, building the mobile app. Each runbook answers one
question and gives the commands to run.

## Structure

Incident runbooks follow the same four sections:

- **Symptoms** - observable signals that should trigger the runbook
- **Diagnosis** - commands to confirm the issue and scope it
- **Procedure** - numbered steps to restore service, safe to re-run
- **Verification and follow-up** - how to confirm the fix, and what to file afterwards

Planned-operation runbooks replace **Symptoms** with **When to use**.

## Index

### Incident response

| Runbook | Purpose |
|---|---|
| [severity-levels.md](severity-levels.md) | P1/P2/P3 definitions and how each alert source maps to them |
| [incident-alerting.md](incident-alerting.md) | Monitoring alerts turned into deduplicated GitHub issues (`incident-create.yml`) |
| [uptime-monitoring.md](uptime-monitoring.md) | UptimeRobot probe on `/api/healthz` (Uptime Kuma is not deployed in beta) |
| [incident-template.md](incident-template.md) | Post-mortem template |
| [worker-stuck.md](worker-stuck.md) | Messenger workers blocked, crashed or failing |
| [database-disk-full.md](database-disk-full.md) | PostgreSQL (PG-app) disk pressure |
| [redis-out-of-memory.md](redis-out-of-memory.md) | Redis `OOM` errors or evictions |
| [mercure-disconnected.md](mercure-disconnected.md) | SSE clients get no live updates |
| [valhalla-unavailable.md](valhalla-unavailable.md) | Routing down or tiles corrupted |
| [oracle-vm-reclaimed.md](oracle-vm-reclaimed.md) | Oracle Always Free instance stopped, reclaimed or lost |

### Data and infrastructure

| Runbook | Purpose |
|---|---|
| [zone-opening.md](zone-opening.md) | Open or refresh a reference zone (`make provision <zone>`) |
| [zone-opening-corrections.md](zone-opening-corrections.md) | Read what the completeness gate refused and correct it by hand |
| [events-refresh.md](events-refresh.md) | Refresh the perishable events layer (upsert + purge) |
| [valhalla-routing-graph.md](valhalla-routing-graph.md) | Build the routing graph locally and ship it to the VM (`make routing-build`, `make routing-publish`) |
| [secrets-inventory.md](secrets-inventory.md) | Every production and CI secret: where it lives, who reads it |
| [secrets-rotation.md](secrets-rotation.md) | Rotation policy and per-secret procedures |
| [base-image-cve-maintenance.md](base-image-cve-maintenance.md) | Base-image digest refresh for OS CVEs (triage `fixed` vs won't-fix) |

### Releases

| Runbook | Purpose |
|---|---|
| [release-checklist.md](release-checklist.md) | Checks before pushing a `v*` tag |
| [release-rollback.md](release-rollback.md) | Roll back a bad deploy by redeploying the previous tag |

### Mobile

| Runbook | Purpose |
|---|---|
| [mobile-dev-build-install.md](mobile-dev-build-install.md) | Build the dev client and install it on a device or emulator |
| [mobile-release-build.md](mobile-release-build.md) | Produce a release-signed, sideloadable Android APK |
| [mobile-push-notifications.md](mobile-push-notifications.md) | FCM configuration and push delivery troubleshooting |

## Conventions

- Local commands run from the repository root. `make php-shell` opens a shell in the `php`
  container, where `bin/console` is available.
- Unless a step says otherwise, `docker compose ...` in a runbook targets the stack you are
  working on. **In production**, SSH to the VM as the `deploy` user and run every such command
  from the checkout with the prod env file and overlay (ADR-061):

    ```bash
    cd /opt/bike-trip-planner
    alias dc='docker compose --env-file /etc/bike-trip-planner/app.env -p prod -f compose.yaml -f deploy/prod/compose.yaml'
    dc ps
    ```

    Replace `docker compose` with `dc` in the runbook commands. Shared services live in
    their own compose projects: `valhalla-shared` (`deploy/valhalla/compose.yaml`) and
    `pg-reference` (`/opt/shared-infra/pg-reference/compose.yaml`).
- There is no `compose.prod.yaml`: `compose.yaml` is the iso-prod base, `deploy/prod/compose.yaml`
  the prod overlay, and the prod env file is rendered by Ansible from Vault
  (`ansible/roles/app_deploy/templates/env.j2`).
- Times are UTC unless stated otherwise. The VM itself runs in `Europe/Paris`
  (`ansible/group_vars/all.yml`), so systemd timers fire in local time.

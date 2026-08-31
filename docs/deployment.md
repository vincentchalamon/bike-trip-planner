# Deployment

Bike Trip Planner is deployed on Oracle Cloud Always Free (ARM A1). The VM is provisioned by **Ansible** (Docker + Traefik + `cloudflared` + shared Valhalla/PG-référence + secrets from Vault); deployment is driven by `.github/workflows/deploy.yml` on `v*` tags only, which **SSHes to the VM** and rolls the stack — there is no Coolify (see [ADR-019](adr/adr-019-deployment-infrastructure-strategy.md), amended by [ADR-061](adr/adr-061-deployment-ansible-gha-ssh-traefik-tunnel.md)). A tag marks a deliberate stable release and a clear rollback point, and avoids a slow arm64 build on every `main` push. The app is fronted by **Traefik** (plain HTTP, Docker labels) and reached through a **Cloudflare Tunnel** (`cloudflared`, no public port).

## Pipeline overview

1. **`build-images`** — Builds the `php`, `pwa` and `provisioner` Docker images for `linux/arm64` (matrix) on a native `ubuntu-24.04-arm` runner, then pushes them to `ghcr.io/vincentchalamon/bike-trip-planner-<service>:<sha>` (plus a `:<tag>` mirror for `v*` releases, or a `:pr-<n>` tag for same-repo PR preview builds). Images are always referenced by SHA in production — no mutable `:latest` tag.
2. **`upload-sourcemaps`** — Installs the PWA and runs `next build` with all `SENTRY_*` vars present, so `withSentryConfig` creates the GlitchTip release matching `<sha>` and uploads the source maps during the build itself (then deletes the `.map` files). No separate `@sentry/cli` upload step. Skipped automatically when GlitchTip secrets are absent. The image build deliberately omits `SENTRY_AUTH_TOKEN`, so the deployed bundle never ships source maps.
3. **`deploy-prod`** (tag only) — SSHes to the VM (`SSH_HOST`/`SSH_USER`/`SSH_KEY`), checks out the tag and runs `docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always`, pulling the images this run just pushed by tag. The `deploy/prod/compose.yaml` overlay drops the base's published `80`/`443` (`ports: !reset []`), adds the Traefik router labels and joins the `edge` + `btp-shared` networks. The job **no-ops when the SSH secrets are absent** (forks, and before the Ansible/SSH infra exists), the same transition-safety contract the old Coolify webhook had.
4. **`smoke-test`** — Waits 60 s, then probes `${PROD_HEALTH_URL}/api/healthz` (3 retries, 90 s budget) and `/api/health` (asserts top-level `status == "ok"`, trusting the controller's own readiness verdict rather than per-dependency entries). On failure, raises a `repository_dispatch` event of type `uptime_alert` which is picked up by `.github/workflows/incident-create.yml` (P1.3) to open a P1 incident issue.

Migrations are executed at container boot (see [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md)). GHCR retains images by SHA and by tag (the `build-images` job prunes to the 10 most recent versions per image), so any recent release is a rollback target — rollback = redeploy the previous tag (below).

## Required GitHub Actions secrets

| Secret | Required for | Purpose |
| --- | --- | --- |
| `GITHUB_TOKEN` | always (native) | Push images to GHCR; no manual setup needed. |
| `SENTRY_AUTH_TOKEN` | source-map upload | GlitchTip auth token with `project:releases` scope. |
| `SENTRY_URL` | source-map upload | Base URL of the self-hosted GlitchTip instance (e.g. `https://errors.biketrip.mooo.com/`). |
| `SENTRY_ORG` | source-map upload | GlitchTip organisation slug. |
| `SENTRY_PROJECT` | source-map upload | GlitchTip PWA project slug. |
| `NEXT_PUBLIC_SENTRY_DSN` | image build + source-map upload | Sentry/GlitchTip client DSN inlined into the PWA bundle at build time. Without it, `Sentry.init` runs with an undefined DSN and client-side error capture is silently disabled in production. |
| `SSH_HOST` | prod deploy | Hostname/IP of the prod VM reached by `deploy-prod` over SSH. |
| `SSH_USER` | prod deploy | SSH user with rights to the repo checkout and Docker on the VM. |
| `SSH_KEY` | prod deploy | Private SSH key for the deploy user; its public key is provisioned into `authorized_keys` by Ansible. |
| `PROD_HEALTH_URL` | prod deploy | Base URL the smoke-test probes (`https://www.${DOMAIN}`); defaults to the current host until cutover. |
| `INCIDENT_DISPATCH_TOKEN` | smoke-test failure | Fine-grained PAT (`Contents: write`, `Issues: write`) used to trigger `repository_dispatch` (see P1.3 / ADR-031). Rotate every 90 days. |

When the Sentry/GlitchTip secrets are missing, the `upload-sourcemaps` job is skipped cleanly rather than failing. The `deploy-prod` job behaves the same way: missing `SSH_HOST`/`SSH_USER`/`SSH_KEY` means the deploy is a no-op (useful for forks or before the Ansible/SSH infra is provisioned) — a tag push still builds and pushes the images.

## Monitoring & observability

- **Health endpoints** — `GET /api/healthz` (liveness) and `GET /api/health` (readiness); the smoke-test job and the uptime monitors probe these.
- **Error tracking** — Sentry SDKs (`sentry/sentry-symfony`, `@sentry/nextjs`) capture backend and PWA errors. **Beta (Sprint 34.5):** the DSNs point at **Sentry SaaS free tier**; the self-hosted GlitchTip stack (`.docker/glitchtip/`) is kept in-repo but not deployed. Reversible by switching `SENTRY_DSN` / `NEXT_PUBLIC_SENTRY_DSN` / `SENTRY_URL` back to the GlitchTip instance. See [ADR-031](adr/adr-031-error-tracking-strategy.md) and [.docker/glitchtip](../.docker/glitchtip/README.md).
- **Uptime** — **Beta (Sprint 34.5):** only the external **UptimeRobot** probe on `/api/healthz` is active; self-hosted Uptime Kuma (`.docker/uptime-kuma/`) is kept in-repo but not deployed. See [runbooks/uptime-monitoring.md](runbooks/uptime-monitoring.md) and [.docker/uptime-kuma](../.docker/uptime-kuma/README.md).
- **Incidents** — uptime/error alerts raise a `repository_dispatch` consumed by `.github/workflows/incident-create.yml`, which opens a triaged incident issue. On-call playbooks live in [runbooks/](runbooks/).
- **OSM data** — two datasets, two calendars, both manual; there is no scheduled job. Reference data is re-opened one zone at a time (`make provision <zone>`); the routing graph is rebuilt out of band (`make routing-build <country>`). See [ADR-049](adr/adr-049-zone-opening-and-import-time-completeness.md) and [ADR-036](adr/adr-036-manual-osm-data-refresh.md).
- **Events** — unlike reference data, events are perishable and refreshed on a schedule (ADR-051 §4). A **systemd timer** provisioned by Ansible (default Sunday 03:00 UTC) runs the provisioner image with the `events-refresh` entrypoint (`php -d memory_limit=512M bin/events-refresh`), which re-imports the DataTourisme + OpenAgenda feeds for every open zone and purges events whose `end_date` has passed. It writes only `tourism.events` — no schema swap, no Docker socket, no Valhalla restart — so it needs none of the machinery that retired `osm-cron` (ADR-036). See [runbooks/events-refresh.md](runbooks/events-refresh.md).

## Rollback

See [docs/runbooks/release-rollback.md](runbooks/release-rollback.md) (P2.3) and [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md). TL;DR:

1. Redeploy the previous tag: re-run the `deploy-prod` job on the earlier `v*` tag (Actions → run → re-run), or on the VM `git checkout <previous-tag>` then `docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always`.
2. Verify `/api/healthz` and `/api/health` are green.
3. Confirm with `git log --oneline` which SHA is live (also visible in the `commit` field of `/api/healthz`).

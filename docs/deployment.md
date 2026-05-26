# Deployment

Bike Trip Planner is deployed on Oracle Cloud Always Free (ARM A1) via Coolify (see [ADR-019](adr/adr-019-deployment-infrastructure-strategy.md)). Continuous deployment is driven by `.github/workflows/deploy.yml` on every push to `main` and on `v*` tags.

## Pipeline overview

1. **`build-images`** — Builds the `php`, `pwa` and `provisioner` Docker images for `linux/arm64` (matrix) using Buildx + QEMU, then pushes them to `ghcr.io/vincentchalamon/bike-trip-planner-<service>:<sha>` (plus a `:<tag>` mirror for `v*` releases). Images are always referenced by SHA in production — no mutable `:latest` tag.
2. **`upload-sourcemaps`** — Installs the PWA, runs `next build`, then uses `@sentry/cli` to create a GlitchTip release matching `<sha>` and uploads source maps with `--url-prefix '~/_next'`. Skipped automatically when GlitchTip secrets are absent.
3. **`trigger-coolify`** — Sends a signed `POST` to the Coolify webhook so it pulls the freshly pushed images and rolls the running services.
4. **`smoke-test`** — Waits 60 s, then probes `https://biketrip.mooo.com/api/healthz` (3 retries, 90 s budget) and `/api/health` (asserts every dependency `status == "ok"`). On failure, raises a `repository_dispatch` event of type `uptime_alert` which is picked up by `.github/workflows/incident-create.yml` (P1.3) to open a P1 incident issue.

Migrations are executed at container boot (see [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md)). Coolify keeps the N most recent images, enabling 1-click rollback to any previous SHA.

## Required GitHub Actions secrets

| Secret | Required for | Purpose |
| --- | --- | --- |
| `GITHUB_TOKEN` | always (native) | Push images to GHCR; no manual setup needed. |
| `SENTRY_AUTH_TOKEN` | source-map upload | GlitchTip auth token with `project:releases` scope. |
| `SENTRY_URL` | source-map upload | Base URL of the self-hosted GlitchTip instance (e.g. `https://errors.biketrip.mooo.com/`). |
| `SENTRY_ORG` | source-map upload | GlitchTip organisation slug. |
| `SENTRY_PROJECT` | source-map upload | GlitchTip PWA project slug. |
| `COOLIFY_WEBHOOK_URL` | deploy trigger | Coolify deploy webhook URL for the production app. |
| `COOLIFY_DEPLOY_SECRET` | deploy trigger | Bearer token sent in `Authorization` to authenticate the webhook. |
| `INCIDENT_DISPATCH_TOKEN` | smoke-test failure | Fine-grained PAT (`Contents: write`, `Issues: write`) used to trigger `repository_dispatch` (see P1.3 / ADR-031). Rotate every 90 days. |

When the Sentry/GlitchTip secrets are missing, the `upload-sourcemaps` job is skipped cleanly rather than failing. The `trigger-coolify` job behaves the same way: missing `COOLIFY_WEBHOOK_URL` means the deploy is a no-op (useful for forks or before the production environment is provisioned).

## Rollback

See [docs/runbooks/release-rollback.md](runbooks/release-rollback.md) (P2.3) and [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md). TL;DR:

1. Open Coolify → previous deployment → Redeploy.
2. Verify `/api/healthz` and `/api/health` are green.
3. Confirm with `git log --oneline` which SHA is live (also visible in the `commit` field of `/api/healthz`).

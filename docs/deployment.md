# Deployment

Bike Trip Planner is deployed on Oracle Cloud Always Free (ARM A1) via Coolify (see [ADR-019](adr/adr-019-deployment-infrastructure-strategy.md)). Deployment is driven by `.github/workflows/deploy.yml` on `v*` tags only: a tag marks a deliberate stable release and a clear rollback point, and avoids a slow QEMU arm64 build on every `main` push.

## Pipeline overview

1. **`build-images`** — Builds the `php`, `pwa` and `provisioner` Docker images for `linux/arm64` (matrix) using Buildx + QEMU, then pushes them to `ghcr.io/vincentchalamon/bike-trip-planner-<service>:<sha>` (plus a `:<tag>` mirror for `v*` releases). Images are always referenced by SHA in production — no mutable `:latest` tag.
2. **`upload-sourcemaps`** — Installs the PWA and runs `next build` with all `SENTRY_*` vars present, so `withSentryConfig` creates the GlitchTip release matching `<sha>` and uploads the source maps during the build itself (then deletes the `.map` files). No separate `@sentry/cli` upload step. Skipped automatically when GlitchTip secrets are absent. The image build deliberately omits `SENTRY_AUTH_TOKEN`, so the deployed bundle never ships source maps.
3. **`trigger-coolify`** — Sends a signed `POST` to the Coolify webhook so it pulls the freshly pushed images and rolls the running services.
4. **`smoke-test`** — Waits 60 s, then probes `https://biketrip.mooo.com/api/healthz` (3 retries, 90 s budget) and `/api/health` (asserts top-level `status == "ok"`, trusting the controller's own readiness verdict rather than per-dependency entries). On failure, raises a `repository_dispatch` event of type `uptime_alert` which is picked up by `.github/workflows/incident-create.yml` (P1.3) to open a P1 incident issue.

Migrations are executed at container boot (see [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md)). Coolify keeps the N most recent images, enabling 1-click rollback to any previous SHA; the `build-images` job also prunes GHCR to the 10 most recent versions per image.

## AI (optional, bring-your-own token)

There is no server-side AI tier to deploy. AI features are opt-in and per-user: each account configures its own provider (Anthropic, Google Gemini, or OpenAI) and API key, stored encrypted at rest. The API calls the chosen provider directly with the user's key; with no key or on a provider outage, AI features are hidden and the rule-based alerts stay fully available. See [ADR-042](adr/adr-042-optional-multi-provider-ai-byo-token.md).

## Required GitHub Actions secrets

| Secret | Required for | Purpose |
| --- | --- | --- |
| `GITHUB_TOKEN` | always (native) | Push images to GHCR; no manual setup needed. |
| `SENTRY_AUTH_TOKEN` | source-map upload | GlitchTip auth token with `project:releases` scope. |
| `SENTRY_URL` | source-map upload | Base URL of the self-hosted GlitchTip instance (e.g. `https://errors.biketrip.mooo.com/`). |
| `SENTRY_ORG` | source-map upload | GlitchTip organisation slug. |
| `SENTRY_PROJECT` | source-map upload | GlitchTip PWA project slug. |
| `NEXT_PUBLIC_SENTRY_DSN` | image build + source-map upload | Sentry/GlitchTip client DSN inlined into the PWA bundle at build time. Without it, `Sentry.init` runs with an undefined DSN and client-side error capture is silently disabled in production. |
| `COOLIFY_WEBHOOK_URL` | deploy trigger | Coolify deploy webhook URL for the production app. |
| `COOLIFY_DEPLOY_SECRET` | deploy trigger | Bearer token sent in `Authorization` to authenticate the webhook. |
| `INCIDENT_DISPATCH_TOKEN` | smoke-test failure | Fine-grained PAT (`Contents: write`, `Issues: write`) used to trigger `repository_dispatch` (see P1.3 / ADR-031). Rotate every 90 days. |

When the Sentry/GlitchTip secrets are missing, the `upload-sourcemaps` job is skipped cleanly rather than failing. The `trigger-coolify` job behaves the same way: missing `COOLIFY_WEBHOOK_URL` means the deploy is a no-op (useful for forks or before the production environment is provisioned).

## Monitoring & observability

- **Health endpoints** — `GET /api/healthz` (liveness) and `GET /api/health` (readiness); the smoke-test job and the uptime monitors probe these.
- **Error tracking** — Sentry SDKs (`sentry/sentry-symfony`, `@sentry/nextjs`) capture backend and PWA errors. **Beta (Sprint 34.5):** the DSNs point at **Sentry SaaS free tier**; the self-hosted GlitchTip stack (`.docker/glitchtip/`) is kept in-repo but not deployed. Reversible by switching `SENTRY_DSN` / `NEXT_PUBLIC_SENTRY_DSN` / `SENTRY_URL` back to the GlitchTip instance. See [ADR-031](adr/adr-031-error-tracking-strategy.md) and [.docker/glitchtip](../.docker/glitchtip/README.md).
- **Uptime** — **Beta (Sprint 34.5):** only the external **UptimeRobot** probe on `/api/healthz` is active; self-hosted Uptime Kuma (`.docker/uptime-kuma/`) is kept in-repo but not deployed. See [runbooks/uptime-monitoring.md](runbooks/uptime-monitoring.md) and [.docker/uptime-kuma](../.docker/uptime-kuma/README.md).
- **Incidents** — uptime/error alerts raise a `repository_dispatch` consumed by `.github/workflows/incident-create.yml`, which opens a triaged incident issue. On-call playbooks live in [runbooks/](runbooks/).
- **OSM data** — routing extracts are refreshed manually (`make provision-update` then restart Valhalla); there is no scheduled job. See [ADR-036](adr/adr-036-manual-osm-data-refresh.md).

## Rollback

See [docs/runbooks/release-rollback.md](runbooks/release-rollback.md) (P2.3) and [ADR-032](adr/adr-032-migrations-and-rollback-strategy.md). TL;DR:

1. Open Coolify → previous deployment → Redeploy.
2. Verify `/api/healthz` and `/api/health` are green.
3. Confirm with `git log --oneline` which SHA is live (also visible in the `commit` field of `/api/healthz`).

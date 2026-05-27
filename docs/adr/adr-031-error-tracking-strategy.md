# ADR-031: Error Tracking Strategy — GlitchTip Self-Hosted + Sentry SDK

- **Status:** Accepted
- **Date:** 2026-05-26
- **Depends on:** ADR-019 (Production Hosting on Oracle Cloud + Coolify)
- **Numbering note:** the plan referenced this document as `adr-030-error-tracking-strategy.md`, but ADR-030 was already taken by "symfony/ai Adoption" before this work started. Renumbered to 031 to avoid renumbering an Accepted ADR.

## Context and Problem Statement

Bike Trip Planner is moving from a developer-only setup to a self-hosted production deployment on Oracle Cloud Always Free + Coolify. Today the application has solid local error-handling foundations (Monolog JSON to stderr, Symfony Messenger with retry/DLQ, Next.js error boundaries, Mercure auto-reconnect, healthchecks) but **no centralized capture of runtime exceptions**. Without a tracker:

- Crashes in production are invisible until a user complains.
- Multi-step incidents that span the PWA → API → workers → Mercure chain are impossible to correlate.
- Regressions introduced by a deploy take hours to attribute to a specific release.

We need a tracker that captures backend and frontend exceptions, deduplicates them by fingerprint, links them to a release, and respects the project's strong self-hosting + privacy stance.

## Decision Drivers

1. **Self-hosted:** consistent with the rest of the stack (no SaaS dependency, no exfiltration of user data).
2. **Tight resource budget:** the Oracle VM hosts the API, workers, Mercure, PostgreSQL, Redis, Valhalla, Overpass, and Ollama already. The tracker must stay under ~500 MB RAM.
3. **Existing tooling reuse:** the Sentry SDK is the de-facto standard for both Symfony (`sentry/sentry-symfony`) and Next.js (`@sentry/nextjs`), so SDK choice is downstream of server choice.
4. **No PII leakage:** the backend handles user emails, JWT tokens, raw GPX traces, and chat history. None of these may end up in error payloads.
5. **Free GitHub Actions tier compatible:** alerting will fan out through `repository_dispatch` (covered in P1.3), so the tracker must expose configurable webhooks.

## Considered Alternatives

1. **Sentry SaaS (sentry.io free tier):** mature UI, easy onboarding, but the free tier caps events at 5k/month and ships data to a third party. Rejected on principle.
2. **Sentry self-hosted (full stack):** the same code GlitchTip forked from, but the official self-hosted variant requires ~4 GB RAM and a Kafka+ClickHouse+Snuba stack. Does not fit the Oracle VM budget.
3. **Self-implemented Monolog → PostgreSQL log table + admin UI:** initially attractive (zero new dependency) but reinventing deduplication, fingerprinting, release tracking, and source-map ingestion is far out of scope.
4. **GlitchTip self-hosted (chosen):** Django + PostgreSQL + Redis only, ~500 MB RAM, Sentry SDK wire-protocol compatible. Open source (MIT), actively maintained, exposes Sentry-compatible APIs for `repository_dispatch` webhooks.

## Decision

Adopt **GlitchTip self-hosted** as the error tracker, with the **Sentry SDKs** (`sentry/sentry-symfony` for the API, `@sentry/nextjs` for the PWA) as ingestion clients.

### Topology

- New Coolify service stack under `.docker/glitchtip/docker-compose.yml`:
  - `glitchtip-web` (HTTP UI, Traefik-exposed at `errors.biketrip.mooo.com`)
  - `glitchtip-worker` (Celery worker for async ingestion)
  - `glitchtip-postgres` (dedicated, not the app's PostgreSQL)
  - `glitchtip-redis` (dedicated, not the app's Redis)
- All four containers live on an isolated Docker network; only `glitchtip-web` is published.
- TLS terminated by the Coolify-managed Traefik (Let's Encrypt).

### Sampling

- Backend `traces_sample_rate = 0.05`, `profiles_sample_rate = 0`. Errors are always captured; traces only on 5% of requests.
- Frontend `tracesSampleRate = 0.1`, `replaysSessionSampleRate = 0`. No session replay — privacy + bandwidth.

### Filtering (Privacy + Noise)

Backend `before_send` (see `App\Sentry\ExceptionFilter`):

- Drop `NotFoundHttpException` (404) and `MethodNotAllowedHttpException` (405).
- Drop any `HttpException` with status < 500 (client errors, already surfaced as API Problem responses).
- Drop `Symfony\Component\Validator\Exception\ValidationFailedException` (expected user input outcome).

Frontend `beforeSend` (see `pwa/sentry.client.config.ts`):

- Drop network errors when `navigator.onLine === false` (user-environment issue).
- Drop `ChunkLoadError` (deploy-time chunk invalidation, recovers on next navigation).
- Drop Mercure reconnect errors during the exponential-backoff window.

### PII Posture

- `sendDefaultPii: false` on every SDK.
- Backend `UserDataEnricher` only forwards `user.id` (UUID v7), never the email, JWT, password, or raw GPX content.
- Frontend never forwards the user object; only tags carry trip / request IDs.
- Tags whitelisted: `request_id`, `trip_id`, `computation_name`. **No** authentication artifacts, no header content, no body content.

### Retention

GlitchTip default retention is 30 days for events; we keep it as is. Aggregated issue metadata (count, last_seen) is kept indefinitely.

### Release Tracking

`APP_RELEASE` is set to the commit SHA at build time (P3.1 deploy workflow). The SDK tags every event with this release, enabling "first seen in release X" filtering. Source maps are uploaded to GlitchTip during the PWA build (`hideSourceMaps: true` keeps them off the public CDN).

## Consequences

### Positive

- Full visibility on production exceptions within seconds of capture.
- Deduplication and fingerprinting offload the operator from triaging duplicate reports.
- Release tagging shrinks "which deploy broke this?" from hours to seconds.

### Negative

- Adds ~500 MB RAM and ~5 GB disk over the lifetime of a single retention cycle to the Oracle VM budget.
- GlitchTip co-hosted on the same VM as the app: if the VM dies, both go silent. Mitigated by UptimeRobot's free external tier (P1.2).
- Source-map upload step on every PWA build requires a `SENTRY_AUTH_TOKEN` secret in CI.

### Neutral

- Sentry SDKs are widely used; both LLMs and human developers recognize the patterns. Lock-in is low because GlitchTip implements the public Sentry wire protocol — migrating to another compatible backend is a DSN change.

## Implementation Notes

- The SentryBundle is registered only in the `prod` environment (see `api/config/bundles.php`) so dev and test never make outbound calls.
- When `SENTRY_DSN` is empty, the SDK initializes in a noop mode; the same image therefore runs locally and in production.
- Custom `before_send` and `UserDataEnricher` services are autowired regardless of environment, so they remain unit-testable.

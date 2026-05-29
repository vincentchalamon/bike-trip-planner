# ADR-034: Usage Analytics — Plausible Community Edition Self-Hosted

- **Status:** Accepted
- **Date:** 2026-05-29
- **Depends on:** ADR-019 (Production Hosting on Oracle Cloud + Coolify), ADR-022 (Persistent Storage Strategy)
- **Related:** Sprint 34, Epic #370, Arbitrage #375 v3

## Context and Problem Statement

Bike Trip Planner needs aggregated, anonymous usage metrics to guide product decisions: which import sources are actually used, which features drive retention, and which entry points lead to trip creation. Without this data, prioritisation is driven by assumption rather than evidence.

An earlier implementation plan (replaced by this ADR) considered a native `UsageEvent` partitioned PostgreSQL table with a custom aggregation layer. That approach was abandoned: it required significant bespoke development (schema, aggregation queries, retention, a read interface), duplicated problems that dedicated analytics tools already solve, and added ongoing maintenance surface to the API and Doctrine ORM layer.

The replacement must:

1. Collect page views and custom interaction events.
2. Never store PII or use browser fingerprinting (RGPD / GDPR constraints are strict).
3. Require no cookie consent banner (no cookies, no cross-site tracking).
4. Integrate with the existing Docker Compose self-hosted deployment on Oracle Cloud + Coolify (ADR-019).
5. Have zero recurring licence cost.

## Decision Drivers

- **Data sovereignty:** user interaction data must not leave the self-hosted infrastructure.
- **RGPD compliance by design:** no cookies, no browser fingerprint, anonymised IP and User-Agent — no consent banner required under RGPD recital 47 / ePrivacy Directive.
- **Cost:** cloud SaaS plans add a recurring invoice to a project with a zero-budget ops model.
- **Operational fit:** the deployment already runs Docker Compose on a single Oracle VM; a new Compose service is the natural unit of integration.
- **Licence:** must be open source with a permissive enough licence for self-hosting (AGPL-3.0 is acceptable; it only requires source disclosure if the software itself is distributed, which self-hosting is not).

## Decision

Adopt **Plausible Analytics Community Edition (CE)** running as a self-hosted Docker Compose service, deployed alongside the existing stack on the Coolify-managed Oracle VM.

### Why Self-Hosted Instead of `plausible.io` Cloud

| Dimension | Plausible Cloud | Plausible CE Self-Hosted |
|---|---|---|
| Data location | Plausible EU servers | Our own VM, our own PostgreSQL + ClickHouse |
| Monthly cost | $9/mo (100k pageviews) | $0 (licence) + marginal VM resources |
| RGPD data-processor agreement | Required (DPA with Plausible) | Not required (single-controller) |
| Control over retention | Via dashboard setting | Full — `clickhouse` TTL configurable |
| Upgrade cadence | Automatic | Manual (pinned image digest) |

Self-hosting is the correct choice: data sovereignty, zero recurring cost, and no third-party data processor in the RGPD chain.

### Topology

New Coolify service stack under `.docker/plausible/docker-compose.yml`:

- `plausible` — Elixir/Phoenix HTTP application (Traefik-exposed at `stats.biketrip.mooo.com`).
- `plausible-db` — dedicated PostgreSQL instance (metadata, accounts, site config).
- `plausible-events-db` — ClickHouse for event storage and aggregation.

All three containers live on an isolated Docker network; only `plausible` is published.
TLS terminated by the Coolify-managed Traefik (Let's Encrypt).

The PWA injects the `plausible/analytics` script via a `<Script>` tag in the root layout. The script is served from the same subdomain (`stats.biketrip.mooo.com/js/script.js`) to avoid ad-blocker false positives on common public Plausible CDN hostnames.

### RGPD / Privacy Posture

Plausible CE does not set any cookie and does not use browser fingerprinting. The following anonymisations apply natively before any event is stored:

- **IP address:** hashed with a daily rotating salt; the original IP is never persisted.
- **User-Agent:** parsed to `{browser}/{version}` and `{os}/{version}`; the full string is discarded.
- Events carry no `userId`, no session ID, and no cross-visit identifier.

Consequence: no cookie consent banner is required. Plausible's privacy model is explicitly designed for RGPD compliance without a consent mechanism; this is documented in [their compliance page](https://plausible.io/data-policy).

### Custom Events

Beyond automatic pageview tracking, the following custom events are instrumented:

**Import source events** (sent when a user successfully imports a route):

| Event name | Description |
|---|---|
| `import_komoot` | Komoot tour or collection imported |
| `import_strava` | Strava route imported |
| `import_rwgps` | RideWithGPS route imported |
| `import_gpx` | GPX file uploaded |

**Feature and retention events** (sent on meaningful interactions):

| Event name | Description |
|---|---|
| `trip_created` | A new trip is saved for the first time |
| `trip_shared` | A trip share link is generated |
| `accommodation_selected` | User saves an accommodation suggestion |
| `alert_action_clicked` | User acts on an alert nudge (dismiss / resolve) |
| `ai_chat_opened` | AI assistant chat panel is opened |

Events are fired via `plausible()` from the frontend using the Plausible custom event API. No backend instrumentation is needed; all events are browser-side.

### Consent Gating Strategy

Because Plausible requires no consent under RGPD by design, the script is loaded unconditionally for all visitors. No consent banner or opt-in gate is displayed. If a visitor uses a browser-level Do Not Track (DNT) header, the Plausible script honours it natively (tracking is suppressed client-side).

Should the project expand to jurisdictions with stricter requirements (e.g. Quebec Law 25, CCPA opt-out), a thin wrapper around `plausible()` can be introduced that checks a localStorage preference before firing events. This is a future concern; the current implementation is unconditional.

## Alternatives Considered

### Plausible Cloud (`plausible.io`)

The simplest path: sign up, paste one script tag, and data appears in the dashboard within minutes. No ops burden.

**Rejected because:**

- Recurring cost ($9/mo minimum) contradicts the zero-budget ops model.
- Data leaves our infrastructure; a RGPD data-processor agreement (DPA) is required.
- At scale (100k+ pageviews), cost tiers up significantly.

### Native `UsageEvent` Implementation (Abandoned)

A custom Doctrine entity `UsageEvent` backed by a partitioned PostgreSQL table (partition by month), with a custom aggregation layer and an admin read interface.

**Rejected (and previously abandoned) because:**

- Significant bespoke development effort: schema, indexes, aggregation queries, a retention/pruning job, and a read interface (API endpoint or dashboard).
- Duplicates problems Plausible already solves — deduplication, session window, funnel analysis, time-series charts.
- Adds ongoing maintenance to the API and ORM layer for a concern orthogonal to trip planning.
- PostgreSQL is a poor fit for high-cardinality append-only event streams (ClickHouse, which Plausible uses, is columnar and purpose-built for this workload).

### Matomo (Self-Hosted)

Open source (GPL-3.0), well-established, feature-rich analytics platform with an extensive plugin ecosystem.

**Rejected because:**

- Cookie-based by default; requires consent configuration and explicit RGPD plugin setup to reach cookie-free mode.
- Heavier resource footprint than Plausible (PHP + MariaDB + a separate cron container).
- Feature scope far exceeds what Bike Trip Planner needs; the complexity overhead is not justified.

### Google Analytics 4 (GA4)

Widely used, free SaaS, with powerful funnel and retention analysis.

**Rejected because:**

- Sends data to Google's servers; fundamentally incompatible with data sovereignty and RGPD compliance without a consent banner.
- Cookie-based by default; a consent banner is legally required in EU.
- Introduces a third-party dependency that ad-blockers routinely block, degrading data quality.

## Consequences

### Positive

- Zero recurring licence cost.
- RGPD compliance by design: no cookies, no fingerprinting, no consent banner needed.
- Data stays on our infrastructure; no third-party data processor in the chain.
- Out-of-the-box dashboard with pageviews, referrers, countries, devices, custom events, funnels.
- Custom event API is simple: one `plausible('event_name')` call per interaction.

### Negative

- **Resource overhead:** Plausible CE requires PostgreSQL (metadata) + ClickHouse (events). ClickHouse is the dominant cost: ~1 GB RAM at idle, ~10 GB disk for the first year of data at moderate traffic. This adds to the Oracle VM's already-loaded memory budget (GlitchTip, Valhalla, Ollama, app stack).
- **ClickHouse backup:** an additional backup target beyond the existing PostgreSQL dumps. ClickHouse supports `BACKUP TO S3` or filesystem snapshots; this must be added to the ops runbook.
- **DNS / TLS:** a new subdomain (`stats.biketrip.mooo.com`) must be provisioned and pointed to the Oracle VM. Let's Encrypt certificate managed by Traefik as with other services.
- **Upgrade cadence:** Plausible CE releases must be tracked manually (no automatic updates); pinned image tags should be updated via Dependabot or a periodic review.
- **Native `UsageEvent` implementation abandoned:** any future need for server-side event correlation (e.g. linking an analytics event to a specific trip computation) requires a separate mechanism, as Plausible events carry no application-level IDs.

### Neutral

- The Plausible CE licence (AGPL-3.0) applies to the Plausible source code itself; self-hosting does not trigger the AGPL copyleft clause (the software is not distributed). No impact on Bike Trip Planner's own MIT licence.
- The `plausible/analytics` JavaScript snippet is ~1 kB (gzipped); negligible impact on PWA bundle size and Lighthouse performance score.

## References

- [Plausible CE self-hosting documentation](https://plausible.io/docs/self-hosting)
- [Plausible RGPD compliance](https://plausible.io/data-policy)
- [Plausible custom events API](https://plausible.io/docs/custom-event-goals)
- [ADR-019: Deployment Infrastructure Strategy](adr-019-deployment-infrastructure-strategy.md)
- [ADR-022: Persistent Storage Strategy](adr-022-persistent-storage-strategy.md)
- [ADR-031: Error Tracking Strategy (GlitchTip)](adr-031-error-tracking-strategy.md)

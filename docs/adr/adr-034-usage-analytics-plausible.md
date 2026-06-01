# ADR-034: Usage Analytics — Plausible Community Edition Self-Hosted

- **Status:** Accepted — reactivation engine under review (see [§Reactivating Analytics After the Beta](#reactivating-analytics-after-the-beta))
- **Date:** 2026-05-29
- **Depends on:** ADR-019 (Production Hosting on Oracle Cloud + Coolify), ADR-022 (Persistent Storage Strategy)
- **Related:** Sprint 34, Epic #370, Arbitrage #375 v3

## Context and Problem Statement

Bike Trip Planner needs aggregated, anonymous usage metrics to guide product decisions: which import sources are actually used, which features drive retention, and which entry points lead to trip creation. Without this data, prioritisation is driven by assumption rather than evidence.

An earlier implementation plan (replaced by this ADR) considered a native `UsageEvent` partitioned PostgreSQL table with a custom aggregation layer. That approach was abandoned: it required significant bespoke development (schema, aggregation queries, retention, a read interface), duplicated problems that dedicated analytics tools already solve, and added ongoing maintenance surface to the API and Doctrine ORM layer.

The replacement must:

1. Collect page views and custom interaction events.
2. Never store PII or use browser fingerprinting (RGPD / GDPR constraints are strict).
3. Require no consent banner: Plausible is cookieless and stores no PII, so loading is gated solely on environment configuration (lawful basis: legitimate interest, RGPD art. 6(1)(f)).
4. Integrate with the existing Docker Compose self-hosted deployment on Oracle Cloud + Coolify (ADR-019).
5. Have zero recurring licence cost.

## Decision Drivers

- **Data sovereignty:** user interaction data must not leave the self-hosted infrastructure.
- **RGPD compliance by design:** no cookies, no browser fingerprint, anonymised IP and User-Agent. A consent banner is not legally required: ePrivacy art. 5(3) / art. 82 LIL is not triggered (nothing is stored on or read from the device), and the processing rests on legitimate interest (RGPD art. 6(1)(f)). Loading is therefore gated solely on environment configuration; no cookie banner is shown.
- **Cost:** cloud SaaS plans add a recurring invoice to a project with a zero-budget ops model.
- **Operational fit:** the deployment already runs Docker Compose on a single Oracle VM; a new Compose service is the natural unit of integration.
- **Licence:** must be open source with a permissive enough licence for self-hosting (AGPL-3.0 is acceptable; it only requires source disclosure if the software itself is distributed, which self-hosting is not).

## Decision

Adopt **Plausible Analytics Community Edition (CE)** running as a self-hosted Docker Compose service, deployed alongside the existing stack on the Coolify-managed Oracle VM.

### Why Self-Hosted Instead of `plausible.io` Cloud

| Dimension                     | Plausible Cloud               | Plausible CE Self-Hosted                    |
| ----------------------------- | ----------------------------- | ------------------------------------------- |
| Data location                 | Plausible EU servers          | Our own VM, our own PostgreSQL + ClickHouse |
| Monthly cost                  | $9/mo (100k pageviews)        | $0 (licence) + marginal VM resources        |
| RGPD data-processor agreement | Required (DPA with Plausible) | Not required (single-controller)            |
| Control over retention        | Via dashboard setting         | Full — `clickhouse` TTL configurable        |
| Upgrade cadence               | Automatic                     | Manual (pinned image digest)                |

Self-hosting is the correct choice: data sovereignty, zero recurring cost, and no third-party data processor in the RGPD chain.

### Topology

New Coolify service stack under `.docker/plausible/docker-compose.yml`:

- `plausible` — Elixir/Phoenix HTTP application (Traefik-exposed at `stats.biketrip.mooo.com`).
- `plausible-db` — dedicated PostgreSQL instance (metadata, accounts, site config).
- `plausible-events-db` — ClickHouse for event storage and aggregation.

All three containers live on an isolated Docker network; only `plausible` is published.
TLS terminated by the Coolify-managed Traefik (Let's Encrypt).

The PWA injects the `plausible/analytics` script via a `<Script>` tag in the root layout, conditionally — only when the Plausible env vars are configured (see Loading Strategy below). The script is served from the same subdomain (`stats.biketrip.mooo.com/js/script.js`) to avoid ad-blocker false positives on common public Plausible CDN hostnames.

### RGPD / Privacy Posture

Plausible CE does not set any cookie and does not use browser fingerprinting. The following anonymisations apply natively before any event is stored:

- **IP address:** hashed with a daily rotating salt; the original IP is never persisted.
- **User-Agent:** parsed to `{browser}/{version}` and `{os}/{version}`; the full string is discarded.
- Events carry no `userId`, no session ID, and no cross-visit identifier.

Consequence: under RGPD, no cookie consent banner is legally required — Plausible's privacy model is explicitly designed for compliance without a consent mechanism ([their compliance page](https://plausible.io/data-policy)), and this aligns with the CNIL audience-measurement framework (anonymous statistics only, no cross-site tracking, EU self-hosted, disclosure in the privacy policy). Loading is therefore gated solely on environment configuration (see Loading Strategy below); a consent banner would contradict this posture and add needless friction. Users retain a documented right to object on the `/privacy` page.

### Custom Events

Beyond automatic pageview tracking, the following custom events are instrumented:

**Import source events** (sent when a user successfully imports a route):

| Event name      | Description                        |
| --------------- | ---------------------------------- |
| `import_komoot` | Komoot tour or collection imported |
| `import_strava` | Strava route imported              |
| `import_rwgps`  | RideWithGPS route imported         |
| `import_gpx`    | GPX file uploaded                  |

**Feature and retention events** (sent on meaningful interactions):

| Event name               | Description                                     |
| ------------------------ | ----------------------------------------------- |
| `trip_created`           | A new trip is saved for the first time          |
| `trip_shared`            | A trip share link is generated                  |
| `accommodation_selected` | User saves an accommodation suggestion          |
| `alert_action_clicked`   | User acts on an alert nudge (dismiss / resolve) |
| `ai_chat_opened`         | AI assistant chat panel is opened               |

Events are fired via `plausible()` from the frontend using the Plausible custom event API. No backend instrumentation is needed; all events are browser-side.

### Loading Strategy

Because Plausible requires no consent (cookieless, no PII — see above), the script is **loaded solely on environment configuration**, with no consent banner:

- The `<Script>` tag (issue #552) injects only when both `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` and `NEXT_PUBLIC_PLAUSIBLE_SRC` are set. When they are unset, nothing is rendered and no request is ever made to the Plausible host.
- Custom events (issue #553) are fired through a `trackEvent` helper that is a no-op when the script is absent, so they inherit the same gate.
- Browser-level Do Not Track (DNT) is honoured natively client-side by Plausible.

A cookie consent banner was initially scoped (issue #385) but dropped: it would gate a tool that legally needs no consent, contradict the privacy policy, and reduce data accuracy for no compliance benefit.

**Beta posture (Sprint 34.5, issue #567):** the env-only gate also serves as the analytics kill-switch. During the restricted beta the env vars are left unset, so the analytics code ships but stays dormant — **zero analytics footprint** (no script injected, no request to the analytics host, no cookie, and `trackEvent` is a no-op). This is asserted end-to-end in `pwa/tests/mocked/plausible-analytics.spec.ts` (env-unset case) and at the unit level in `pwa/src/lib/plausible.test.ts`. The analytics **server is deliberately not deployed** during the beta (issue #551 deferred): at <10 users the data is meaningless and the ClickHouse footprint (~2-4 GB RAM) is not justified.

### Reactivating Analytics After the Beta

Reactivation is a deploy-time concern; the frontend needs no code change. Two steps:

1. **Provision an analytics server** (see arbitrage below).
2. **Set the two PWA env vars** at build time: `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` (the site domain registered in the analytics dashboard) and `NEXT_PUBLIC_PLAUSIBLE_SRC` (the tracker script URL). Once both are set, the `<Script>` tag injects automatically and `trackEvent` starts emitting custom events. Leaving either unset returns to the dormant state.

#### Recommended Engine at Reactivation: Umami (revisit vs. Plausible CE)

ADR-034 selected Plausible CE on the assumption of a self-hosted server bearing PostgreSQL **and** ClickHouse. At beta exit (target <50 users), the ClickHouse footprint dominates the cost (~1 GB RAM idle, multi-GB disk) for a traffic volume that does not need columnar event storage. For this scale, prefer **[Umami](https://umami.is/)**:

| Dimension     | Plausible CE                  | Umami                                           |
| ------------- | ----------------------------- | ----------------------------------------------- |
| Datastore     | PostgreSQL + **ClickHouse**   | PostgreSQL **only** (or MySQL)                  |
| Footprint     | ~1 GB RAM idle (ClickHouse)   | ~150 MB RAM                                     |
| RGPD posture  | Cookieless, no PII, no banner | Cookieless, no PII, no banner                   |
| Custom events | `plausible('event')` API      | `umami.track('event')` API                      |
| Licence       | AGPL-3.0                      | MIT                                             |
| Self-hosting  | Docker Compose                | Docker Compose (reuses the existing PostgreSQL) |

Umami reuses the stack's existing PostgreSQL (no second engine), keeping the Oracle VM memory budget (GlitchTip, Valhalla, Ollama, app stack) intact. It is cookieless and PII-free, so the RGPD posture and the no-consent-banner rationale above hold unchanged.

Migration cost is small and confined to the frontend tracker, because the gating contract is engine-agnostic: the `PlausibleScript` component and the `trackEvent` helper would be renamed/retargeted to Umami's `umami.track()` API, but the env-gated load pattern and the custom-event list (the two tables above) carry over as-is. **This arbitrage is to be confirmed when analytics is reactivated**; until then both the env vars stay unset and no server is deployed.

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
- RGPD compliance by design: no cookies, no fingerprinting; no consent banner is required (legitimate interest), so loading is gated solely on environment configuration — no friction, no consent-decline data loss.
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

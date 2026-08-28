# Architecture

A one-page overview of how Bike Trip Planner fits together, plus a thematic index into the
[Architecture Decision Records](adr/) that justify each choice. For the product view see the
[README](../README.md), which also lists the product features.

## Shape

Decoupled, stateless-compute architecture:

- **Frontend** — Next.js 16 (App Router), React 19, TypeScript strict. Local-first state in
  Zustand + Immer (in-memory, no persistence), validated with Zod. Type-safe API calls via
  openapi-fetch. Receives live computation updates over Mercure SSE.
- **Backend** — API Platform 4.3 on Symfony 8 (PHP 8.5). Custom State Providers/Processors (not
  Doctrine auto-CRUD). Heavy work runs asynchronously on Symfony Messenger workers; progress is
  pushed to the browser via Mercure.
- **Data** — PostgreSQL 18 (Doctrine ORM) persists trip configuration and stages. Redis holds
  transient computation state, the Messenger transport, and external-API caches.

<!-- markdownlint-disable MD040 -->
```
Browser (Next.js 16)            PHP backend (API Platform 4.3)
  Zustand + Immer (in-memory)     Stateless computation
  Zod validation                  GPX parsing + pacing engine
  openapi-fetch (typed)           Valhalla routing + PostGIS + weather
  Mercure SSE (real-time)  <--    Async workers (Symfony Messenger)
                                  PostgreSQL + Redis + Mercure publisher
```

## Type contract (single source of truth)

PHP DTOs define the schema -> API Platform exports an OpenAPI spec -> `make typegen`
(openapi-typescript) generates TypeScript types -> openapi-fetch consumes them. A backend schema
change intentionally breaks the frontend build, preventing data drift. See
[ADR-002](adr/adr-002-interface-contract-and-strict-typing.md).

The generated types land in `core/schema.d.ts`, in the framework-free **`@btp/core`** workspace
that both `pwa` and `mobile` import (ADR-053) -- there is no mobile-local schema copy. The CI job
`openapi-typegen-drift` (`.github/workflows/ci.yml`) regenerates the types from the exported
OpenAPI spec and fails the build if `core/schema.d.ts` differs from what is committed, so schema
drift between backend and either frontend is caught automatically, never by hand. `@btp/core` also
carries the Mercure wire types (`core/mercure.ts`) and the pure SSE reconciliation reducers
(`core/reconciliation.ts`): web and mobile compose the same reducers rather than each
re-implementing reconciliation, so a race or field-preservation fix lands once for both platforms.
See [ADR-055](adr/adr-055-mobile-state-architecture.md).

## Async computation pipeline

A trip request fans out into independent computations (route, stages, OSM scan, POIs, weather,
terrain, accommodations) across five Messenger workers backed by Redis. A two-phase gate
(preview -> analysis) sequences the work, and a tracker emits `computation_step_completed`,
`trip_ready`, and `stage_updated` events over Mercure. See
[ADR-016](adr/adr-016-performance-optimization-strategy.md) and
[ADR-027](adr/adr-027-gate-mechanism-two-phase-pipeline.md).

## Routing & geospatial

GPX is parsed with a streaming XMLReader (constant memory), elevation-smoothed, then decimated
with Douglas-Peucker (~25k -> ~1.5k points). Routing uses a self-hosted Valhalla engine fed by
Geofabrik extracts; OSM features come from a local PostGIS reference index imported by the
provisioner, which the API queries directly — no runtime Overpass dependency. See
[ADR-004](adr/adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md),
[ADR-017](adr/adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md),
[ADR-033](adr/adr-033-osm-data-refresh-strategy.md) and
[ADR-040](adr/adr-040-local-first-reference-data-postgis.md).

## Alert engine

Stage analyzers implement `StageAnalyzerInterface` and are auto-discovered via a tagged iterator
(`app.stage_analyzer`); priority integers order them. Heavier or POI-dependent checks run as
dedicated async message handlers instead. Every emitted alert carries a stable `App\Enum\AlertCode`
naming its rule variant, and each code needs its own row in the README alert table — this coupling
is enforced in both directions by `AlertDocumentationTest`, which scans the source for emitted
codes rather than relying on a hand-maintained map. See
[ADR-012](adr/adr-012-rule-based-nudge-and-contextual-alert-engine.md),
[ADR-014](adr/adr-014-alert-extensibility.md) and
[ADR-015](adr/adr-015-dynamic-engine-management-design-pattern.md).

## Deployment & operations

Production runs as Docker Compose on an Oracle Cloud VM, orchestrated by Coolify and shipped by
the `deploy.yml` workflow. Errors flow through the Sentry SDKs and uptime is probed externally; in
beta (Sprint 34.5) these point at Sentry SaaS and UptimeRobot, with the self-hosted GlitchTip and
Uptime Kuma stacks kept in-repo but not deployed (reversible — see ADR-031). See
[Deployment](deployment.md) and the [runbooks](runbooks/).

## ADR index by theme

- **Foundations:** ADR-001 (global architecture), ADR-002 (type contract), ADR-003 (local-first
  state & migrations), ADR-009 (QA & testing), ADR-010 (DX & local infra), ADR-037 (Docker
  dev/prod convergence).
- **Geospatial & routing:** ADR-004, ADR-005 (external-API caching), ADR-006 (pacing engine),
  ADR-017 (Valhalla), ADR-040 (local-first PostGIS reference data), ADR-041 (provisioner
  resilience), ADR-036 (manual OSM refresh).
- **Alerts & enrichment:** ADR-012, ADR-013 (accommodation pricing), ADR-014, ADR-015, ADR-026
  (multi-source data), ADR-044 (data.gouv.fr markets source removed).
- **Frontend & state:** ADR-007.
- **Pipeline & performance:** ADR-016, ADR-027, ADR-043 (synchronous structural compute, async
  enrichments).
- **Export & devices:** ADR-018 (Garmin), ADR-021 (enriched GPX), ADR-024 (mobile / Capacitor).
- **Auth, access & privacy:** ADR-023 (magic link), ADR-029 (early access), ADR-034 (analytics),
  ADR-035 (GDPR erasure), ADR-038 (hide forbidden as not-found), ADR-047 (server-side web auth).
- **Infrastructure & ops:** ADR-019 (hosting), ADR-022 (storage), ADR-031 (error tracking),
  ADR-032 (migrations & rollback), ADR-039 (beta right-sizing free tier).
- **Security:** ADR-011 (SSRF / XXE prevention).

Several ADRs are superseded or revoked and kept in `adr/` for historical context: ADR-008 (PDF
roadbook, revoked), ADR-020 & ADR-025 (self-hosted Overpass, replaced by PostGIS in ADR-040),
ADR-028, ADR-030, ADR-042, ADR-045 & ADR-046 (all AI, withdrawn by ADR-052 when AI support was
removed), and ADR-033 (nightly OSM refresh, replaced by ADR-036).

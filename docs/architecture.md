# Architecture

A one-page overview of how Bike Trip Planner fits together, plus a thematic index into the
[Architecture Decision Records](adr/) that justify each choice. For the product view see the
[README](../README.md); for the feature inventory see [FEATURES.md](../FEATURES.md).

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
  openapi-fetch (typed)           Valhalla routing + OSM/weather APIs
  Mercure SSE (real-time)  <--    Async workers (Symfony Messenger)
                                  PostgreSQL + Redis + Mercure publisher
```

## Type contract (single source of truth)

PHP DTOs define the schema -> API Platform exports an OpenAPI spec -> `make typegen`
(openapi-typescript) generates TypeScript types -> openapi-fetch consumes them. A backend schema
change intentionally breaks the frontend build, preventing data drift. See
[ADR-002](adr/adr-002-interface-contract-and-strict-typing.md).

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
Geofabrik extracts; OSM features come from the public Overpass API (cached in Redis). See
[ADR-004](adr/adr-004-spatial-engineering-gpx-parsing-and-data-decimation.md),
[ADR-017](adr/adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md) and
[ADR-033](adr/adr-033-osm-data-refresh-strategy.md).

## Alert engine

Stage analyzers implement `StageAnalyzerInterface` and are auto-discovered via a tagged iterator
(`app.stage_analyzer`); priority integers order them. Heavier or POI-dependent checks run as
dedicated async message handlers instead. New rules must also be reflected in the README alert
table and in `ALERT_RULE_MAP` — this coupling is enforced by `AlertDocumentationTest`. See
[ADR-012](adr/adr-012-rule-based-nudge-and-contextual-alert-engine.md),
[ADR-014](adr/adr-014-alert-extensibility.md) and
[ADR-015](adr/adr-015-dynamic-engine-management-design-pattern.md).

## AI pipeline

Self-hosted Ollama serves LLaMA models through `symfony/ai`. A two-pass analysis (per-stage, then
whole-trip) plus a context-aware chat assistant enrich the roadbook; everything degrades
gracefully when Ollama is unreachable. See
[ADR-028](adr/adr-028-ollama-llama-integration.md) and
[ADR-030](adr/adr-030-symfony-ai-adoption.md).

## Deployment & operations

Production runs as Docker Compose on an Oracle Cloud VM, orchestrated by Coolify and shipped by
the `deploy.yml` workflow. Errors flow to self-hosted GlitchTip; uptime is watched by Uptime Kuma
and UptimeRobot. See [Deployment](deployment.md) and the [runbooks](runbooks/).

## ADR index by theme

- **Foundations:** ADR-001 (global architecture), ADR-002 (type contract), ADR-003 (local-first
  state & migrations), ADR-009 (QA & testing), ADR-010 (DX & local infra).
- **Geospatial & routing:** ADR-004, ADR-005 (external-API caching), ADR-006 (pacing engine),
  ADR-017, ADR-025, ADR-033.
- **Alerts & enrichment:** ADR-012, ADR-013 (accommodation pricing), ADR-014, ADR-015, ADR-026
  (multi-source data).
- **Frontend & state:** ADR-007.
- **Pipeline & performance:** ADR-016, ADR-027.
- **Export & devices:** ADR-018 (Garmin), ADR-021 (enriched GPX), ADR-024 (mobile / Capacitor).
- **Auth, access & privacy:** ADR-023 (magic link), ADR-029 (early access), ADR-034 (analytics),
  ADR-035 (GDPR erasure).
- **AI:** ADR-028, ADR-030.
- **Infrastructure & ops:** ADR-019 (hosting), ADR-022 (storage), ADR-031 (error tracking),
  ADR-032 (migrations & rollback).
- **Security:** ADR-011 (SSRF / XXE prevention).

A few early ADRs are superseded or revoked (e.g. ADR-008 PDF roadbook, ADR-020 dynamic Overpass
provisioning); they are kept in `adr/` for historical context.

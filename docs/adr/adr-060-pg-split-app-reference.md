# ADR-060: Split Postgres into PG-app and PG-référence

- **Status:** Accepted
- **Date:** 2026-08-31
- **Depends on:** ADR-019 (Deployment & Resource Sizing), ADR-022 (Persistent Storage Strategy), ADR-040 (Local-first Tier-1 PostGIS reference index), ADR-049 (Two datasets, two grains, two calendars)

## Context and Problem Statement

The deployment target moves to feature-deploys by pull request on a single VM: production and every preview run as isolated `docker compose -p` stacks side by side. Each stack needs its **own** application database (its trips, stages and accounts must not leak across previews), but the **reference index** — the local-first Tier-1 PostGIS data (`osm` / `tourism`), plus the provisioner's own bookkeeping (`provisioner`) — is large (France 15-25 GB), regional, slow to build and identical for every stack. Giving each preview its own copy is neither affordable on a 24 GB VM nor sensible: the data is read-only for the app and deliberately dated (ADR-040/049).

So the one Postgres holding everything has to split into two roles:

- **PG-app**, one per stack: the `public` schema — trips, stages, auth. Read-write, small, disposable.
- **PG-référence**, shared across stacks: `osm` / `tourism` / `provisioner`. Read-only for the app, written only by the provisioner.

Verified before splitting: there is **no cross-schema foreign key or join** between `public` and `osm`/`tourism` — the app reads the reference index through raw-SQL repositories (`App\Osm\*`, `App\Tourism\*`, `App\InRide\InRidePoiRepository`, `App\Command\NotifyZoneOpenedCommand`), each taking an injected `Doctrine\DBAL\Connection`, never an ORM association. A physical split onto two hosts is therefore transparent to the query layer.

This is **greenfield**: no `v*` tag has shipped and no production database exists (see the ADR-032 baseline reset), so the split is pure DDL reorganisation with no data migration.

## Decision

Two Doctrine DBAL connections, one default entity manager, split migration histories.

- **Two DBAL connections, one EM.** `doctrine.dbal.connections.default` (`DATABASE_URL`) is PG-app; `doctrine.dbal.connections.reference` (`REFERENCE_DATABASE_URL`) is PG-référence. `dbal.default_connection: default`. There is **no second entity manager**: the reference side has no ORM entity — every reader is raw SQL — so a second EM would be dead weight. The `jsonb` / `text[]` mapping types are duplicated onto the reference connection so those repositories' result conversions behave identically.
- **Bind the reference connection by parameter name.** `services.php` binds `Doctrine\DBAL\Connection $referenceConnection` to `@doctrine.dbal.reference_connection`. Each reference repository/command takes a `Connection $referenceConnection`; a plain `Connection` still autowires to the default PG-app connection (so, e.g., `HealthController` holds both — `postgres` on default, `postgres_reference` + reference-data on reference).
- **`REFERENCE_DATABASE_URL` defaults to `DATABASE_URL`.** Set as the container default for `env(REFERENCE_DATABASE_URL)`, so dev/CI keep running on a **single** Postgres with zero extra configuration; the split into a separate host is a prod/Ansible concern. It is wired through `compose.yaml` (the iso-prod base) on `php` and `worker`, next to `DATABASE_URL`, so it is never silently empty in prod. The provisioner points at PG-référence through its libpq `PG*` env.
- **Two migration histories.**
  - **Public** DDL (`schema/public_schema.sql`) runs on the default connection at boot (`MIGRATIONS_ON_BOOT`), history `doctrine_migration_versions`. It carries **no PostGIS dependency** (stage geometry is `jsonb`).
  - **Reference** DDL (`schema/reference_schema.sql`: the `osm`/`tourism`/`provisioner` schemas, the `CREATE EXTENSION postgis`, and the formerly-separate `tourism.events.source` column folded inline) is a separate namespace + directory (`DoctrineMigrations\Reference`, `migrations/reference`). It is **not** run on the per-stack PG-app at boot. Against a physically-separate PG-référence it can be versioned as its own history with its own table (`config/migrations_reference.php` + `--conn reference`); in dev/CI, where one Postgres backs both connections, that path is instead folded into the boot history (`doctrine_migrations.php`, dev/test only) so a single `migrate` builds both.
- **The provisioner owns the reference DDL.** In production the provisioner's container entrypoint applies the idempotent `reference_schema.sql` via `psql` before the first import, so the live `osm`/`tourism` tables exist for promotion. The reference Doctrine migration executes the **same** file, so the two executors cannot drift. On a real split the per-stack PG-app is never provisioned with the reference schema.
- **Single-database deployments self-provision the reference schema at boot.** Dev, CI, the E2E integration smoke and the recette all run **one** Postgres for both connections and never launch the provisioner, yet the app still reads `osm`/`tourism` — even on a plain GPX import, `storeStages()` scans `osm.coverage`/`osm.cycle_routes` for the out-of-zone and on-cycle-network metrics. So the boot entrypoint detects the single-database case (`REFERENCE_DATABASE_URL` unset or equal to `DATABASE_URL`) and, after the public migrate, runs the reference history on the `reference` connection too — the empty `osm`/`tourism` tables then exist and those reads return empty instead of erroring, exactly as the pre-split single baseline did. On a real split (the two URLs differ) this is skipped: the app stays read-only on the shared PG-référence. (`make start-dev`/Foundry reach the same result through the dev/test fold in `doctrine_migrations.php`; the entrypoint step covers the iso-prod `APP_ENV=prod` stacks.)
- **PostGIS is required on PG-référence, not on PG-app.** The reference tables use `public.geometry` + `ST_*`; the shared reference database must be a `postgis/postgis` image (provisioned by Ansible in a later PR). PG-app needs no PostGIS.

## Test harness — two connections, one physical database

In test/CI a single Postgres backs both connections (`REFERENCE_DATABASE_URL` defaults to `DATABASE_URL`, both taking the `_test` suffix). The reference migration path is registered into the boot history for dev/test only (`doctrine_migrations.php`), so Foundry's single `migrate` reset builds the `public` **and** the `osm`/`tourism`/`provisioner` schemas on the one test DB — the tables the functional tests read (`HealthControllerTest`) exist without a second migrate invocation. (Running two `--configuration` migrates in one process is unreliable with the bundle — the second is silently ignored — which is why the histories are folded rather than run separately here.) Reference-touching tests seed and assert through `doctrine.dbal.reference_connection`.

## Snapshot invariant (orthogonal to the split)

Historical integrity is a **snapshot** property: a computed trip is frozen onto the `Stage` entity's JSONB columns (`pois`/resupply, `accommodations`, `selectedAccommodation`) at store time, and the trip-detail render (`TripDetailProvider`) reads only those columns — never the reference index. So a persisted trip renders in full even if PG-référence no longer holds those rows, or is unreachable. This is independent of the split and is pinned by a dedicated functional test that breaks the reference connection and still renders the frozen data.

## Limitations

- **A PR that modifies the reference DDL is not previewable in isolation.** Previews share the one PG-référence; they do not seed or migrate it, so a change to `reference_schema.sql` only takes effect once the shared reference DB is (re)provisioned out of band. Reference-DDL changes are validated by the test harness (which builds both histories on a throwaway DB), not by a preview.
- **The shared PG-référence is a SPOF** across all stacks. Acceptable for the beta (matches the shared-Valhalla posture); it is read-only and reproducible (`make provision`), so it is not backed up (ADR-038).

## Alternatives considered

- **Two entity managers (one per connection).** Rejected: the reference side maps no ORM entity, so a second EM adds configuration and cache surface for zero benefit. A bound DBAL connection is the whole need.
- **One shared database for everything, previews included.** Rejected: previews would read and write each other's trips, and there is no per-stack isolation. The split is the point.
- **Per-preview copy of the reference index.** Rejected: 15-25 GB per preview is unaffordable on the VM and pointless for read-only, deliberately-dated data.
- **Run the reference migrations from the app at boot.** Rejected: PG-app (per stack) must not own the shared reference schema, and the app is read-only on it. The provisioner — the only writer — owns and applies the reference DDL.

## Consequences

- Each stack gets a disposable PG-app; the heavy reference index is provisioned once and shared read-only, so a preview costs a small empty database, not a copy of France.
- The reference DDL lives in one idempotent SQL file with two executors (provisioner in prod, Doctrine migration in test) that cannot drift.
- `/api/health` reports the reference database separately (`postgres_reference`) and non-required: an unreachable reference DB degrades feature enrichment, it never takes readiness down (ADR-040).
- A physical split of PG-référence onto its own host (Ansible) is a configuration change (`REFERENCE_DATABASE_URL` / `PG*`), not a code change.

# ADR-032 — Migrations & Rollback Strategy

- **Status**: Accepted
- **Date**: 2026-05-26
- **Related**: ADR-019 (Deployment infrastructure), ADR-031 (Error tracking), Issue #493

## Context

Bike Trip Planner is moving to production on Oracle Cloud Always Free (ARM A1) orchestrated by Coolify, with deployments triggered by the `.github/workflows/deploy.yml` workflow on every push to `main` and on `v*` tags.

In this setup we need a clear policy answering two questions:

1. **When and how are Doctrine migrations executed** relative to a deployment? The image must come up with a database schema compatible with the code it contains, otherwise the very first request after a rolling restart 500s.
2. **How do we roll back** when a release is bad?

Without an explicit policy:

- Forgetting to run migrations leaves prod with a stale schema → 500s.
- Running migrations *manually* from a developer laptop creates drift between environments and a long mean-time-to-recover.
- Destructive migrations (`DROP COLUMN`, `DROP TABLE`, type changes) coupled to code changes in a single release make rollback impossible — restoring the previous image cannot restore dropped data.

## Decision

### Migrations are executed at container boot

A dedicated production entrypoint `.docker/php/entrypoint.sh` is installed in the `frankenphp_prod` Dockerfile stage. On container start it runs:

```sh
bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

…before exec'ing the underlying `docker-php-entrypoint` and the FrankenPHP CMD.

Execution is gated by the environment variable `MIGRATIONS_ON_BOOT` (default: `true` in `compose.yaml` and in the Dockerfile `ENV`). Setting it to `false` skips auto-migration, useful when:

- A migration is suspected to be problematic and we want to deploy code without touching the schema.
- We want to run a long migration manually in a controlled shell session.

The Coolify healthcheck (`/api/healthz`, ADR-031 / #486) only goes green once the entrypoint has finished — so a failing migration will keep the container unhealthy and Coolify keeps routing traffic to the previous instance.

### Destructive migrations are forbidden in a single release

A migration that drops, renames or narrows the type of a column/table is allowed **only after** the previous release stopped writing to it. The mandatory pattern is **two releases**:

1. **Release N — additive**: add the new column/table, dual-write or backfill, keep reading from the old one. No `DROP`.
2. **Release N+1 — read switch**: read from the new column, write only to the new column. Old column still present.
3. **Release N+2 — destructive**: drop the old column/table. Safe to deploy because the running code already ignores it.

This guarantees that **rolling back release N+2 → N+1 (or N+1 → N) never requires restoring data**.

### Rollback procedure

Coolify keeps the N most recent image tags pinned by SHA (we never deploy `:latest` in prod, see #493). Rolling back is:

1. Coolify UI → previous deployment → "Redeploy".
2. The previous immutable image (`ghcr.io/vincentchalamon/bike-trip-planner-*:<previous-sha>`) is pulled and started.
3. `MIGRATIONS_ON_BOOT=true` makes the previous container run `doctrine:migrations:migrate` again, which is a no-op when no migrations were added between the two SHAs (additive case) and a downgrade is **not** attempted automatically.
4. If the bad release introduced an additive migration we want to keep, do nothing else.
5. If the bad release introduced a *destructive* migration (should not happen per the policy above), restore from the most recent PostgreSQL backup — covered by a future plan, out of scope here.

A detailed playbook lives in `docs/runbooks/release-rollback.md` (created as part of the P2.3 runbooks effort).

## Consequences

### Positive

- Schema and code are always in sync at boot: no manual step, no operator drift.
- Coolify healthchecks naturally gate traffic: bad migration → container unhealthy → no traffic shifted.
- The `MIGRATIONS_ON_BOOT=false` flag provides a safety valve when we want to deploy code without touching the schema.
- Two-release destructive policy makes rollbacks safe by construction.

### Negative

- Boot is slightly slower (a few hundred ms for `doctrine:migrations:migrate` when no migration is pending).
- Two-release policy adds friction for schema cleanup work — but this is intentional and aligns with industry practice.
- A failed migration blocks the container from becoming healthy; recovery requires either fixing forward or flipping `MIGRATIONS_ON_BOOT=false` and intervening manually.

### Neutral

- Workers (`messenger:consume`) share the same image and the same `entrypoint.sh`; they skip migrations because `compose.yaml` sets `MIGRATIONS_ON_BOOT=false` on the worker service. Only the `php` service runs them, and workers wait for it to be healthy via `depends_on`.

# Database Disk Full

PostgreSQL 18 persists users, trip configurations and stages (JSONB) per ADR-022. Since the PG split (ADR-060) the app database (PG-app, service `database` of the prod stack) is separate from the shared PG-reference (`pg-reference` project). Both share the VM boot volume (Oracle Always Free: 200 GB of block storage in total) with Docker images, the Valhalla tiles and every preview stack, so disk pressure is the most likely "silent killer".

In production, run the commands below with the `dc` alias from [README.md](README.md#conventions) instead of `docker compose`.

## Symptoms

- PHP container logs: `SQLSTATE[53100]` (`disk full`) or `could not extend file`
- `/api/health` answers 503 with `deps.postgres.status = "down"` (`deps.postgres_reference` for PG-reference, which does not fail readiness)
- Writes fail (POST `/trips` returns 5xx) but reads still succeed for a few minutes
- `df -h /` over SSH shows the boot volume above 90 % (no host monitoring is deployed in beta)

## Diagnosis

Check VM disk usage first:

```bash
df -h /
docker system df
```

Open a psql session on PG-app and measure (the container knows its own user and database):

```bash
docker compose exec database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
```

```sql
SELECT pg_size_pretty(pg_database_size(current_database())) AS db_size;

SELECT relname,
       pg_size_pretty(pg_total_relation_size(c.oid)) AS total,
       pg_size_pretty(pg_relation_size(c.oid)) AS table,
       pg_size_pretty(pg_total_relation_size(c.oid) - pg_relation_size(c.oid)) AS indexes_toast
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind = 'r'
ORDER BY pg_total_relation_size(c.oid) DESC
LIMIT 10;

SELECT relname, n_dead_tup, n_live_tup
FROM pg_stat_user_tables
ORDER BY n_dead_tup DESC
LIMIT 10;
```

Check for stuck long-running queries:

```sql
SELECT pid, now() - query_start AS duration, state, query
FROM pg_stat_activity
WHERE state <> 'idle'
ORDER BY duration DESC NULLS LAST
LIMIT 10;
```

## Procedure

1. **Free obvious wins on disk** (run on the VM, not in the container):

    ```bash
    docker image prune -af          # unused images; a rollback re-pulls them from GHCR
    docker container prune -f
    sudo journalctl --vacuum-size=200M
    docker compose ls               # stale pr-<n> preview stacks each hold a PG-app volume
    ```

    Close stale PRs to reclaim their preview stacks: `teardown-preview` in `deploy.yml`
    removes the stack and its volumes when a PR is closed.

2. **Reclaim PostgreSQL bloat** on the top offender(s). Prefer non-blocking first:

    ```sql
    VACUUM (VERBOSE, ANALYZE) stage;
    VACUUM (VERBOSE, ANALYZE) trip_request;
    ```

    If bloat persists and a brief lock is acceptable (writes blocked on that table):

    ```sql
    VACUUM FULL VERBOSE stage;
    ```

    The Messenger transport is Redis-backed (`redis://.../messages`), not Doctrine,
    so there is no `messenger_messages` table to truncate here — queue pressure is
    handled in [`redis-out-of-memory.md`](./redis-out-of-memory.md).

3. **Resize the boot volume** on Oracle Cloud (last resort, requires VM reboot):
    - OCI console → Compute → Instances → select VM → Boot volume → "Edit" → raise size (free tier ceiling: 200 GB total block storage across all volumes)
    - Reboot, then check `df -h /`: the Ubuntu image grows the root partition at boot (cloud-init `growpart`). If it did not, grow it by hand (`lsblk` to find the device, then `sudo growpart <disk> <partition>` and `sudo resize2fs <root device>`)
    - After reboot, the stack comes back up via the Docker `restart: unless-stopped` policy; if not, run `dc up -d` from `/opt/bike-trip-planner`

## Verification and follow-up

- Re-check `pg_database_size(current_database())` and `df -h /`.
- Confirm `/api/health` reports `deps.postgres.status = "ok"`.
- If `VACUUM FULL` was used, capture downtime in the incident issue.
- Schedule a follow-up: enable autovacuum tuning (`autovacuum_vacuum_scale_factor`) or add an archival job for old trip computations.
- If Oracle volume was resized, update the infrastructure note in ADR-019.

## References

- ADR-019 — Deployment infrastructure (Oracle Always Free volume limits)
- ADR-022 — Persistent storage strategy (PostgreSQL JSONB)

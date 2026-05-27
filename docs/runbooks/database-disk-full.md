# Database Disk Full

PostgreSQL 18 persists trip configurations and stages (JSONB) per ADR-022. The boot volume of the Oracle Always Free VM is 150 GB shared with Docker, Valhalla tiles, Overpass DB, and Ollama models — disk pressure is the most likely "silent killer".

## Symptômes

- PHP container logs: `SQLSTATE[53100]` (`disk full`) or `could not extend file`
- `/api/health` reports `database: 503`
- Writes fail (POST `/trips` returns 5xx) but reads still succeed for a few minutes
- Coolify dashboard shows boot volume > 90 % usage

## Diagnostic

Check VM disk usage first:

```bash
df -h /
docker system df
```

Open a psql session and measure:

```bash
docker compose exec database psql -U app -d app
```

```sql
SELECT pg_size_pretty(pg_database_size('app')) AS db_size;

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

## Procédure

1. **Free obvious wins on disk** (run on the VM, not in the container):

   ```bash
   docker image prune -af
   docker container prune -f
   journalctl --vacuum-size=200M
   ```

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
   - SSH into VM and run `sudo /usr/libexec/oci-growfs -y` to extend the filesystem
   - Coolify auto-restarts the stack after reboot

## Post-action

- Re-check `pg_database_size('app')` and `df -h /`.
- Confirm `/api/health` reports `database: ok`.
- If `VACUUM FULL` was used, capture downtime in the incident issue.
- Schedule a follow-up: enable autovacuum tuning (`autovacuum_vacuum_scale_factor`) or add an archival job for old trip computations.
- If Oracle volume was resized, update the infrastructure note in ADR-019.

## References

- ADR-019 — Deployment infrastructure (Oracle Always Free volume limits)
- ADR-022 — Persistent storage strategy (PostgreSQL JSONB)

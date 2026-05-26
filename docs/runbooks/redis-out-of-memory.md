# Redis Out of Memory

Redis (`redis:8-alpine`) hosts three workloads per ADR-022: Messenger transport (`messages`, `failed`), trip state cache (`cache.trip_state`), and external API caches (OSM 24 h TTL, weather 3 h TTL). An OOM event is usually caused by a runaway cache or a Messenger queue backlog.

## Symptômes

- PHP logs: `OOM command not allowed when used memory > 'maxmemory'`
- `/api/health` reports `redis: 503`
- Workers crash on `LPUSH` to `messages` transport
- Mercure events still flow (Mercure does not use Redis) but trip computations stall

## Diagnostic

```bash
docker compose exec redis redis-cli INFO memory
docker compose exec redis redis-cli CONFIG GET maxmemory
docker compose exec redis redis-cli CONFIG GET maxmemory-policy
```

Key-by-key size sampling:

```bash
docker compose exec redis redis-cli --bigkeys
docker compose exec redis redis-cli MEMORY STATS | head -40
```

Inspect each logical bucket:

```bash
docker compose exec redis redis-cli LLEN messages
docker compose exec redis redis-cli LLEN failed
docker compose exec redis redis-cli --scan --pattern 'cache.trip_state:*' | wc -l
docker compose exec redis redis-cli --scan --pattern 'osm:*' | wc -l
docker compose exec redis redis-cli --scan --pattern 'weather:*' | wc -l
```

## Procédure

1. **Drain the failed transport** (often the largest unbounded queue):

   ```bash
   docker compose exec php bin/console messenger:failed:show
   docker compose exec php bin/console messenger:failed:remove --all
   ```

2. **Selective flush** of an external API cache (preserves Messenger state):

   ```bash
   docker compose exec php bin/console cache:pool:clear cache.app
   ```

   Or targeted scan + delete from the Redis CLI:

   ```bash
   docker compose exec redis redis-cli --scan --pattern 'osm:*' | \
     xargs -r docker compose exec -T redis redis-cli DEL
   ```

3. **Full reset** of Messenger transports + trip state (drops in-flight computations):

   ```bash
   make flush-queue
   ```

4. **Verify eviction policy** (per ADR-022, transient caches should use `allkeys-lru`):

   ```bash
   docker compose exec redis redis-cli CONFIG SET maxmemory-policy allkeys-lru
   ```

   Persist the change in `compose.prod.yaml` (Redis `command:`) — `CONFIG SET` does not survive a restart.

5. **Raise `maxmemory`** as a stopgap if the VM has free RAM:

   ```bash
   docker compose exec redis redis-cli CONFIG SET maxmemory 512mb
   ```

   Then update `compose.prod.yaml` and redeploy via Coolify so the change is persisted.

## Post-action

- `redis-cli INFO memory` → `used_memory_peak_perc` back under 70 %.
- `/api/health` green for two consecutive Uptime Kuma probes.
- File a follow-up issue if the same bucket reappeared (likely a TTL leak in an HTTP client cache key).
- Confirm `compose.prod.yaml` and infra ADR reflect any persisted `maxmemory` / policy change.

## References

- ADR-005 — External API caching (TTLs)
- ADR-022 — Persistent storage strategy (Redis sizing and eviction policy)

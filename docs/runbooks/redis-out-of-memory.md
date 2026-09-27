# Redis Out of Memory

Redis (`redis:8-alpine`) hosts, per ADR-022: the Messenger transport (streams `messages` and
`failed`), the worker heartbeat, the rate limiters and locks, and the Symfony cache pools
(`cache.trip_state`, the external API caches `cache.osm` 24 h, `cache.weather` 3 h,
`cache.route_fetch`, `cache.routing`, and the short-lived OAuth/MCP pools). It runs with
`--maxmemory 384mb --maxmemory-policy volatile-lru` under a 512M container limit
(`compose.yaml`): keys with a TTL (the caches) are evicted under pressure, keys without one
(the Messenger streams) never are. An OOM error therefore means the non-evictable data alone
fills `maxmemory`, usually a Messenger backlog or a huge `failed` stream.

In production, run the commands below with the `dc` alias from
[README.md](README.md#conventions) instead of `docker compose`.

## Symptoms

- PHP logs: `OOM command not allowed when used memory > 'maxmemory'`
- `/api/health` answers 503 with `deps.redis.status = "down"`
- Dispatching a message fails (`XADD` on the `messages` stream), so new computations are refused
- Mercure events still flow (Mercure does not use Redis) but trip computations stall

## Diagnosis

```bash
docker compose exec redis redis-cli INFO memory
docker compose exec redis redis-cli CONFIG GET maxmemory
docker compose exec redis redis-cli CONFIG GET maxmemory-policy
docker compose exec redis redis-cli INFO stats | grep evicted_keys
```

Find what holds the memory:

```bash
docker compose exec redis redis-cli --bigkeys
docker compose exec redis redis-cli --memkeys
docker compose exec redis redis-cli MEMORY STATS | head -40
```

Measure the non-evictable part, the Messenger streams (`XLEN` counts entries, `XINFO GROUPS`
shows what the consumer group still owes):

```bash
docker compose exec redis redis-cli XLEN messages
docker compose exec redis redis-cli XINFO GROUPS messages
docker compose exec redis redis-cli XLEN failed
```

Cache pool keys are namespaced by a hash of the pool name, not by readable prefixes such as
`osm:*`, so rely on `--bigkeys` / `--memkeys` rather than pattern scans.

## Procedure

1. **Drain the failed transport** (often the largest unbounded, non-evictable data). Look at
    it first, copy anything worth keeping into the incident issue:

    ```bash
    docker compose exec php bin/console messenger:failed:show
    docker compose exec php bin/console messenger:failed:remove --all
    ```

2. **Clear the external API caches** (preserves Messenger state):

    ```bash
    docker compose exec php bin/console cache:pool:clear \
      cache.osm cache.weather cache.route_fetch cache.routing
    ```

    `cache.app` (the Symfony default pool) does **not** include these caches; clearing it
    would not reclaim their memory.

3. **Full reset** of Messenger transports + trip state (drops in-flight computations; users
    retry from the PWA):

    ```bash
    make flush-queue
    ```

    In production there is no Makefile target to call; run its three commands with `dc`:
    `bin/console messenger:stop-workers`, `bin/console app:messenger:clear --all`,
    `bin/console cache:pool:clear cache.trip_state`.

4. **Do not switch to `allkeys-lru`.** It would let Redis evict the Messenger streams and
    silently lose queued work. `volatile-lru` is the intended policy.

5. **Raise `maxmemory`** as a stopgap only if the container limit leaves room (the default
    384 MB sits under a 512M limit to leave headroom for Redis overhead):

    ```bash
    docker compose exec redis redis-cli CONFIG SET maxmemory 448mb
    ```

    `CONFIG SET` does not survive a restart. To make it permanent, change `--maxmemory` and
    the `redis` memory limit together in `compose.yaml`, then ship a release.

## Verification and follow-up

- `redis-cli INFO memory` → `used_memory_peak_perc` back under 70 %.
- `/api/health` reports `deps.redis.status = "ok"` again.
- File a follow-up issue if the same bucket reappeared (likely a TTL leak in an HTTP client cache key).
- Confirm `compose.yaml` reflects any persisted `maxmemory` change.

## References

- ADR-005 — External API caching (TTLs)
- ADR-022 — Persistent storage strategy (Redis sizing and eviction policy)

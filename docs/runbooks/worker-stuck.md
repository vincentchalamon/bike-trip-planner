# Worker Stuck

Messenger workers process trip computations asynchronously over Redis (transports `async` and `failed`; the underlying Redis streams are named `messages` and `failed`). A stuck worker blocks Mercure SSE updates and freezes the PWA on "Computing…".

## Symptômes

- `/api/health` answers 503 with `deps.messenger.status = "down"` and `workers_alive = 0` (ADR-075)
- PWA stays on a `pending` computation status (no Mercure event for > 2 min)
- `failed` transport depth growing
- `worker` container logs show repeated `Retrying message` or `Worker reached message limit`

## Diagnostic

Start with the readiness probe — it is what now sees the async tier:

```bash
curl -s https://<host>/api/health | jq .deps.messenger
```

`workers_alive` counts consumers that beat within the last 5 minutes. `queue_depth` is the
undelivered backlog on `async`, `failed_depth` the dead letters. **Neither depth carries a
verdict**: only `workers_alive == 0` turns readiness red, because a depth threshold would be
red whenever the system is merely busy.

Then inspect Messenger state from the PHP container:

```bash
make php-shell
bin/console messenger:stats
bin/console messenger:failed:show
```

Identify how many worker processes are actually alive:

```bash
docker compose ps worker
docker compose exec worker ps -eo pid,cmd | grep messenger:consume
```

Inspect Redis directly to confirm queue depth. The transport uses **streams**, so `LLEN`
reports nothing — `XLEN` counts entries ever written, and `XINFO GROUPS` gives the backlog
still owed to the consumer group:

```bash
docker compose exec redis redis-cli XLEN messages
docker compose exec redis redis-cli XINFO GROUPS messages
docker compose exec redis redis-cli XLEN failed
```

Pull the latest 200 stderr lines:

```bash
docker compose logs --tail=200 worker
```

## Procédure

1. **Retry transient failures** — if the `failed` transport contains messages that should succeed (external API blip). Nothing consumes `failed` on its own, so it only ever drains here:

    ```bash
    docker compose exec php bin/console messenger:failed:retry --force
    ```

2. **Restart workers gracefully** — sends `SIGTERM`, current message finishes, Redis visibility timeout prevents double-processing:

    ```bash
    docker compose exec php bin/console messenger:stop-workers
    docker compose restart worker
    ```

3. **Drop poison messages** — only after copying the payload to the incident issue:

    ```bash
    docker compose exec php bin/console messenger:failed:remove <id>
    ```

4. **Last resort — full flush** (drops in-flight trip computations; users must retry from the PWA):

    ```bash
    make flush-queue
    ```

    This stops workers, runs `app:messenger:clear --all`, and purges the `cache.trip_state` pool.

## Post-action

- Verify `bin/console messenger:stats` reports a draining `async` queue and an empty `failed` transport.
- `curl -s .../api/health | jq .deps.messenger` — `workers_alive` back above zero, `failed_depth` at zero.
- If a poison message was removed, link the payload + `request_id` (correlation ID stamp) to the incident issue.
- If recurrence within 24 h, escalate to P1 and open a fix issue against the responsible `MessageHandler`.

## References

- ADR-005 — Orchestration, optimization, and caching of external APIs
- ADR-022 — Persistent storage strategy (Redis as Messenger transport)
- ADR-075 — Readiness covers the consumers, not just the servers
- `Makefile` targets `flush-queue` and `perf-db-report`

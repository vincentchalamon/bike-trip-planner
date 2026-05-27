# Worker Stuck

Messenger workers process trip computations asynchronously over Redis (transports `messages` and `failed`). A stuck worker blocks Mercure SSE updates and freezes the PWA on "Computing…".

## Symptômes

- PWA stays on a `pending` computation status (no Mercure event for > 2 min)
- Issue auto-created with `severity-p2` (or `severity-p1` if all workers idle while queue depth > 0)
- `failed` transport depth growing
- `php` container logs show repeated `Retrying message` or `Worker reached message limit`

## Diagnostic

Open a shell in the PHP container and inspect Messenger state:

```bash
make php-shell
bin/console messenger:stats
bin/console messenger:failed:show
```

Identify how many workers are actually alive:

```bash
docker compose ps php
docker compose exec php ps -eo pid,cmd | grep messenger:consume
```

Inspect Redis directly to confirm queue depth:

```bash
docker compose exec redis redis-cli LLEN messages
docker compose exec redis redis-cli LLEN failed
```

Pull the latest 200 stderr lines:

```bash
docker compose logs --tail=200 php
```

## Procédure

1. **Retry transient failures** — if the `failed` transport contains messages that should succeed (external API blip):

   ```bash
   docker compose exec php bin/console messenger:failed:retry --force
   ```

2. **Restart workers gracefully** — sends `SIGTERM`, current message finishes, Redis visibility timeout prevents double-processing:

   ```bash
   docker compose exec php bin/console messenger:stop-workers
   docker compose restart php
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

- Verify `bin/console messenger:stats` reports a draining `messages` queue and an empty `failed` transport.
- Watch Uptime Kuma `/api/health` — should be green within one probe cycle.
- If a poison message was removed, link the payload + `request_id` (correlation ID stamp) to the incident issue.
- If recurrence within 24 h, escalate to P1 and open a fix issue against the responsible `MessageHandler`.

## References

- ADR-005 — Orchestration, optimization, and caching of external APIs
- ADR-022 — Persistent storage strategy (Redis as Messenger transport)
- `Makefile` target `flush-queue`

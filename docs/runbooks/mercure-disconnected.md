# Mercure Disconnected

Mercure is embedded in the `php` FrankenPHP container (Caddy + Mercure + PHP, ADR-037) and runs
protocol 1.0. It pushes computation status updates over SSE. Mercure is the invalidation
channel, not the source of truth (ADR-065): a client that misses events resynchronises on its
next read, so a disconnected hub costs latency, not data.

In production, run the commands below with the `dc` alias from
[README.md](README.md#conventions) instead of `docker compose`.

## Symptoms

- The trip page never receives live updates; stages only appear after a reload.
- Browser devtools: `EventSource` errors on `/.well-known/mercure`, or 401/403 responses.
- Backend logs: `Mercure publish failed for trip ...`.
- `/api/health` reports `deps.mercure.status = "down"`. Mercure is not a required dependency,
  so the overall status stays `ok`.

## Diagnosis

```bash
docker compose ps php
docker compose logs --tail=200 php | grep -i mercure
```

Probe the hub from inside the container. Without a subscription and a token the hub answers
with a 4xx; any HTTP status means it is up, a connection error means it is not:

```bash
docker compose exec php curl -sS -o /dev/null -w '%{http_code}\n' http://php/.well-known/mercure
```

Check the Mercure configuration the container resolved. `MERCURE_JWT_KEY` feeds
`MERCURE_JWT_SECRET` (Symfony side) and `MERCURE_PUBLISHER_JWT_KEY` /
`MERCURE_SUBSCRIBER_JWT_KEY` (hub side), so they must all be identical. Compare hashes, not
values; the three lines must match:

```bash
docker compose exec php sh -c 'for v in "$MERCURE_JWT_SECRET" "$MERCURE_PUBLISHER_JWT_KEY" "$MERCURE_SUBSCRIBER_JWT_KEY"; do printf %s "$v" | sha256sum; done'
docker compose exec php sh -c 'echo "$MERCURE_URL $MERCURE_PUBLIC_URL $MERCURE_ISSUER"'
```

Updates are private. The PWA gets its subscriber cookie from `GET /trips/{id}/detail` and
subscribes with `?match=/trips/{id}` (protocol 1.0 replaced `?topic=` with `?match=`). To test
from the browser devtools console on the PWA host, after opening the trip once:

```javascript
new EventSource('/.well-known/mercure?match=' + encodeURIComponent('/trips/<trip-id>'), { withCredentials: true })
  .onmessage = (e) => console.log(e)
```

## Procedure

1. **Restart the hub.** Mercure is embedded in `php`, so restart `php`:

    ```bash
    docker compose restart php
    ```

2. **If the key was changed or is wrong**, fix `vault_mercure_jwt_key` in Ansible Vault (at
   least 32 bytes, `openssl rand -hex 32`), re-render and reload the stack (see
   [secrets-rotation.md](secrets-rotation.md#common-steps)). The `php` entrypoint refuses to
   boot on an empty, default or too-short key (SEC-004), so a running container always has a
   syntactically valid one; a mismatch can only come from a hand-edited environment.

3. **Reset the client side.** The Mercure client (`pwa/src/lib/mercure/client.ts`, used by
   `pwa/src/hooks/use-mercure.ts`) re-fetches the trip detail to renew its subscriber cookie
   and reconnects with backoff. After a hub restart, a page reload re-subscribes immediately.

## Verification and follow-up

- `/api/health` reports `deps.mercure.status = "ok"`.
- Open the PWA, start a trip computation, and watch the SSE events arrive without a reload.
- If the key was rotated, record the date in the incident issue.

## References

- ADR-001 - Global architecture (Mercure as SSE transport)
- ADR-037 - Dev/prod Docker convergence (FrankenPHP with embedded Caddy + Mercure)
- ADR-065 - Mercure as invalidation channel, not source of truth
- `api/config/packages/mercure.php`, `api/src/Mercure/TripUpdatePublisher.php`

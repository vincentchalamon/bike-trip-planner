# Mercure Disconnected

Mercure (embedded in the `php` FrankenPHP container) pushes computation status updates over SSE. A disconnected hub leaves the PWA stuck on `pending` even though workers complete the computation.

## Symptômes

- PWA toast "Reconnecting to live updates…" repeats in a loop
- Browser console: `EventSource` errors on `/.well-known/mercure`
- Backend logs: `Mercure publish failed: 401 Unauthorized` or `connection refused`
- `/api/health` reports `mercure: 503`

## Diagnostic

```bash
docker compose ps php
docker compose logs --tail=200 php | grep -i mercure
```

Probe the hub directly (publisher token required; healthz endpoint preferred when exposed):

```bash
docker compose exec php curl -sS -o /dev/null -w '%{http_code}\n' http://php/.well-known/mercure
```

Validate the JWT keys configured on the PHP side (`MERCURE_JWT_SECRET`, publisher key):

```bash
docker compose exec php env | grep -i mercure
```

From a browser devtools console on the PWA host, confirm the subscriber JWT is fresh:

```javascript
new EventSource('/.well-known/mercure?topic=' + encodeURIComponent('https://example.com/trip/test'))
  .onmessage = (e) => console.log(e)
```

## Procédure

1. **Restart the hub** — Mercure is embedded in the `php` edge, so restart `php` (idempotent):

   ```bash
   docker compose restart php
   ```

2. **If the JWT secret rotated**, regenerate it across services. The publisher key lives in the PHP container, the subscriber key in the PWA build. Both must share `MERCURE_JWT_SECRET`:

   ```bash
   docker compose exec php php -r 'echo bin2hex(random_bytes(32))."\n";'
   ```

   Update the Coolify environment for `php` and `pwa`, then redeploy. Mismatched keys produce silent 401s with no obvious symptom beyond reconnect loops.

3. **Reset reconnect state on the PWA** — the Mercure client (`pwa/src/lib/mercure/client.ts`) backs off exponentially. After a hub restart, ask users to refresh; the `use-mercure` hook will re-subscribe automatically.

4. **Check the edge** — the Caddy reverse-proxy and the Mercure hub both run inside the `php` (FrankenPHP) container. If routing looks wrong:

   ```bash
   docker compose logs --tail=100 php | grep -i mercure
   docker compose restart php
   ```

## Post-action

- `/api/health` reports `mercure: ok`.
- Open the PWA, start a trip computation, observe the SSE event arriving (no toast).
- If JWT keys were rotated, store the rotation date in the incident issue and schedule the next rotation (90 d).
- If Caddy was the culprit, capture the misrouting line from the logs into the incident.

## References

- ADR-001 — Global architecture (Mercure as SSE transport)
- ADR-019 — Deployment infrastructure (Caddy reverse proxy)

# Mobile Push Notifications (FCM)

Configuration and troubleshooting for server-pushed notifications delivered to
the native app via Firebase Cloud Messaging (FCM) — the out-of-app channel that
wakes a backgrounded/closed app (ADR-058). Distinct from the in-app local
triggers scheduled on-device (`mobile/src/notifications/local.*`,
`plan.ts`) — this runbook only covers the **server → device** leg.

## Symptômes

- A device never receives a push (weather/safety, analysis-done, or
  zone-opening) despite being registered and eligible.
- Registering a device token fails (`POST /users/me/device-tokens` returns
  non-2xx), or the app silently never registers one.
- The `SendPushNotification` Messenger message piles up in the `failed`
  transport.
- A notification arrives but taps into the wrong screen, or a duplicate
  notification appears while the app is open and already showing the live
  update via Mercure.

## Diagnostic

### Server side

1. **Is the credential configured?** `App\Push\FcmCredentials` reads
   `FCM_SERVICE_ACCOUNT_JSON` lazily — the container boots fine with it empty,
   but any send attempt raises `RuntimeException` ("must be configured to send
   push notifications"), which the worker surfaces as a failed message, not a
   silent drop:

    ```bash
    make php-shell
    bin/console debug:container --env-vars | grep FCM_SERVICE_ACCOUNT_JSON
    ```

    In dev/recette this is expected to be empty (ADR-058 leaves it unset on
    purpose so unrelated endpoints keep working); in **production** an empty
    value is the bug — check the Coolify secret and that `compose.yaml` (not
    only `compose.dev.yaml`) passes it through to **both** `php` and `worker`
    (project convention, see `CLAUDE.md` "New runtime secret?").

2. **Check the failed transport** for stuck `SendPushNotification` messages:

    ```bash
    docker compose exec php bin/console messenger:failed:show
    ```

    A repeated failure with a 401/403 from `oauth2.googleapis.com` or
    `fcm.googleapis.com` points at a stale or wrong service-account key
    (revoked in the Firebase console, or copy-pasted with a missing field —
    `FcmCredentials` validates `project_id`/`client_email`/`private_key` are
    present, so a truncated JSON fails fast with a named field).

3. **Is the user's token actually registered?**

    ```bash
    docker compose exec php bin/console dbal:run-sql \
      "SELECT id, platform, created_at FROM device_token WHERE \"user_id\" = '<uuid>'"
    ```

    No row: the client never registered (see client-side checks below) or the
    token was pruned as dead (see Post-action).

4. **Is the category opted in?** `notification_preference` holds overrides;
   `zoneOpening` is opt-in/default-OFF — a user who never enabled it will never
   receive it by design, not by bug. `weatherSafety` and `analysisDone` default
   ON, so an absent row there means "receiving."

5. **`analysisDone` specifically**: it is only sent when **no Mercure
   subscriber is live** on `/trips/{id}` (if the rider is watching, they already
   get `TRIP_READY` over SSE — a push would be redundant). If a push is
   unexpectedly missing right after a computation finishes, check whether the
   app still held an open SSE subscription (e.g. app briefly backgrounded, not
   fully closed) — that is the intended no-push path, not a bug. The liveness
   check itself fails open on a hub error (see
   [mercure-disconnected.md](mercure-disconnected.md) if the hub itself looks
   unhealthy).

### Client side

1. **OS permission granted?** `fetchDeviceToken()` in
   `mobile/src/notifications/push.ts` returns `null` (silent no-op, nothing
   sent to the backend) whenever permission is denied or
   `getDevicePushTokenAsync()` throws (no Google Play Services, custom ROM,
   some emulators). Check the device's app notification settings.

2. **Firebase config bundled into the build.** `getDevicePushTokenAsync()`
   needs the app's Android build linked to a Firebase project (normally via a
   `google-services.json` referenced from `app.json`/`app.config.js`). **No
   such file is currently committed or wired in this repo** — treat it the same
   way as `mobile/credentials.json` for release signing: a locally-held,
   gitignored artifact, not (yet) part of the tracked build config. If device
   token registration never fires on a real build, this is the first thing to
   check, not a code regression.

3. **Registration call itself.** `registerDeviceToken()` posts to
   `${API_BASE_URL}/users/me/device-tokens` and swallows network errors
   (fire-and-forget from the auth store) — check the backend access log /
   Caddy logs for the POST rather than expecting a client-side error, and
   confirm `EXPO_PUBLIC_API_URL` points at a host the device can actually reach
   (see `mobile/README.md`, "How the installed app reaches the API").

4. **Routing to the wrong screen.** Tap-through routing lives in
   `mobile/src/notifications/push-routing.ts` /
   `use-push-routing.ts`, keyed off the `category` /
   `data` payload the backend attaches (`App\Enum\NotificationCategory`) — a
   mismatch here is a mapping bug in that file, not a delivery problem.

## Procédure

1. **Configure the credential** (once per environment): in the Firebase
   console, generate a service-account private key (JSON) for the project, and
   set it as `FCM_SERVICE_ACCOUNT_JSON` in the Coolify app's secrets (production)
   or a local `.env` (recette/dev testing). It must reach **both** `php` and
   `worker` — already wired as a passthrough in `compose.yaml` (lines defining
   `FCM_SERVICE_ACCOUNT_JSON: "${FCM_SERVICE_ACCOUNT_JSON:-}"`); do not
   re-invent this in `compose.dev.yaml` alone.

2. **Retry a transient failure** (e.g. after fixing the credential):

    ```bash
    docker compose exec php bin/console messenger:failed:retry --force
    ```

3. **Trigger a push manually** to smoke-test end-to-end delivery:

    ```bash
    # Weather/safety digest for today's stages
    docker compose exec php bin/console app:notifications:weather-safety --day=today

    # Zone-opening announcement (opt-in users only)
    docker compose exec php bin/console app:notifications:zone-opened <slug>
    ```

4. **Force a re-registration on device** (after fixing the client-side cause):
   log out and back in — `registerDeviceToken()` runs on login and is
   idempotent (upsert by token).

## Post-action

- Confirm `messenger:stats` shows the `SendPushNotification` queue draining and
  `failed` empty.
- FCM reports a stale token as `UNREGISTERED`/`NOT_FOUND` (HTTP 404);
  `FcmClient` collects those and the handler deletes the row via
  `DeviceTokenRepository::deleteByTokens()` — this is the **only** pruning
  path, there is no separate reaper. If a device keeps re-appearing as dead
  right after a fresh install, suspect it reinstalled with a new token while
  the old row lingered (expected — the old row prunes itself on the next send
  attempt, not instantly).
- If the root cause was a credential rotation, update the team's secret store,
  not just the running container's env (a Coolify redeploy without the secret
  reverts it to empty).

## References

- [ADR-058](../adr/adr-058-push-fcm.md) — FCM transport, categories, opt-in,
  dead-token pruning
- `api/src/Push/FcmClient.php`, `FcmCredentials.php` — send + auth
- `mobile/src/notifications/push.ts` — device token registration/rotation
- `docs/runbooks/mercure-disconnected.md` — the SSE liveness check
  `analysisDone` depends on
- `CLAUDE.md` — "New runtime secret? Wire it into `compose.yaml`, not just
  dev/recette."

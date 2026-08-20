# ADR-058: Push Notifications via Firebase Cloud Messaging (HTTP v1)

- **Status:** Accepted
- **Date:** 2026-08-19
- **Depends on:** ADR-011 (Security — SSRF Prevention), ADR-022 (Persistent Storage Strategy), ADR-023 (Authentication Strategy), ADR-053 (Mobile Strategy — Dedicated Native App)

## Context and Problem Statement

The native app (ADR-053) needs to reach the rider when the app is backgrounded or
closed: an in-ride safety alert, a stage recomputed while offline, an opened-zone
announcement. Mercure SSE (ADR-056) only delivers while a subscription is live, so
it cannot wake a suspended app. Both target platforms (Android, iOS) deliver
out-of-app notifications through **Firebase Cloud Messaging (FCM)** — on iOS, FCM
is the documented bridge to APNs. We need one server-side channel that fans a
notification out to every device a user registered.

The device side already exists: `App\Entity\DeviceToken` (epic #1051, #1122)
stores one row per physical FCM token, globally unique, bound to a single account,
registered/unregistered through `/account/device-tokens`. What is missing is the
**sender**: how the backend authenticates to FCM, delivers a message, and reacts
to a token FCM reports as dead.

## Decision

Send through the **FCM HTTP v1 API** from an async Messenger handler, authenticated
with an OAuth2 service-account flow, over host-locked scoped HTTP clients.

- **FCM HTTP v1, not the legacy API.** The legacy `fcm.googleapis.com/fcm/send`
  server-key API is deprecated and shut down. HTTP v1
  (`POST /v1/projects/{projectId}/messages:send`) is the supported surface: one
  message per request, structured `notification` + `data` payload, per-token
  delivery.
- **OAuth2 JWT-bearer service-account auth.** Credentials are a Firebase
  service-account JSON key. `App\Push\FcmClient` signs a short-lived RS256 JWT
  (`openssl_sign`, no third-party JWT library), exchanges it at
  `oauth2.googleapis.com/token` for an access token (cached in-process until just
  before expiry), and presents that token as a bearer to the send endpoint.
- **Host-locked scoped clients (ADR-011).** The token endpoint and the send
  endpoint each get a dedicated `scoped_client` (`google_oauth.client`,
  `fcm.client`), `max_redirects: 0` (SEC-007 — neither endpoint legitimately
  redirects, so a 3xx must never pivot to an internal host). The project id folded
  into the send path is a trusted server-side value, never a user URL.
- **Async through Messenger.** `App\Message\SendPushNotification` (plain DTO:
  title, body, optional `userId`, optional explicit token list, `data` map,
  `category`) is routed to the existing `async` transport. The handler resolves
  the recipient set (union of the explicit tokens and every token registered by
  `userId`), sends, and never blocks a request thread.
- **Dead-token pruning at the source of truth.** FCM reports a stale token as
  HTTP 404 (`UNREGISTERED` / `NOT_FOUND`). `FcmClient` collects those tokens and
  the handler deletes them via `DeviceTokenRepository::deleteByTokens()`, so a
  device that uninstalled or rotated its token stops being targeted. This is the
  only self-healing path — there is no separate reaper.
- **Fail-closed credentials (like `AccessRequestHmacService`).** The service
  account is read from `FCM_SERVICE_ACCOUNT_JSON`. `App\Push\FcmCredentials`
  parses it **lazily**: an absent or malformed key raises a clear error only when a
  push is actually attempted, so the container still boots and unrelated endpoints
  (device-token registration) keep working with no key set. Production MUST provide
  the key; dev/recette leave it empty on purpose. The passthrough is wired into
  **both** `php` and `worker` in `compose.yaml` (the iso-prod base Coolify reads),
  so it is not silently empty in prod.

## Data model — device token

`DeviceToken` is unchanged by this ADR (introduced in #1122): `id` (UUID v7),
`user` (ManyToOne, `ON DELETE CASCADE`), `token` (unique), `platform`
(`android` / `ios`), `createdAt` (UTC). One row per physical token; re-registering
a token held by another account reassigns it. Account erasure cascades the rows;
FCM-side pruning removes tokens the transport reports dead.

## Categories, opt-in, and permission (planned — #1124)

This ADR lands the transport; notification **categories** and their user-facing
controls are #1124. The message already carries a `category` field (folded into
the FCM `data` payload) so the client can route to the right Android channel / iOS
category, but the catalogue of categories, the per-category opt-in, and the
**opt-in per opened zone** (a rider subscribes to announcements for the zones they
ride) are deferred to that issue.

- **Permission is the device's, not the server's.** The OS prompt (POST_NOTIFICATIONS
  on Android 13+, the iOS authorization prompt) is requested client-side; the
  backend only ever sends to a token the client chose to register. A user who
  never grants permission has no token row, so is never targeted.
- **RGPD.** A device token is personal data (it identifies a device bound to an
  account). It is stored only after the user registers it, deleted on unregister,
  pruned when FCM reports it dead, and cascade-deleted on account erasure
  (ADR — RGPD account erasure = immediate anonymisation). No notification content
  is persisted server-side beyond the transient Messenger envelope.

## Alternatives considered

- **A push library (`kreait/firebase-php`, `google/auth`).** Rejected for now: the
  v1 flow we need is a JWT sign + two HTTP POSTs. A library would pull its own
  Guzzle client, bypassing the mandated scoped-client SSRF discipline (ADR-011),
  to save ~30 lines of `openssl_sign` + base64url. Not worth the dependency and the
  SSRF exception. Revisit if we need topic messaging, condition targeting, or the
  batch APIs.
- **Web Push (VAPID) instead of FCM.** Rejected: the product is a native app
  (ADR-053, the PWA was removed). Native delivery on iOS goes through APNs, for
  which FCM is the documented bridge; a second Web Push stack would be dead weight.
- **Synchronous send in the request thread.** Rejected: FCM latency and per-token
  fan-out would block API responses, and a transient FCM error would surface as a
  request failure. Messenger gives retry + failure transport for free (ADR-043).

## Consequences

- The backend can wake a suspended app for safety and trip-state events, reusing
  the existing async worker pool and the device-token store.
- Dead tokens are pruned as a side effect of sending, so the store self-heals with
  no reaper cron.
- A new runtime secret (`FCM_SERVICE_ACCOUNT_JSON`) must be provisioned in prod; if
  absent, pushes fail closed (worker error, Messenger retry then dead-letter) while
  the rest of the API is unaffected.
- Categories, per-category opt-in, and per-zone subscription are still to build
  (#1124); this ADR is the transport they will sit on.

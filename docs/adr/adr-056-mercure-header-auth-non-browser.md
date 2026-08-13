# ADR-056: Mercure Header-Auth for Non-Browser SSE Clients

- **Status:** Accepted
- **Date:** 2026-08-13
- **Depends on:** ADR-023 (Authentication Strategy), ADR-038 (Hide Forbidden as Not Found), ADR-053 (Mobile Strategy — Dedicated Native App)

## Context and Problem Statement

Trip computations run asynchronously and stream their results to the client over
Mercure SSE. On the web, the browser subscribes to the hub with an HttpOnly
`mercureAuthorization` cookie: the per-trip subscriber JWT is minted by
`App\Mercure\MercureTokenIssuer` (HS256, claim `mercure.subscribe: ["/trips/{id}"]`,
1 h TTL) and set as a cookie by `App\Mercure\MercureSubscriberListener` on the
trip create/access responses. Keeping the token in an HttpOnly cookie is
deliberate — it never reaches JavaScript, closing an XSS exfiltration path.

The native app (ADR-053) has the same live-trip requirement but **cannot read
that cookie**: React Native has no browser cookie jar, and its `EventSource`
polyfill (`react-native-sse`) subscribes with request headers, not cookies. The
spike (#1011, `docs/mobile-mercure-auth.md`) confirmed the hub itself is *not*
cookie-only — the embedded Caddy Mercure module (`subscriber_jwt` + `anonymous`)
authenticates a subscriber via the cookie **or** an `Authorization: Bearer` header
**or** an `?authorization=` query parameter, verified against the live dev hub
with a plain `curl` and no cookie. So no hub change is needed. The gap is
delivery: the mobile client has no way to *obtain* the subscriber token, because
it is only ever handed out as the cookie.

## Decision

Add a **readable-body endpoint** that delivers the same per-trip subscriber JWT to
any authenticated client, and have the native client present it as an
`Authorization: Bearer` header to the hub. The web's cookie posture is untouched.

- **New resource `GET /trips/{id}/mercure-token`.** `App\ApiResource\MercureToken`
  (provider `App\State\MercureTokenProvider`) returns `{ token }` in the response
  body. The token is minted by the same `MercureTokenIssuer`, signed with the same
  HMAC secret, and scoped to `/trips/{id}` for 1 h — identical to the cookie
  token, just delivered through a channel a non-browser client can read.
- **Same object-level authorization as `/detail`.** The operation is guarded by
  `is_granted('TRIP_VIEW', ...)`, so a non-owner is masked as **404, not 403**
  (ADR-038, `HideForbiddenAsNotFoundListener`). The token is only ever surfaced to
  a caller the voter already granted.
- **Header over query parameter on the client.** React Native sends the token as
  `Authorization: Bearer <jwt>` (supported by `react-native-sse`'s custom
  headers). The `?authorization=` query parameter is the documented fallback if a
  platform strips the header — it is redacted from Caddy access logs — but the
  header is preferred so the token stays out of URLs.
- **No client detection, no cookie for mobile.** Consistent with the client-
  agnostic API (ADR-023): the backend does not sniff who is calling. The web keeps
  reading its HttpOnly cookie; the mobile client fetches the body token and holds
  it only in memory for the lifetime of the subscription.

## Consequences

- The mobile app gets real-time trip updates with the same security properties as
  the web: a short-lived, per-trip, owner-scoped subscriber token, never exposed
  to a non-owner (masked 404).
- Two delivery channels for one token (cookie for the browser, body for everyone
  else). They share the issuer and secret, so there is no second token lifecycle
  to maintain — only a second way to read the same JWT.
- The earlier Capacitor-era `X-Mercure-Token` response header (a WebView-specific
  hack, ADR-023) stays removed; this resource supersedes that need without
  reintroducing client detection.
- The subscriber token remains short-lived (1 h). A subscription that outlives it
  must re-fetch — acceptable for the current session-length trips; token refresh
  on the live stream is a later concern if sessions get longer.

## References

- [ADR-023](adr-023-authentication-strategy.md) — Authentication strategy (client-agnostic API)
- [ADR-038](adr-038-hide-forbidden-as-not-found.md) — Hide forbidden as not found
- [ADR-053](adr-053-mobile-strategy-native-app.md) — Mobile strategy (native app)
- [Mobile Mercure auth](../mobile-mercure-auth.md) — spike outcome (#1011)
- `App\ApiResource\MercureToken`, `App\State\MercureTokenProvider` — the readable-body endpoint (#1019)

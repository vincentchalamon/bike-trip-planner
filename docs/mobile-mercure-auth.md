# Mobile Mercure auth (SSE for non-browser subscribers)

Spike outcome for [#1011](https://github.com/vincentchalamon/bike-trip-planner/issues/1011).
Establishes how the Expo/React Native app subscribes to the Mercure hub in real
time, since the web subscribes with an httpOnly cookie that React Native cannot use.

## Finding: the hub is not cookie-only

The embedded Caddy Mercure module (`.docker/php/Caddyfile`) is configured with
`subscriber_jwt` and `anonymous`. It authenticates a subscriber via any of the
standard Mercure channels, in order of preference:

1. `mercureAuthorization` httpOnly cookie — used by browsers so the JWT never
   reaches JS (XSS protection). **Not usable from React Native.**
2. `Authorization: Bearer <jwt>` request header.
3. `?authorization=<jwt>` query parameter.

Both (2) and (3) were confirmed against the live dev hub: a non-browser subscriber
(plain `curl`, no cookie) received a published event. **No Caddy/hub change is
required** for the mobile client.

### Reproduction

Subscriber and publisher JWTs are HS256, signed with `MERCURE_JWT_SECRET`
(`MERCURE_JWT_KEY` in compose; dev default `!ChangeThisMercureHubJWTSecretKey!`).

```bash
# mint an HS256 JWT with the given payload (dev secret)
KEY='!ChangeThisMercureHubJWTSecretKey!'
b64url(){ openssl base64 -A | tr '+/' '-_' | tr -d '='; }
mkjwt(){ h=$(printf '%s' '{"alg":"HS256","typ":"JWT"}' | b64url); p=$(printf '%s' "$1" | b64url); \
  echo "$h.$p.$(printf '%s' "$h.$p" | openssl dgst -sha256 -hmac "$KEY" -binary | b64url)"; }

SUB=$(mkjwt '{"mercure":{"subscribe":["*"]}}')
PUB=$(mkjwt '{"mercure":{"publish":["*"]}}')

# subscribe with the header (background), then publish
curl -sk -N --max-time 6 -H "Authorization: Bearer $SUB" \
  'https://localhost/.well-known/mercure?topic=https%3A%2F%2Fexample.com%2Ftest' &
curl -sk -X POST -H "Authorization: Bearer $PUB" \
  --data-urlencode 'topic=https://example.com/test' \
  --data-urlencode 'data={"event":"trip"}' \
  https://localhost/.well-known/mercure
# -> the subscriber prints: data: {"event":"trip"}
```

## Retained mechanism for #1014

Use the **`Authorization: Bearer` header** (`react-native-sse` supports custom
headers on its `EventSource` polyfill). The `?authorization=` query parameter is
the documented fallback if a platform strips the header; it is redacted from access
logs by the Caddyfile, but prefer the header so the token stays out of URLs.

## Gap to close before #1014 (backend)

The per-trip subscriber JWT already exists — `App\Mercure\MercureTokenIssuer`
mints it (HS256, claim `mercure.subscribe: ["/trips/{id}"]`, 1 h TTL) and
`App\Mercure\MercureSubscriberListener` attaches it. But it is delivered **only**
as the httpOnly `mercureAuthorization` cookie, on the trip create/access responses.
React Native cannot read that cookie, so the mobile client has no way to obtain the
token to put in the header.

A backend change is therefore required before #1014: deliver the same subscriber
token through a channel a non-browser client can read, without weakening the web's
cookie posture. Tracked in [#1019](https://github.com/vincentchalamon/bike-trip-planner/issues/1019).

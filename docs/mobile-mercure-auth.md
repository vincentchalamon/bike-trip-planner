# Mercure Auth for Non-Browser Clients

How a client without a browser cookie jar (the Expo / React Native app, a script) subscribes to a
trip's Mercure updates. The decision is recorded in
[ADR-056](adr/adr-056-mercure-header-auth-non-browser.md); Mercure's role as an invalidation
channel is in [ADR-065](adr/adr-065-mercure-is-an-invalidation-channel.md).

## Hub

| Property          | Value                                                                  |
|-------------------|------------------------------------------------------------------------|
| Hub               | Mercure module embedded in FrankenPHP, configured in `.docker/php/Caddyfile` |
| Protocol          | Mercure 1.0 (`protocol_version: 1.0` in `api/config/packages/mercure.php`) |
| Endpoint          | `/.well-known/mercure` on the API origin                               |
| Topic of a trip   | `/trips/{id}`                                                          |
| Topic selector    | `?match=/trips/{id}` (exact match; `topic=` is the pre-1.0 name)       |
| Token format      | RFC 9068 access token (`typ: at+jwt`) with `authorization_details`, HS256, signed with `MERCURE_JWT_KEY`; `iss` = `MERCURE_ISSUER`, `aud` = `MERCURE_PUBLIC_URL` |
| Token TTL         | 1 hour                                                                 |

## Ways to present the subscriber token

| Channel                                   | Client      | Status                                              |
|-------------------------------------------|-------------|-----------------------------------------------------|
| `__Secure-mercure_access_token` cookie (HttpOnly, `SameSite=Strict`, path `/.well-known/mercure`) | Browser (web app) | Set by `MercureSubscriberListener` on trip responses; never readable from JavaScript |
| `Authorization: Bearer <token>` header    | Mobile app, scripts | Supported; the mobile app uses it                    |
| `?authorization=<token>` query parameter  | none        | Rejected by the 1.0 hub (tokens must not travel in URLs) |

## Getting the token: `GET /trips/{id}/mercure-token`

| Property  | Value                                                                   |
|-----------|-------------------------------------------------------------------------|
| Auth      | The client's API JWT (`Authorization: Bearer`)                          |
| Access    | `TRIP_VIEW` on the trip; a trip you cannot see answers `404`, not `403` ([ADR-038](adr/adr-038-hide-forbidden-as-not-found.md)) |
| Response  | `{"token": "<subscriber JWT>"}`, the same token the web receives as a cookie, minted by `App\Mercure\MercureTokenIssuer` |
| Resource  | `App\ApiResource\MercureToken`, provider `App\State\MercureTokenProvider` |

The mobile implementation is `mobile/src/api/mercure.ts`: it fetches the token, then opens a
`react-native-sse` `EventSource` on `/.well-known/mercure?match=/trips/{id}` with the
`Authorization` header. Fetch a new token when it expires.

## Trying it by hand

```bash
API=https://localhost
JWT='<your API access token>'
TRIP='<trip id>'

TOKEN=$(curl -sk -H "Authorization: Bearer $JWT" -H 'Accept: application/ld+json' \
  "$API/trips/$TRIP/mercure-token" | jq -r .token)

curl -sk -N -H "Authorization: Bearer $TOKEN" \
  "$API/.well-known/mercure?match=/trips/$TRIP"
```

Then edit the trip from the app: each change prints a `data:` line carrying the event envelope.
Nothing in it is exclusive to the stream: a client that misses events recovers by reading the trip
through the API.

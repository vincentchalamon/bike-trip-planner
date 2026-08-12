# Bike Trip Planner - Mobile (Expo)

Native app foundation. Covers R1 (magic-link auth via deep link), R2 (typed API
client) and R4 (route map). Built with Expo SDK 57, expo-router, MapLibre RN and
openapi-fetch.

## Prerequisites

- Node 20+ and npm
- Android Studio + an Android SDK / emulator (or a physical device with USB debugging)
- JDK 17
- The backend reachable over HTTPS (the shared ngrok tunnel, see below)

`@maplibre/maplibre-react-native` and `expo-secure-store` are **native modules**:
they do not run in Expo Go. Use a development build (`npx expo run:android`).

## Install

```bash
cd mobile
npm install        # .npmrc pins legacy-peer-deps (react/react-dom peer quirk in SDK 57)
```

## Run (Android)

```bash
npx expo run:android      # builds the dev client and installs it on device/emulator
# then, for subsequent JS-only changes:
npx expo start --dev-client
```

## Pointing at the API (ngrok)

The API base URL is read from `EXPO_PUBLIC_API_URL`. Create `mobile/.env`:

```dotenv
EXPO_PUBLIC_API_URL=https://epidermis-sandlot-headrest.ngrok-free.dev
```

If unset, the client falls back to that same ngrok host (see `src/api/config.ts`).
`EXPO_PUBLIC_*` vars are inlined at bundle time, so restart Metro after changing it.

Once authenticated, every request carries an `Authorization: Bearer <jwt>` header
(`src/api/client.ts`). A 401 triggers a single token refresh + replay. The API is
client-agnostic: auth tokens travel in the JSON body and calls negotiate on
`application/ld+json` only.

## Auth / deep-link flow

1. **Login** (`app/login.tsx`) posts the email to `POST /auth/request-link`.
2. The email link opens the app via one of:
   - custom scheme: `biketripplanner://auth/verify/<token>`
   - Android App Link: `https://epidermis-sandlot-headrest.ngrok-free.dev/auth/verify/<token>`
3. The file route `app/auth/verify/[token].tsx` extracts the token and calls
   `POST /auth/verify`, which returns the JWT and refresh token. Both are stored
   in `expo-secure-store`.
4. `POST /auth/refresh` rotates the JWT when it expires.

### Testing the deep link

Custom scheme (works without domain verification):

```bash
adb shell am start -a android.intent.action.VIEW \
  -d "biketripplanner://auth/verify/TEST_TOKEN"
```

App Link (verified https link):

```bash
adb shell am start -a android.intent.action.VIEW \
  -d "https://epidermis-sandlot-headrest.ngrok-free.dev/auth/verify/TEST_TOKEN"
```

### assetlinks (App Link verification)

`autoVerify` App Links only open the app directly once Android has verified domain
ownership. The host must serve `/.well-known/assetlinks.json` listing this app's
package and its signing-certificate SHA-256 fingerprint:

```json
[{
  "relation": ["delegate_permission/common.handle_all_urls"],
  "target": {
    "namespace": "android_app",
    "package_name": "coop.lestilleuls.biketripplanner",
    "sha256_cert_fingerprints": ["<SHA-256 of the signing cert>"]
  }
}]
```

Get the fingerprint from the build keystore:

```bash
keytool -list -v -keystore <keystore> -alias <alias> | grep SHA256
```

Until assetlinks is published, use the custom scheme for testing.

## Regenerating API types

The backend OpenAPI is the source of truth. Refresh types after a schema change:

```bash
# from the repo root, export the spec (main vendor mounted read-only):
docker run --rm -v "$PWD/api:/app" -v "/home/vincent/Sites/bike-trip-planner/api/vendor:/app/vendor:ro" \
  -w /app -e APP_ENV=dev --entrypoint sh bike-trip-planner-php:dev \
  -c "bin/console api:openapi:export" > mobile/openapi.json

cd mobile && npm run typegen   # openapi-typescript openapi.json -> src/api/schema.d.ts
```

## Auth contract (client-agnostic, tokens in the body)

Since #1010 the backend auth is client-agnostic — no client detection, no cookie:

- `POST /auth/request-link` `{ email }` → 202.
- `POST /auth/verify` `{ token }` → `{ token, refresh_token }` in the body.
- `POST /auth/refresh` `{ refresh_token }` → `{ token, refresh_token }` in the body.

All three negotiate on `application/ld+json` only (both `Content-Type` and `Accept`);
a plain `application/json` POST is rejected (415/422). `src/auth/authApi.ts` posts and
reads the token pair from the body accordingly.

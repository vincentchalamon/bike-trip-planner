# Bike Trip Planner - Mobile (Expo)

Native app foundation. Covers R1 (magic-link auth via deep link), R2 (typed API
client) and R4 (route map). Built with Expo SDK 57, expo-router, MapLibre RN and
openapi-fetch.

## Prerequisites

- Node 20+ and npm
- Android Studio + an Android SDK / emulator (or a physical device with USB debugging)
- JDK 17
- The backend reachable over HTTPS (the shared ngrok tunnel, see below)

`@maplibre/maplibre-react-native`, `expo-secure-store`, `expo-location` and
`expo-notifications` are **native modules**: they do not run in Expo Go. Use a
development build (`npx expo run:android`).

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

```
EXPO_PUBLIC_API_URL=https://epidermis-sandlot-headrest.ngrok-free.dev
```

If unset, the client falls back to that same ngrok host (see `src/api/config.ts`).
`EXPO_PUBLIC_*` vars are inlined at bundle time, so restart Metro after changing it.

Every request carries `X-Client-Type: native` and, once authenticated, an
`Authorization: Bearer <jwt>` header (`src/api/client.ts`). A 401 triggers a single
token refresh + replay.

## Auth / deep-link flow

1. **Login** (`app/login.tsx`) posts the email to `POST /auth/request-link`.
2. The email link opens the app via one of:
   - custom scheme: `biketripplanner://verify?token=<token>`
   - Android App Link: `https://epidermis-sandlot-headrest.ngrok-free.dev/auth/verify/<token>`
3. `useDeepLinkAuth` extracts the token and calls `POST /auth/verify`, which returns
   the JWT (and, in the intended native contract, a refresh token). Both are stored
   in `expo-secure-store`.
4. `POST /auth/refresh` rotates the JWT when it expires.

### Testing the deep link

Custom scheme (works without domain verification):

```bash
adb shell am start -a android.intent.action.VIEW \
  -d "biketripplanner://verify?token=TEST_TOKEN"
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

## Known backend gap (to wire on the backend side)

The web auth is cookie-based: `POST /auth/verify` sets the refresh token as an
httpOnly cookie and returns `{ token }`; `POST /auth/refresh` reads that cookie and
ignores the body (`input: false`). The backend does **not** yet read
`X-Client-Type: native` nor return/accept the refresh token in the JSON body.

This foundation already sends `X-Client-Type: native` and posts/reads the refresh
token in the body (`src/auth/authApi.ts`), matching the intended native contract.
The JWT returned by `/auth/verify` works today; native refresh needs the backend to
return `refresh_token` in the verify/refresh body for that header.
```

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

## Installable preview build (device install)

To test an increment on a real phone without keeping Metro + a tunnel running,
build a standalone, self-contained APK once and install it. Policy is **free +
local**: builds run on your own machine, no cloud EAS project is required.

There are two paths; pick per need:

| Path | Command | Ships JS bundle? | Needs Metro at runtime? |
| --- | --- | --- | --- |
| Dev client (debug) | `npx expo run:android` | no (Metro serves it) | yes |
| Preview (standalone) | `npm run build:preview` | yes (baked in) | no |

The **preview** build is the one you sideload for offline testing. It reads the
`preview` profile in [`eas.json`](eas.json) (`distribution: internal`,
`android.buildType: apk`) and runs the whole build locally via `eas build
--local`.

```bash
cd mobile

# EXPO_PUBLIC_API_URL is inlined into the bundle at build time. A non-development
# build FAILS CLOSED without it (see src/api/config.ts and app.config.js), so it is
# mandatory here — it also fixes the Android App Link host for the magic link.
EXPO_PUBLIC_API_URL=https://epidermis-sandlot-headrest.ngrok-free.dev \
  npm run build:preview
# -> writes an APK such as build-<timestamp>.apk in mobile/
```

Notes:

- `eas build --local` generates and reuses a local keystore on first run; you may
  be prompted to log in to an Expo account for credential storage. No cloud build
  is submitted.
- `eas-cli` is not a project dependency; run it with `npx eas-cli ...` if the
  `eas` binary is not on your `PATH` (`npm run build:preview` calls `eas`
  directly — prefix with `npx` if needed).

### Install the APK on a device

```bash
adb install -r build-<timestamp>.apk   # USB debugging enabled, device authorised
# or copy the .apk to the phone and open it (allow "install unknown apps")
```

### How the installed app reaches the API

The preview APK talks to whatever host you baked into `EXPO_PUBLIC_API_URL` at
build time — there is no runtime override, the value is compiled into the JS
bundle. For a physical device the host must be reachable **from the phone**, not
just from your laptop:

- **ngrok public URL** (the default above): works over Wi-Fi or cellular, and its
  HTTPS is what the Android App Link (`/auth/verify`) verifies. Recommended.
- **A LAN IP** (`http://192.168.x.y:8000`): only works when the phone is on the
  same network, and cleartext HTTP additionally needs an ATS/`usesCleartext`
  exception — the ngrok tunnel avoids both problems.

`localhost` never works from a phone: it resolves to the device itself. To repoint
the app at another environment, rebuild with a different `EXPO_PUBLIC_API_URL`.

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

## API types

Since #1014 the mobile app consumes the generated OpenAPI types from the shared
`@btp/core` workspace (`@btp/core/schema`), the same source of truth the web app
uses — there is no mobile-local `schema.d.ts` any more. Regenerate types from the
repo root with `make typegen` (writes `core/schema.d.ts`); the mobile app picks up
the change automatically through the workspace.

## Auth contract (client-agnostic, tokens in the body)

Since #1010 the backend auth is client-agnostic — no client detection, no cookie:

- `POST /auth/request-link` `{ email }` → 202.
- `POST /auth/verify` `{ token }` → `{ token, refresh_token }` in the body.
- `POST /auth/refresh` `{ refresh_token }` → `{ token, refresh_token }` in the body.

All three negotiate on `application/ld+json` only (both `Content-Type` and `Accept`);
a plain `application/json` POST is rejected (415/422). `src/auth/authApi.ts` posts and
reads the token pair from the body accordingly.

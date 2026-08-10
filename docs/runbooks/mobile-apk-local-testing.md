# Runbook: testing the Android APK against a local API over ngrok

You want to run the Capacitor APK on a real phone while the API runs on your dev
machine, exposed through an ngrok tunnel. This is the procedure.

**The APK from GitHub Artifacts will not work for this.** The mobile build is a
static export (`output: "export"`, see [ADR-024](../adr/adr-024-mobile-strategy-capacitor.md)):
there is no Next.js server and none of the web build's `/api/*` rewrite, so the WebView
calls `NEXT_PUBLIC_API_URL` **directly**, and that value is frozen into the bundle at
build time. The CI job (`.github/workflows/android.yml`) bakes it from the repo variables
`NEXT_PUBLIC_API_URL` / `NEXT_PUBLIC_MERCURE_URL`, which are **unset** — so the Artifacts
APK points at empty/relative URLs (its own `https://localhost` WebView origin) and has no
usable backend. Nothing in an installed APK is reconfigurable at runtime.

To hit your local API you must **rebuild the APK yourself with the ngrok URL baked in**.

## Symptômes

Reasons to run this:

- You need to validate a native (WebView) behaviour that the web PWA over ngrok does not
  reproduce (see [mobile browser testing over ngrok](../../scripts/ngrok-recette.sh)
  for the web-only, no-rebuild path).
- You downloaded the Artifacts APK, installed it, and every screen is blank / every API
  call fails — expected, per above.

## Prérequis

- **Node.js 26 + npm**, with the `pwa/` dependencies installed (`cd pwa && npm ci`). The
  Capacitor CLI (`@capacitor/cli`, `@capacitor/android`) comes from the dev dependencies —
  no separate global install.
- **Android SDK + build-tools + JDK 21** locally (Android Studio or the command-line tools),
  so `./gradlew assembleDebug` runs. The repo does **not** commit `pwa/android/`;
  `npm run build:android` creates it via `npx cap add android` on first run.
- **`adb`** on PATH to sideload, or transfer the `.apk` to the phone and allow "unknown
  sources".
- **A physical Android device** (ADR-024 targets the Samsung Galaxy S20 FE) **or an emulator**,
  with **USB debugging** enabled for `adb install`.
- **Docker + Docker Compose**, running the **recette** stack. `make start-recette` is the
  equivalent of the raw `docker compose` command in step 1 and also provisions the JWT
  keypair (`ensure-jwt-recette`).
- **ngrok installed with an account/authtoken** configured. The free tier works but the
  tunnel URL rotates on every restart and shows a one-time interstitial (see gotchas below).
- **HTTPS end to end.** `capacitor.config.ts` sets `androidScheme: "https"` +
  `allowMixedContent: false`, so the API URL must be `https://` (ngrok provides it). The
  ngrok URL is frozen into the bundle at build time via `NEXT_PUBLIC_API_URL` /
  `NEXT_PUBLIC_MERCURE_URL` (step 3) — rebuild the APK whenever it rotates.

## Procédure

1. **Boot the stack and open the tunnel**, then note the ngrok host:

    ```bash
    docker compose -f compose.yaml -f compose.recette.yaml up --wait
    ngrok http https://localhost          # e.g. abcd-1234.ngrok-free.app
    ```

2. **Point the backend at the ngrok host** (reuses the same script as the web path —
    sets `SERVER_NAME` to serve both `localhost` and the ngrok domain, `local_certs`,
    `TRUSTED_HOSTS`, `FRONTEND_URL`; re-run it whenever the ngrok URL rotates):

    ```bash
    scripts/ngrok-recette.sh abcd-1234.ngrok-free.app
    ```

    No CORS change is needed: `CORS_ALLOW_ORIGIN` (`api/.env`) already allows the native
    WebView origin —
    `^(https?|capacitor)://(localhost|127\.0\.0\.1)(:[0-9]+)?$` covers both
    `https://localhost` and `capacitor://localhost`.

3. **Build the APK with the ngrok URL frozen in** (Mercure gets an explicit URL because
    the native WebView cannot resolve it same-origin):

    ```bash
    cd pwa
    NEXT_PUBLIC_API_URL="https://abcd-1234.ngrok-free.app" \
    NEXT_PUBLIC_MERCURE_URL="https://abcd-1234.ngrok-free.app/.well-known/mercure" \
      npm run build:android        # build:mobile + cap add/sync android
    cd android && ./gradlew assembleDebug
    # APK: pwa/android/app/build/outputs/apk/debug/app-debug.apk
    ```

4. **Install on the phone** and open the app:

    ```bash
    adb install -r pwa/android/app/build/outputs/apk/debug/app-debug.apk
    ```

## Post-action / gotchas

- **ngrok-free rotates the URL on every restart.** Each new tunnel means re-running
  `ngrok-recette.sh` **and** rebuilding the APK (the URL is frozen in the bundle, unlike
  the origin-relative web build). A paid ngrok static domain removes the rebuild.
- **HTTPS is mandatory.** `capacitor.config.ts` sets `allowMixedContent: false` with
  `androidScheme: "https"`; the API URL must be `https://` (ngrok is).
- **Auth cookie is cross-site here.** The session cookie is host-only, `Secure`,
  `SameSite=Lax` ([ADR-023](../adr/adr-023-authentication-strategy.md)). The WebView origin
  (`https://localhost`) and the API (`https://<ngrok>`) are a cross-site pair, so a `Lax`
  cookie is not sent on cross-site requests. If native login relies on the cookie rather
  than a bearer token, this is the first thing that will break — verify the auth flow
  before blaming the build.
- **First open shows the ngrok-free interstitial** once; accept it.

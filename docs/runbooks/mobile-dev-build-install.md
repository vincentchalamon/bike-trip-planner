# Mobile Dev Build & Device Install

How to get a working build of the mobile app onto a real device or emulator
during development — the day-to-day loop, distinct from the two standalone
paths documented elsewhere (see References).

## Symptômes (when to use)

- You changed native code, added a native module, or bumped an Expo SDK/plugin,
  and Metro-only (`npx expo start` against Expo Go) is not enough or not
  applicable.
- You need the app installed on a physical device or emulator to iterate with
  live JS reload.
- `adb install` fails, the app crashes on launch, or the device never shows up
  as a build target.

## Diagnostic

The mobile app is an **Expo CNG (Continuous Native Generation) project**:
`mobile/android` and `mobile/ios` are generated on demand by `expo prebuild`
and gitignored (`mobile/.gitignore`: `/android`, `/ios`). There is no committed
native project to open in Android Studio directly.

`@maplibre/maplibre-react-native` and `expo-secure-store` are **native
modules** — they do not run inside Expo Go. Any change under `mobile/` needs a
**development build** (`npx expo run:android`), not the Expo Go app.

Check the toolchain and target before building:

```bash
node -v          # Node 20+
java -version    # JDK 17
adb devices      # at least one device/emulator, state "device" not "unauthorized"
```

A device listed as `unauthorized` means the "Allow USB debugging" prompt on the
phone was not accepted (or was accepted for a different machine's RSA key) —
unlock the phone and accept the prompt, then re-run `adb devices`.

## Procédure

1. **Install dependencies once**, from the repo root (links `@btp/core` via
   npm workspaces — see ADR-053):

    ```bash
    npm install
    ```

2. **Build the dev client and install it** on the connected device/emulator:

    ```bash
    cd mobile
    npx expo run:android
    ```

    This runs `expo prebuild` (regenerating `android/`), compiles the native
    project, and installs the resulting debug APK — Metro serves the JS bundle,
    nothing is baked in.

3. **Point the app at a reachable API** before or after the build (see
   `mobile/README.md`, "Pointing at the API"): `EXPO_PUBLIC_API_URL` is read at
   bundle time, so set it in `mobile/.env` or export it before step 2 if you
   need a non-default host.

4. **Subsequent JS-only changes** don't need a rebuild — just restart the
   Metro server against the already-installed dev client:

    ```bash
    npx expo start --dev-client
    ```

5. **Reinstall without rebuilding** (e.g. after `adb uninstall`, or to move an
   already-built APK to a second device):

    ```bash
    adb install -r android/app/build/outputs/apk/debug/app-debug.apk
    ```

## Post-action

- Confirm the app opens to the login screen and Metro's terminal shows the
  device connected ("Android Bundled ... " logs streaming).
- Exercise the auth deep link once to confirm the build is wired correctly
  (see `mobile/README.md`, "Testing the deep link"):

    ```bash
    adb shell am start -a android.intent.action.VIEW \
      -d "biketripplanner://auth/verify/TEST_TOKEN"
    ```

- If you only needed a build to hand off or sideload without keeping Metro
  running, use the **preview** path instead (`npm run build:preview` — see
  `mobile/README.md`, "Installable preview build") rather than repeating this
  runbook per install.

## References

- `mobile/README.md` — full prerequisites, API pointing, deep-link testing,
  assetlinks
- [mobile-release-build.md](mobile-release-build.md) — release-**signed**
  standalone APK (a different, later-stage build than this one)
- ADR-053 — npm-workspaces monorepo (`core` / `pwa` / `mobile`)

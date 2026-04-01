# ADR-024: Mobile Strategy — Capacitor for Android APK

- **Status:** Accepted
- **Date:** 2026-04-01
- **Depends on:** ADR-001 (Global Architecture), ADR-023 (Authentication Strategy)

## Context and Problem Statement

Bike Trip Planner needs a mobile version to allow consulting trips offline while bikepacking. The target device is a Samsung Galaxy S20 FE running Android, used by a single user who primarily uses Firefox (not Chrome) as their default browser.

The mobile solution must:

- **Produce an installable APK** — sideloaded directly onto the device, no app store publication required
- **Reuse the existing Next.js codebase** — avoid maintaining a separate mobile codebase
- **Work independently of the system browser** — the user runs Firefox, which limits browser-dependent solutions
- **Support offline access** — trips must be consultable without network connectivity while on the road
- **Access native APIs** — filesystem storage, network status detection, and potentially GPS

### Usage Context

This is a private-use application with no store distribution. The APK is installed manually via sideloading. This removes all constraints related to app store review policies, bundle size limits, and update mechanisms.

---

## Decision Drivers

- **Code reuse** — maximize shared code between web and mobile to minimize maintenance burden
- **Browser independence** — must not depend on Chrome or any specific system browser
- **APK output** — must produce a standalone Android application package
- **Offline capability** — trip data must be accessible without network
- **Development effort** — single-developer project; minimize additional tooling and learning curve

---

## Considered Options

| Criteria | PWA (Firefox) | Capacitor | React Native |
|---|---|---|---|
| **Produces an APK** | No | Yes | Yes |
| **Single codebase** | Yes | Yes | No (rewrite) |
| **Browser independent** | No (host browser) | Yes (embedded WebView) | Yes (native) |
| **Development effort** | Low | Low–Medium | High |
| **Native API access** | Limited | Full (via plugins) | Full |
| **Performance** | Browser-dependent | WebView (adequate) | Native (best) |

### Option A: PWA on Firefox

Progressive Web App installed via Firefox's "Add to Home Screen."

**Pros:**

- Zero additional tooling — the existing Next.js app already supports PWA patterns
- No build pipeline changes
- Automatic updates via service worker

**Cons:**

- **Firefox on Android has limited PWA support** — no standalone display mode, no reliable service worker lifecycle, no background sync
- Does not produce an APK — just a browser shortcut
- Offline caching is unreliable and varies across Firefox versions
- No access to native APIs beyond what the browser exposes
- **Eliminated by the Firefox constraint** — this approach only works well on Chrome-based browsers

### Option B: Capacitor (chosen)

Ionic Capacitor wraps the Next.js frontend in a native Android WebView, producing a standard APK.

**Pros:**

- Reuses the entire Next.js codebase — the web app runs inside a WebView with a native shell
- Produces a standard APK installable via sideloading
- Uses Android System WebView (independent of the user's default browser)
- Full access to native APIs via Capacitor plugins (Filesystem, Network, Geolocation, Preferences)
- Active ecosystem with official and community plugins
- Low learning curve for a web developer — configuration is mostly `capacitor.config.ts`

**Cons:**

- WebView performance is slightly lower than fully native — acceptable for a data display app
- Requires a dual build strategy (web vs. mobile output modes)
- Adds Android SDK tooling to the development environment
- Debugging requires Android Studio or Chrome DevTools remote debugging

### Option C: React Native

Full rewrite of the frontend using React Native components.

**Pros:**

- Native performance and platform-specific UI
- Direct access to all native APIs
- Mature ecosystem with strong community support

**Cons:**

- **Requires a complete frontend rewrite** — React Native components are not compatible with React DOM/Next.js
- Two separate codebases to maintain (web + mobile)
- Massive effort for a single-developer project with a single target device
- Overkill for what is essentially a data consultation app
- No code sharing with the existing Next.js frontend beyond business logic utilities

---

## Decision

**Capacitor** to wrap the existing Next.js frontend into an Android APK.

### Dual Build Strategy

The Next.js application supports two output modes controlled at build time:

| Target | Next.js `output` | Purpose |
|---|---|---|
| **Web** | `'standalone'` | Production server deployment (Docker/Node.js) |
| **Mobile** | `'export'` | Static HTML export for Capacitor WebView |

The `output` mode is toggled via an environment variable (e.g., `BUILD_TARGET=mobile`), keeping a single `next.config.ts` with conditional configuration.

### Architecture Adaptations

#### i18n: Client-Side Only

Next.js static export (`output: 'export'`) does not support the built-in `i18n` routing. All internationalization is refactored to client-side only, using a library like `next-intl` in client mode or a lightweight i18n solution that does not depend on server-side locale detection.

This has no impact on the web build since the application already targets a single locale.

#### API URL Configuration

The mobile app must communicate with the remote API server. The API base URL is parameterized via `NEXT_PUBLIC_API_URL`, allowing the Capacitor build to point to the production API while the web build uses relative URLs or the Docker-internal address.

#### CORS Extension

The Capacitor WebView serves content from `capacitor://localhost` on Android. The backend CORS configuration is extended to allow this origin:

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin:
            - '^https?://localhost(:\d+)?$'
            - '^capacitor://localhost$'
```

#### Offline Access

Capacitor's Filesystem and Preferences plugins store trip data locally on the device. When the app detects network availability (via the Network plugin), it syncs trip data from the API. When offline, the app serves cached trip data from local storage.

---

## Consequences

### Positive

- **Single codebase** — web and mobile share the same React components, Zustand stores, and API client
- **Browser independent** — the embedded WebView is decoupled from the user's Firefox installation
- **Installable APK** — standard Android package, sideloaded without store constraints
- **Offline capable** — native storage plugins provide reliable offline data access
- **Low incremental effort** — no frontend rewrite, mostly configuration and build pipeline changes

### Negative

- **Dual build complexity** — `next.config.ts` must handle two output modes; CI pipeline needs a mobile build job
- **i18n refactoring** — internationalization must move to client-side to support static export
- **WebView limitations** — some advanced CSS or browser APIs may behave differently in Android WebView
- **Android SDK dependency** — developers need Android Studio and SDK tools for mobile builds

### Neutral

- **No store publication** — eliminates app review delays but also means no automatic update distribution (user must manually install new APKs)
- **Single device target** — simplifies testing (only Samsung Galaxy S20 FE) but decisions may need revisiting if more devices are added

---

## References

- [Capacitor Documentation](https://capacitorjs.com/docs)
- [Next.js Static Export](https://nextjs.org/docs/app/building-your-application/deploying/static-exports)
- [Capacitor Filesystem Plugin](https://capacitorjs.com/docs/apis/filesystem)
- [Capacitor Network Plugin](https://capacitorjs.com/docs/apis/network)

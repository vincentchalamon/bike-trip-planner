# ADR-053: Mobile Strategy — Dedicated Native App

- **Status:** Accepted
- **Date:** 2026-08-11
- **Supersedes:** ADR-024 (Mobile Strategy — Capacitor for Android APK)
- **Depends on:** ADR-001 (Global Architecture), ADR-023 (Authentication Strategy), ADR-047 (server-side auth)

## Context and Problem Statement

Installed on a phone, the PWA "feels like a website", not an app. The product is being repositioned around two distinct surfaces on one API:

- **Web (Next.js SSR):** discovery, SEO, sharing (`/s/…`), and trip planning on a large screen. It stays a plain website — the installable-PWA layer is removed (Phase 1).
- **Mobile:** the in-the-field surface (offline consultation + in-ride navigation).

The real driver for going native is **not** aesthetics — a responsive web app can adopt native-looking patterns. It is the set of capabilities a browser/WebView cannot deliver for bikepacking: **offline maps, background GPS, and push notifications**. ADR-024 chose Capacitor (Next.js inside an Android WebView); that produces web-in-a-shell, and its offline promise does not hold — the current map tiles are hosted, online-only services (Carto basemaps + Esri imagery) whose terms forbid bulk offline caching.

## Decision

Build a **dedicated native mobile app**, Android first (iOS later).

- **Leading candidate: React Native + Expo**, to reuse the single type contract (backend DTO → OpenAPI → TS) and the pure domain logic (pacing, Zod validation) across web and mobile in an upcoming monorepo (`apps/{core,web,mobile}`), and to get iOS at low marginal cost. MapLibre React Native mirrors the web's `maplibre-gl`.
- **Contingent on a throwaway spike.** The spike first de-risks the **offline vector-tile strategy** (self-hosted OpenMapTiles/Planetiler or PMTiles vs. a paid provider with an offline license) — a go/no-go on the native bet itself — then validates auth, background location, push, and the OpenAPI client from RN. A no-go on offline, or a spike verdict favouring Flutter, revisits the tech choice before any monorepo work.
- **Push via FCM**, included from the MVP.
- **Capacitor is abandoned:** its scaffolding (`capacitor.config.ts`, the Android project, the dual `BUILD_TARGET` Next.js export, the `android.yml` workflow) is removed and the web reverts to a plain SSR site.

## Consequences

- No more "single codebase" shortcut: the native app carries its own UI layer; sharing happens through `apps/core` (types + domain logic), not through React DOM components. This reverses ADR-024's main rationale, which was reasonable when the goal was merely consulting data in a WebView.
- A new native mobile workstream (and a small backend brick for FCM push).
- The authentication strategy (ADR-023) now applies to a **native client** rather than a Capacitor WebView: the refresh token is returned in the response body when a native client is detected and stored in the platform's secure storage.
- The mobile approach is validated incrementally (spike → MVP), so this ADR states direction; the spike gates the final tech commitment.

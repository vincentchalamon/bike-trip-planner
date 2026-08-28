# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta.1] - 2026-08-28

First closed-beta release. The planner turns a Komoot / Strava / RideWithGPS URL
or a GPX upload into a day-by-day bikepacking roadbook, on web and on Android.

### Added

- **Route import** from Komoot (tour and collection), Strava, RideWithGPS, and
  direct GPX upload (up to 30 MB), with SSRF/XXE-hardened ingestion.
- **Roadbook engine**: smart pacing (day-by-day targets from distance and
  elevation), stateless async computation over Symfony Messenger, results
  streamed live to the UI via Mercure SSE.
- **Rule-based alert engine**: safety and comfort nudges per stage, each keyed on
  a stable `AlertCode` for dismissal and dedup.
- **Discovery layers**: accommodations and cultural POIs from OpenStreetMap,
  DataTourisme, Wikidata, and events from OpenAgenda; per-stage weather; Valhalla
  routing over a France OSM extract.
- **In-ride nearby-search assistant** (AI-free).
- **Export and sharing**: multi-format export and public read-only trip share.
- **Accounts**: passwordless magic-link login, encrypted refresh tokens, and
  GDPR account erasure (immediate irreversible anonymisation).
- **Native Android app** (Expo / React Native) sharing `@btp/core` with the web
  app: offline auto-sync, FCM push notifications, full planner parity.

### Known limitations (beta)

- **AI analysis removed** (ADR-052): the trip brief and stage narrative no longer
  use an LLM.
- **Garmin Connect export** is deferred (icebox).
- **Resupply timeline UI** is disabled pending a UX redesign; the underlying data
  is still computed.
- **Backups** are not automated for the closed beta; do not treat beta data as
  durable.
- **Observability** runs on Sentry SaaS (errors) and UptimeRobot (uptime);
  self-hosted GlitchTip / Uptime Kuma / Plausible are the post-beta target.
- **Coverage** is France-only (OSM extract and routing graph).

[Unreleased]: https://github.com/vincentchalamon/bike-trip-planner/compare/v0.1.0-beta.1...HEAD
[0.1.0-beta.1]: https://github.com/vincentchalamon/bike-trip-planner/releases/tag/v0.1.0-beta.1

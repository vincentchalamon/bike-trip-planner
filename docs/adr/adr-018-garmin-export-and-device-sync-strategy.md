# ADR-018: Garmin Export and Device Sync Strategy

- **Status:** Accepted - Phase 1 (enriched GPX + FIT download) is implemented; Phase 2 (Garmin Connect push) is not
- **Date:** 2026-03-04
- **Depends on:** ADR-004 (GPX decimation), Enriched GPX export (waypoints)

## Context and Problem Statement

Bike Trip Planner generates multi-stage bikepacking itineraries. Currently, the export is limited to one GPX file per stage (a `<trk>/<trkpt>` track with lat/lon/ele, ~1,500 points after Douglas-Peucker decimation). The rider has to download the GPX and then import it manually into their Garmin GPS.

Two identified needs:

1. **Native Garmin format** - GPX works, but FIT (Garmin's proprietary binary format) is the native format for Garmin courses: more compact, supports Course Points (POIs along the route), and avoids a conversion on the GPS side.
2. **Automatic push to the GPS** - Remove the manual import step by sending the itinerary directly to the user's Garmin Connect account, with automatic sync to the device.

### ADR-004 Clarification

ADR-004 mentions, as a neutral consequence, that decimated data is not suitable to "re-export high-fidelity GPS traces for Garmin devices". That remark concerns re-exporting high-fidelity traces (activity recording). For **course navigation** (following the purple line on an Edge/Fenix), ~1,500 points per stage is more than enough - Komoot and Strava send courses with a similar density.

## Considered Options

### Option A: GPX download only (improved status quo)

Keep the GPX format, enriched with waypoints (`<wpt>`) for accommodations, water points and shops. Add a global GPX download button (all stages concatenated).

| Criterion | Assessment |
|---------|------------|
| GPS compatibility | Universal (Garmin, Wahoo, Hammerhead, Coros...) |
| Compactness | Low (verbose XML, ~5× heavier than FIT) |
| Course Points / POIs | Supported via `<wpt>`, but conversion needed on the GPS side |
| Implementation effort | Minimal (the `GpxWriter` exists, add `<wpt>`) |
| UX | Manual import required |

### Option B: FIT download (Garmin binary format)

A new backend `FitWriter` generating the FIT binary format via PHP's native `pack()`. FIT directly encodes typed Course Points (Food, Water, Summit, Generic...) that Garmin GPS units recognize natively.

File structure:

```text
Header (14 bytes)
├── FILE_ID     (type=course, manufacturer, product)
├── COURSE      (name, sport=cycling)
├── EVENT       (timer start)
├── RECORD[]    (lat/lon in semicircles, altitude, cumulative distance)
├── COURSE_POINT[]  (POIs: accommodations, water points, shops)
├── LAP         (start/end summary, total distance)
└── EVENT       (timer stop)
CRC16
```

| Criterion | Assessment |
|---------|------------|
| GPS compatibility | Garmin only (Wahoo/Hammerhead also accept FIT, but GPX remains more universal) |
| Compactness | ~5× more compact than GPX |
| Course Points / POIs | Native, typed (Food, Water, Summit...), displayed directly on the GPS |
| Implementation effort | Medium (~200-300 lines, native `pack()`, no external dependency) |
| UX | Manual import, but a lighter file and native POIs |

Coordinates in "semicircles": `round(degrees / 180 × 2^31)`. Course Point descriptions are limited to 16 bytes.

### Option C: Push via the Garmin Connect Courses API

The backend pushes the FIT file directly to the user's Garmin Connect account via the Courses API. The device receives the course automatically at the next sync (Bluetooth/WiFi/USB). This is the mechanism used by Komoot and Strava.

| Criterion | Assessment |
|---------|------------|
| UX | Optimal: 1 click, automatic sync to the GPS |
| Implementation effort | High (OAuth 2.0 PKCE, token management, automatic refresh) |
| Prerequisites | DB persistence (token storage), production infrastructure (HTTPS OAuth callback), Garmin Developer Program approval |
| Garmin Developer Program | Free, ~2-day approval, open to individual developers |
| OAuth constraint | OAuth 1.0 retired on 31/12/2026 → implement OAuth 2.0 PKCE directly |
| API specifications | Available only after acceptance into the program |

### Option D: Push via Strava as an intermediary (Strava → Garmin Connect → GPS)

Push the itinerary to Strava, which then syncs automatically to Garmin Connect.

**Not viable.** The Strava v3 API is read-only for routes:

- `GET /routes/{id}` - view
- `GET /routes/{id}/export/gpx` - export
- `GET /athletes/{id}/routes` - list

There is no `POST` endpoint to create a route programmatically. Only uploading *activities* (recorded rides) is supported, not *planned routes*. Moreover, even if the endpoint existed, it would add a needless intermediary compared with a direct push to Garmin Connect (Option C).

## Decision Outcome

**Chosen: Options A + B (Phase 1), then Option C (Phase 2), sequentially. Option D rejected.**

### Phase 1: Enriched GPX + FIT (download)

The two formats are complementary:

- **Enriched GPX** (Option A): universal compatibility, useful for non-Garmin GPS units
- **FIT** (Option B): native Garmin format, more compact, typed Course Points

Download buttons:

- Per stage: GPX + FIT in the `stage-card`
- Whole itinerary: GPX + FIT in the `trip-summary`

The `FitWriter` can be implemented without any external dependency (PHP's native `pack()`). The enriched GPX requires adding `<wpt>` to the existing `GpxWriter`.

### Phase 2: Garmin Connect push (Option C)

Once the following are in place: DB persistence (OAuth tokens), production infrastructure (HTTPS callback), and Garmin Developer Program approval.

### Rejection of Option D (Strava)

The Strava API does not allow creating routes. The option is technically impossible.

## Consequences

### Positive

- **Immediate value (Phase 1)** - FIT download brings the native Garmin format without any external dependency or additional infrastructure.
- **Universal coverage** - GPX for every GPS, FIT for the optimal Garmin experience.
- **Reuse (Phase 2)** - The FIT file generated by the `FitWriter` is reused as-is for the Garmin Connect push.

### Negative

- **FIT is a proprietary binary format** - There is no official FIT SDK for PHP; encoding via `pack()` requires a manual implementation of the protocol (header, message definitions, CRC16). Risk of subtle errors on edge cases.
- **Phase 2: Garmin coupling** - The OAuth integration creates a dependency on a third-party service (availability, API changes, approval process).

### Neutral

- Phase 2 is blocked by structural prerequisites (DB, production infrastructure, Garmin approval) that will be handled independently.

## Sources

- [Garmin FIT SDK](https://developer.garmin.com/fit/protocol/)
- [Strava API v3 Reference](https://developers.strava.com/docs/reference/) - confirms there is no route creation endpoint
- [Strava Routes to Garmin Device](https://support.strava.com/hc/en-us/articles/115000919304-Syncing-Strava-Routes-to-your-Garmin-Device)
- [Garmin Connect Developer Program](https://www.garmin.com/en-US/forms/GarminConnectDeveloperAccess/)

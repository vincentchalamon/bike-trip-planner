# Features

What Bike Trip Planner does, grouped by area. For a guided walk-through, see
[Plan your first trip](plan-your-first-trip.md).

## Coverage

Reference data (points of interest, accommodations, events) is imported zone by zone.
The covered area is currently France, Belgium, the Netherlands and Luxembourg. A route
outside it still shows its distances and the points of interest found, but it cannot be
edited or re-routed.

## Route import

- Paste a **Komoot** tour or collection, a **Strava** route or a **RideWithGPS** route
  URL, or upload a **GPX** file (up to 30 MB). See [supported route sources](route-sources.md).
- The source is detected as you type; unsupported URLs are refused before any fetch.
- The trip view opens as soon as the stage breakdown is computed; the enrichments
  (points of interest, accommodations, weather, alerts) stream in over Mercure SSE.

## Planning and pacing

- **Stage breakdown**: with dates, one stage per day; without dates, as many stages as
  the maximum daily distance requires.
- **Pacing formula**: each day is shorter than the previous one (accumulated fatigue,
  10% per day by default) and elevation gain shortens every stage; stages never drop
  below 30 km. `target_day_n = base * 0.9^(n-1) - elevation_gain / 50`.
- **Rider profile**: Beginner / Intermediate / Expert presets, or custom maximum
  distance per day, average speed, accumulated fatigue, elevation index, departure hour
  and e-bike mode.
- **Stage editing**: change a day's distance (the rest of the trip is re-split), add or
  delete a stage, insert a rest day, search a stage's departure or arrival, add a point
  of interest as a waypoint. Changes can be undone and redone.
- **Per-stage estimates**: departure and arrival times, difficulty, surface breakdown,
  elevation profile, water and resupply points along the stage, estimated budget.
- **Trip management**: a "My trips" list with title and date filters; duplicate or
  delete a trip. A trip that is underway or past becomes read-only; duplicate it to plan
  a variant.

## Alerts

A rule-based engine checks every stage for terrain, traffic, weather, calendar and
services issues (steep gradients, main roads, rough surfaces, headwind, heat, sunset,
resupply gaps, water gaps, ferries, fords, border crossings and more), at three
severity levels: critical, warning, nudge. Each alert carries a stable code, so a
dismissed alert stays dismissed. See the [alert engine](alert-engine.md) for every rule.

## Accommodations, points of interest and events

- **Accommodations** near each stage end, from OpenStreetMap and DataTourisme: hotels,
  guest houses, chalets, hostels, alpine huts, campsites, wilderness huts, with an
  estimated price. Filter the types, widen the search radius, select one per stage, or
  add your own (name, address, price, URL). See [accommodation types](accommodations.md).
- **Cultural points of interest** (museums, monuments, castles, viewpoints...) near the
  route, with an "Add to itinerary" action.
- **Local events** (festivals, concerts, exhibitions, fairs) on the dates of the trip,
  from DataTourisme and OpenAgenda.

Data sources are described in [external data sources](external-data-sources.md).

## Weather

Per-stage forecast from Open-Meteo (up to 16 days ahead): temperature and feels-like,
rain, wind and gusts relative to the direction of travel (headwind, tailwind,
crosswind), a comfort index, sunrise and sunset, and an hour-by-hour table for the
riding window.

## In-ride assistance

On the road, tap one of eight questions (water, shelter, food, groceries, bike shop,
pharmacy, train, e-bike charging) to get the closest options ranked by distance and
detour, with opening-hours status, a "closes soon" warning and a hand-off to your maps
app. It reads the local map index directly (no AI), and your position is sent in the
request body only, never in a URL.

## Export and sharing

- Download the whole trip or a single stage as **GPX** or **FIT**, with waypoints for water points, shops and accommodations.
- Share a trip through a **read-only link** (revocable at any time), a **PNG
  infographic**, or a **formatted text** summary.

## Accounts and privacy

- Passwordless sign-in by **magic link** (the link expires after 30 minutes). The
  service is in private beta: accounts are granted after an early-access request.
- Change your email address (confirmed from the new address).
- **Download your data** as JSON, or **delete your account** (immediate, irreversible
  anonymisation).
- Cookieless, self-hosted audience measurement (Plausible); no third-party trackers.
- Light / dark theme, English / French interface, keyboard shortcuts.

See [Legal & Licensing](legal-and-licensing.md) for the GDPR posture.

## Mobile app

A native Android app (Expo / React Native) on the same API:

- sign in by magic link, create a trip from a URL or a GPX file, browse trips, stages
  and the map, edit stages, export GPX / FIT and share;
- upcoming and ongoing trips are **cached on the device** and re-synced in the
  background when the connection returns;
- in-ride assistance using the device location;
- **push notifications** (weather and safety for the day's stage, analysis finished,
  new zone opened) and on-device reminders (offline data not ready, trip without
  dates), each toggleable.

## AI agents (MCP)

An AI assistant such as Claude can plan trips on your behalf through the built-in MCP
server: it lists, reads, creates, edits, analyses, shares and deletes trips with 13
tools. Access goes through OAuth 2.1: you approve each application on a consent screen,
and the **Authorized applications** section of your account (web and mobile) shows
when each one was authorized and last used, and lets you revoke it.

- [Connect an AI agent](connect-an-ai-agent.md)
- [MCP tools reference](mcp-tools.md)

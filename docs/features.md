# Features

**Import your route in seconds** — Paste a link from Komoot, Strava, or RideWithGPS, or upload a GPX file directly. The backend fetches, parses, and processes everything asynchronously.

**Smart pacing engine** — Automatically distributes distance across days, accounting for cumulative fatigue and elevation gain. Configurable daily targets with a safety minimum threshold.

**20+ safety & comfort alerts** — A rule-based alert engine analyzes each stage for steep gradients, dangerous traffic, headwinds, surface quality, e-bike range, sunset timing, resupply gaps, and more — with three severity levels (critical, warning, nudge). See the [alert engine](alert-engine.md).

**Accommodation finder** — Discovers bivouac spots, refuges, and gites near each stage endpoint via OpenStreetMap, with heuristic pricing estimates. See [supported accommodation tags](accommodations.md).

**Cultural points of interest** — Detects museums, monuments, castles, viewpoints, and other attractions along the route with an "add to itinerary" action.

**Real-time processing** — Async workers compute your trip in parallel; live status updates stream to the browser via Mercure SSE. No page reload needed.

**In-ride nearby search (no AI, no account key)** — On a stage, tap one of eight intents — water, shelter, food, resupply, bike shop, health, train station, or e-bike charging — and get the closest options ranked by distance and detour, with opening-hours status, a "closes soon" warning, and a one-tap handoff to your maps app. It reads the local map index directly (no LLM, no provider token), and your GPS position is sent in the request body only, never in a URL.

**Multi-format export** — Export enriched GPX files with waypoints for accommodation, water points, and POIs — ready for your GPS device. Download per-stage FIT files for Garmin, or generate a text roadbook summary.

**Your account, your data** — Passwordless magic-link sign-in. Export all your data as JSON or irreversibly delete your account at any time. Privacy-friendly, cookieless analytics (self-hosted Plausible) — no third-party trackers.

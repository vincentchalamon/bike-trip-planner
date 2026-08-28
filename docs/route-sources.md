# Supported route sources

A trip starts from a route. The planner accepts a URL from one of the platforms below, or a
direct GPX upload. Anything else is rejected before any fetch.

| Platform | Supported URL formats |
|---|---|
| **Komoot** | `komoot.com/[xx-xx/]tour/123` and `komoot.com/[xx-xx/]collection/123` |
| **Strava** | `strava.com/routes/123` |
| **RideWithGPS** | `ridewithgps.com/routes/123` |
| **GPX upload** | Direct file upload (up to 30 MB) |

The optional `xx-xx/` segment is a locale prefix (e.g. `en-gb/`, `fr-fr/`); `123` is the numeric
tour, collection, or route id.

!!! note "Why URLs are validated up front"
    Each URL is matched against a strict per-platform pattern and fetched through an HTTP client
    scoped to that platform's base URI (max 2 redirects, 10 s timeout). Free-form URLs are refused,
    which prevents server-side request forgery (SSRF). GPX parsing is hardened against XXE. See
    [ADR-011](adr/adr-011-security-input-validation-and-ssrf-prevention-for-gpx-url-ingestion.md).

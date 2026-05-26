# Runbook — Incident severity levels

Severity classification used by the alerting pipeline (GlitchTip, Uptime Kuma
and UptimeRobot → `repository_dispatch` → [`incident-create.yml`](../../.github/workflows/incident-create.yml)).
Each level maps to a GitHub label (`severity-p1`, `severity-p2`, `severity-p3`)
applied automatically when an incident issue is opened.

## Levels

### P1 — Critical, page now

User-facing outage or imminent data risk. Acknowledge within 15 minutes,
target restoration under 60 minutes.

Triggers:

- Uptime alert on `/api/healthz` (app down / VM down)
- Uptime alert on the homepage (PWA unreachable)
- Error volume spike: more than 50 occurrences of the same fingerprint in the
  last alerting window
- Error matching the keyword list `database`, `connection refused`,
  `out of memory`, `panic`
- Error level `fatal` or `critical`

### P2 — Degraded, fix today

Service still reachable but a dependency is impaired or a worker is failing.
Acknowledge within 2 hours, target restoration end of day.

Triggers:

- Uptime alert on `/api/health` (composite dependency check) without `/healthz`
  being down
- Uptime alert on an optional dependency (Ollama, Valhalla, Overpass)
- Worker stuck in a failure retry loop
- Error level `error` not matching the P1 keyword list

### P3 — Warning, fix this sprint

Non-blocking signal: business validation warnings, deprecations, recovery
notifications.

Triggers:

- Error level `warning`, `info`, or `deprecation`
- Recovery events (`status: up`) from any monitor
- Anything not matched by P1 or P2 rules (default fallback)

## Routing automatique

The workflow [`incident-create.yml`](../../.github/workflows/incident-create.yml)
applies these rules in order:

| Event type     | Signal                                                                                  | Severity |
| -------------- | --------------------------------------------------------------------------------------- | -------- |
| `error_alert`  | `level ∈ {fatal, critical}` OR `count > 50` OR title matches critical keywords          | P1       |
| `error_alert`  | `level == error` (default for errors)                                                   | P2       |
| `error_alert`  | other levels (`warning`, `info`, …)                                                     | P3       |
| `uptime_alert` | `status == down` AND monitor targets `/api/healthz` or homepage                         | P1       |
| `uptime_alert` | `status == down` AND monitor targets `/api/health` or an optional dep (Ollama/Valhalla) | P2       |
| `uptime_alert` | `status == down` on any other monitor                                                   | P2       |
| `uptime_alert` | `status != down` (recovery / warning)                                                   | P3       |

### Payload to label mapping

```text
client_payload  ──► event_fingerprint = sha256(culprit::title)[:12]   (error_alert)
                ──► event_fingerprint = "<monitor>-<status>"           (uptime_alert)
                ──► severity = rule table above
                ──► labels = ["incident", "severity-p<n>", "event-fp:<fingerprint>"]
```

Issues are deduplicated by searching open issues with the `event-fp:<fingerprint>`
label: a match means a comment is appended with the new occurrence timestamp
and workflow run URL instead of opening a duplicate issue.

See [`docs/runbooks/incident-alerting.md`](./incident-alerting.md) for the full
architecture, payload schemas, and webhook configuration.

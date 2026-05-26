# Runbook — Incident alerting via GitHub Issues

End-to-end documentation for the auto-incident pipeline that turns external
monitoring alerts into deduplicated GitHub issues, pushed to maintainers via
the official GitHub mobile app.

## Architecture

```text
GlitchTip ─────────┐
Uptime Kuma ───────┼──► POST /repos/.../dispatches (Bearer INCIDENT_DISPATCH_TOKEN)
UptimeRobot ───────┘                 │
                                     ▼
              GitHub repository_dispatch event
                                     │
                                     ▼
                .github/workflows/incident-create.yml
                                     │
                  ┌──────────────────┴──────────────────┐
                  ▼                                     ▼
        existing open issue                  no existing issue
        with event-fp:<hash> label           with that label
                  │                                     │
                  ▼                                     ▼
        comment with new occurrence          create issue with labels
                                             [incident, severity-pN,
                                              event-fp:<hash>]
                                     │
                                     ▼
                       GitHub mobile push notification
```

- **Workflow source:** [`.github/workflows/incident-create.yml`](../../.github/workflows/incident-create.yml)
- **Severity rules:** [`severity-levels.md`](./severity-levels.md)
- **Triggering services:** GlitchTip ([`docs/adr/adr-031-error-tracking-strategy.md`](../adr/adr-031-error-tracking-strategy.md)),
  Uptime Kuma + UptimeRobot ([`uptime-monitoring.md`](./uptime-monitoring.md))

## Authentication & secrets

External services call the GitHub REST API
`POST /repos/vincentchalamon/bike-trip-planner/dispatches` with a bearer token.

- **Secret name:** `INCIDENT_DISPATCH_TOKEN` (already provisioned)
- **Type:** Fine-grained PAT
- **Scopes:** `Contents: read` + `Metadata: read` (required by GitHub) plus
  `Administration > Repository dispatch: write` on this repository only
- **Storage:** GitHub Actions secrets _and_ injected into GlitchTip / Uptime
  Kuma / UptimeRobot webhook configuration UIs
- **Rotation:** every 90 days. Calendar reminder in the on-call doc

The workflow itself authenticates with the built-in `GITHUB_TOKEN` (scope
`issues: write`); it does **not** read `INCIDENT_DISPATCH_TOKEN`.

### Rotating `INCIDENT_DISPATCH_TOKEN`

1. GitHub → Settings → Developer settings → Fine-grained tokens → generate new
   token, same scopes, 90-day expiry.
2. Update the secret in Settings → Secrets and variables → Actions.
3. Update the bearer token in:
   - GlitchTip → project Settings → Alerts → webhook integration
   - Uptime Kuma → Settings → Notifications → Webhook
   - UptimeRobot → My Settings → Integrations & API → Webhook
4. Trigger a test dispatch (see below) and confirm an issue is opened.
5. Revoke the old token.

## Expected payload schemas

### `error_alert` (GlitchTip)

GlitchTip is Sentry-webhook-compatible; the parser is tolerant of missing
fields and falls back to `unknown`.

```json
{
  "event_type": "error_alert",
  "client_payload": {
    "issue": {
      "title": "RuntimeException: Redis connection refused",
      "culprit": "App\\MessageHandler\\ComputeStageHandler::__invoke",
      "level": "error",
      "count": 12,
      "web_url": "https://errors.biketrip.mooo.com/issues/4242",
      "tags": { "request_id": "0192c0d8-7e3a-7000-9f3a-4f6d5b2c8a91" }
    },
    "environment": "production",
    "release": "abc1234"
  }
}
```

Fingerprint: `sha256("<culprit>::<title>")[:12]`.

### `uptime_alert` (Uptime Kuma / UptimeRobot)

```json
{
  "event_type": "uptime_alert",
  "client_payload": {
    "monitor_name": "biketrip-healthz",
    "monitor_url": "https://biketrip.mooo.com/api/healthz",
    "status": "down",
    "message": "Connection timed out after 10s",
    "heartbeat": { "ping": 10000 }
  }
}
```

UptimeRobot field aliases (`monitorFriendlyName`, `alertType`,
`alertDetails`, `responseTime`) are accepted as fallbacks.

Fingerprint: `<monitor_name>-<status>` lowercased, non-alphanumerics replaced
with `-`, truncated to 48 chars (e.g. `biketrip-healthz-down`).

## Manual test

Replace `<TOKEN>` with `INCIDENT_DISPATCH_TOKEN` (local copy, never commit).

```bash
# Error alert
curl -X POST \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches \
  -d '{
    "event_type": "error_alert",
    "client_payload": {
      "issue": {
        "title": "Manual test error",
        "culprit": "App\\Tests\\ManualDispatch",
        "level": "error",
        "count": 1
      },
      "environment": "staging"
    }
  }'

# Uptime alert
curl -X POST \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches \
  -d '{
    "event_type": "uptime_alert",
    "client_payload": {
      "monitor_name": "manual-test",
      "monitor_url": "https://biketrip.mooo.com/api/healthz",
      "status": "down",
      "message": "Manual dispatch test"
    }
  }'
```

A new incident issue should appear within ~30 seconds. Re-running the same
command must comment on the existing issue (no duplicate).

## Webhook configuration per service

### GlitchTip

1. Open the GlitchTip project → **Settings** → **Alerts**.
2. **Create alert** → trigger condition (e.g. "An event is seen", frequency
   1 min, environment `production`).
3. Action type **Webhook**.
4. URL: `https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches`.
5. Method `POST`, headers:
   - `Accept: application/vnd.github+json`
   - `Authorization: Bearer <INCIDENT_DISPATCH_TOKEN>`
   - `Content-Type: application/json`
6. Body template (GlitchTip supports raw JSON; adjust placeholders to project
   syntax):

   ```json
   {
     "event_type": "error_alert",
     "client_payload": {
       "issue": {
         "title": "{{ issue.title }}",
         "culprit": "{{ issue.culprit }}",
         "level": "{{ issue.level }}",
         "count": {{ issue.count }},
         "web_url": "{{ issue.web_url }}"
       },
       "environment": "{{ environment }}",
       "release": "{{ release }}"
     }
   }
   ```

### Uptime Kuma

1. Uptime Kuma UI → **Settings** → **Notifications** → **Setup Notification**.
2. Type **Webhook**.
3. POST URL: `https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches`.
4. Request body: `Custom Body` with:

   ```json
   {
     "event_type": "uptime_alert",
     "client_payload": {
       "monitor_name": "{{ monitorJSON.name }}",
       "monitor_url": "{{ monitorJSON.url }}",
       "status": "{{ status }}",
       "message": "{{ msg }}",
       "heartbeat": { "ping": {{ heartbeatJSON.ping }} }
     }
   }
   ```

5. Additional headers: `Authorization: Bearer <INCIDENT_DISPATCH_TOKEN>`,
   `Accept: application/vnd.github+json`.
6. Attach the notification to every monitor (default behaviour when "Apply on
   all existing monitors" is checked).

### UptimeRobot

1. **My Settings** → **Integrations & API** → **Add Integration**.
2. Integration type **Web-Hook**.
3. URL to notify: `https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches`.
4. POST value (JSON):

   ```json
   {
     "event_type": "uptime_alert",
     "client_payload": {
       "monitor_name": "*monitorFriendlyName*",
       "monitor_url": "*monitorURL*",
       "status": "*alertTypeFriendlyName*",
       "message": "*alertDetails*",
       "responseTime": *responseTime*
     }
   }
   ```

5. Send as JSON: **Yes**. Custom HTTP headers:
   - `Authorization: Bearer <INCIDENT_DISPATCH_TOKEN>`
   - `Accept: application/vnd.github+json`
6. Attach the integration to the `/api/healthz` external monitor.

## Troubleshooting

- **No issue appears:** check the Actions tab for a failed
  `Incident — auto-create issue` run. The most common cause is a malformed
  `client_payload` JSON; the workflow logs the raw payload (truncated to
  500 chars) in the `Parse payload` step.
- **Duplicate issues for the same incident:** the fingerprint changed. Confirm
  the upstream service is sending stable `culprit` / `monitor_name` values.
- **HTTP 401 from `dispatches`:** the PAT expired. See "Rotating
  `INCIDENT_DISPATCH_TOKEN`".
- **HTTP 422 from `dispatches`:** `event_type` is not in the workflow's
  `types:` list (only `error_alert` and `uptime_alert` are accepted).

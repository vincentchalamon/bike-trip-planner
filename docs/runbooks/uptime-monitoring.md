# Uptime Monitoring

Two layers per the plan:

- **Uptime Kuma** self-hosted on the Oracle VM via Coolify — fine-grained probes against internal endpoints.
- **UptimeRobot** free tier (external, hors VM) — single probe against `/api/healthz`, the only signal that survives a full-VM outage.

Both forward alerts to GitHub through `repository_dispatch` (see `incident-create.yml` workflow, plan P1.3).

## Symptômes (when to use this runbook)

- Setting up monitoring for the first time
- A monitor fires falsely (false positive) or fails to fire (false negative)
- Adding a new probe after shipping a new endpoint or dependency
- Rotating the `INCIDENT_DISPATCH_TOKEN`

## Diagnostic

Self-hosted Uptime Kuma on `status.<domain>` — check the monitor list and recent history. UptimeRobot dashboard — check the single `/api/healthz` monitor status.

If alerts are silent, validate the dispatch token end-to-end:

```bash
curl -X POST \
  -H "Authorization: Bearer $INCIDENT_DISPATCH_TOKEN" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches \
  -d '{"event_type":"uptime_alert","client_payload":{"severity":"p3","monitor":"manual-test","message":"dispatch token check"}}'
```

A 204 response with no issue created indicates the workflow is misconfigured; a 401 indicates the token is wrong or expired.

## Procédure

### 1. Uptime Kuma — initial setup

1. Deploy via Coolify using image `louislam/uptime-kuma:latest`, expose on subdomain `status.<domain>` (Traefik auto-TLS).
2. Create the admin account on first visit.
3. Add the following monitors (TCP or HTTP):

   | Monitor | Type | Target | Interval | Notes |
   |---|---|---|---|---|
   | API liveness | HTTP(S) | `https://<host>/api/healthz` | 60 s | Expect 200 |
   | API readiness | HTTP(S) | `https://<host>/api/health` | 300 s | Keyword match `"status":"ok"` |
   | PWA home | HTTP(S) - keyword | `https://<host>/` | 300 s | Keyword: distinctive page string |
   | DNS | DNS | apex domain | 600 s | Resolver: 1.1.1.1 |
   | Mercure | HTTP(S) | `https://<host>/.well-known/mercure?topic=__healthcheck__` | 300 s | Custom: EventSource heartbeat or HEAD |

4. Configure notifications → Webhook → `POST https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches` with Bearer `INCIDENT_DISPATCH_TOKEN`, body `{"event_type":"uptime_alert","client_payload":{...}}`.
5. Enable the public status page at `status.<host>/status/public`.

### 2. UptimeRobot — initial setup

1. Sign up for free tier at <https://uptimerobot.com> (50 monitors, 5 min interval).
2. Add a single HTTP(S) monitor on `https://<host>/api/healthz`.
3. Add a webhook integration with the same GitHub `repository_dispatch` URL, body `{"event_type":"uptime_alert","client_payload":{"severity":"p1","monitor":"uptimerobot","message":"$monitorFriendlyName is $alertTypeFriendlyName"}}`.
4. Verify by pausing the monitor briefly — the issue should auto-open.

### 3. Token rotation (every 90 d)

1. GitHub → Settings → Developer settings → Personal access tokens (fine-grained) → regenerate `INCIDENT_DISPATCH_TOKEN` with scopes `Contents: write` + `Issues: write` and 90 d expiry.
2. Update the secret in Uptime Kuma webhook config and in UptimeRobot webhook config.
3. Trigger a manual test (curl above) to confirm.
4. Note the rotation date in the incident issue tracker (a recurring calendar reminder helps).

### 4. Adding a new monitor

- New backend dependency → add an Uptime Kuma probe and update the `/api/health` controller to include it.
- New PWA route critical for users → keyword monitor on Uptime Kuma.
- Never add the external dependency to UptimeRobot — keep that single probe focused on `/api/healthz` (the "is the VM alive" signal).

## Post-action

- All monitors green for 24 h after setup or change.
- A test webhook dispatch produced a real issue with the correct severity label.
- Rotation date recorded; next rotation calendared.
- Status page reachable from a clean network (mobile data) to confirm DNS and TLS.

## References

- `severity-levels.md` — mapping monitor → severity
- ADR-019 — Deployment infrastructure (Coolify hosting Uptime Kuma)
- Plan P1.2 / P1.3 — alerting and uptime monitoring design

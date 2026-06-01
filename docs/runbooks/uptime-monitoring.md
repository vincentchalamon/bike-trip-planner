# Runbook — Uptime monitoring

> **Beta posture (Sprint 34.5, issue #568):** during the restricted beta we run
> **only the external UptimeRobot monitor** on `/api/healthz`. The self-hosted
> Uptime Kuma stack (`.docker/uptime-kuma/`) is **not deployed** — its files
> stay in the repository for reversibility but no container runs on the VM. The
> single off-host probe on `/api/healthz` (which transitively pings Ollama via
> the readiness chain) is enough to detect a host-level outage and raise an
> incident. Re-enable Uptime Kuma later by deploying the stack and restoring the
> "Self-hosted" layer below. See [ADR-031](../adr/adr-031-error-tracking-strategy.md)
> for the parallel Sentry-SaaS beta posture.

Target (post-beta) two-layer uptime monitoring for Bike Trip Planner production:

1. **Self-hosted (Uptime Kuma)** on the Coolify VM — sub-minute detection,
   rich monitor types, public status page. Configuration documented in
   [`.docker/uptime-kuma/README.md`](../../.docker/uptime-kuma/README.md).
   **Not deployed in beta** (see banner above).
2. **External (UptimeRobot)** — single off-host probe that survives a full VM
   outage. **This is the only active layer in beta.** This document covers (2).

## Why two layers?

Uptime Kuma runs on the same Oracle Cloud Always Free VM as the application
(see [ADR-019](../adr/0019-hosting-coolify-oracle-cloud.md)). If the VM goes
down — kernel panic, network ACL change, Oracle reclaims the instance — Uptime
Kuma goes down with it and emits no alert. UptimeRobot's free tier runs from
external probes; a single HTTP monitor is enough to detect a host-level outage
and trigger the incident workflow.

## UptimeRobot setup

### Account

1. Create a free account on <https://uptimerobot.com> with the team alias
   `oncall@biketrip.mooo.com` (or any shared inbox). Free tier allows 50
   monitors at 5-minute intervals; we use one.
2. Enable **two-factor authentication** on the account.

### Monitor

**+ Add New Monitor**:

- **Monitor Type**: `HTTP(s)`
- **Friendly Name**: `biketrip-healthz`
- **URL**: `https://biketrip.mooo.com/api/healthz`
- **Monitoring Interval**: `5 minutes` (free tier minimum)
- **Monitor Timeout**: `30 seconds`
- **HTTP Method**: `GET`
- **Accepted Status Codes**: `200`
- **Alert Contacts**: see below

### Alert contact — GitHub `repository_dispatch` webhook

**My Settings → Alert Contacts → Add Alert Contact**:

- **Alert Contact Type**: `Webhook`
- **Friendly Name**: `github-incident-dispatch`
- **URL to Notify**:
  `https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches`
- **POST Value (JSON Format)**: enable
- **POST Value**:

  ```json
  {
    "event_type": "uptime_alert",
    "client_payload": {
      "source": "uptimerobot",
      "monitor": "biketrip-healthz",
      "status": "*alertTypeFriendlyName*",
      "url": "*monitorURL*",
      "details": "*alertDetails*"
    }
  }
  ```

- **Custom HTTP Headers** (UptimeRobot Pro feature; on free tier, encode the
  token in the URL query string as a fallback — see workaround below):

  ```json
  {
    "Authorization": "Bearer <INCIDENT_DISPATCH_TOKEN>",
    "Accept": "application/vnd.github+json",
    "X-GitHub-Api-Version": "2022-11-28"
  }
  ```

  > **Free tier workaround**: UptimeRobot free does not allow custom headers.
  > Route the webhook through a tiny relay (Cloudflare Worker or a
  > GitHub-hosted `workflow_dispatch` proxy) that adds the `Authorization`
  > header server-side. Document the relay URL in the Coolify secret store.
  > Until the relay exists, the UptimeRobot alert still fires by email; the
  > `repository_dispatch` automation is best-effort on free tier.

- **Enable notifications for**: `Down` and `Up`.

Attach the alert contact to the `biketrip-healthz` monitor.

### Verification

```bash
# Simulate a down event from a workstation (requires the PAT):
curl -fsS -X POST \
  -H "Authorization: Bearer $INCIDENT_DISPATCH_TOKEN" \
  -H "Accept: application/vnd.github+json" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches \
  -d '{"event_type":"uptime_alert","client_payload":{"source":"uptimerobot","monitor":"biketrip-healthz","status":"down"}}'
```

GitHub answers `204 No Content` on success. The future incident workflow
(#P1.3) listens on `repository_dispatch` events of type `uptime_alert`.

## Severity & escalation

| Monitor                                  | Source        | Severity | First responder         |
| ---------------------------------------- | ------------- | -------- | ----------------------- |
| `/api/healthz` (60 s)                    | Uptime Kuma   | P1       | On-call engineer        |
| `/api/healthz` (5 min, external)         | UptimeRobot   | P1       | On-call engineer        |
| `/api/health` (5 min)                    | Uptime Kuma   | P2       | On-call engineer        |
| Keyword `/`                              | Uptime Kuma   | P1       | On-call engineer        |
| DNS `A` record                           | Uptime Kuma   | P2       | Infra owner             |
| Mercure hub                              | Uptime Kuma   | P2       | Backend owner           |

UptimeRobot is intentionally redundant with Uptime Kuma #1. The duplicate alarm
is the trade-off: if both fire, the issue is real; if only UptimeRobot fires,
the VM (or Uptime Kuma) is the problem.

## Token rotation

`INCIDENT_DISPATCH_TOKEN` is a fine-grained GitHub PAT with
**Contents: Read & write** on `vincentchalamon/bike-trip-planner` only.

- Lifetime: 90 days (GitHub maximum for fine-grained PATs without SSO).
- Storage: Coolify secret store (for Uptime Kuma) + UptimeRobot alert contact
  (or relay env var).
- Rotation runbook: generate a new PAT, update both stores, send a test
  dispatch, then revoke the old PAT.

Never commit the token to the repository.

# Severity Levels

Definitions used by the alerting pipeline ([incident-alerting.md](incident-alerting.md)). The severity label drives notification urgency and response SLO. During the beta the alert sources are Sentry SaaS and UptimeRobot (ADR-039); GlitchTip and Uptime Kuma are the post-beta targets and are not deployed.

## P1 — Critical (user-facing outage)

Active user impact. The application is unusable for all or most users.

- `/api/healthz` returns non-2xx for more than 2 consecutive probes (≥ 60 s)
- PostgreSQL unreachable, in read-only mode, or disk full
- Every Messenger worker stuck or crashed (`WORKER_REPLICAS`, 2 by default): `/api/health` turns 503 with `deps.messenger.workers_alive = 0` (ADR-075)
- `php` container (FrankenPHP: Caddy + embedded Mercure + PHP) in a restart loop
- Error rate > 5 % per minute on any API route
- Oracle VM reclaimed or unreachable for more than 5 minutes

**SLO**: acknowledge < 15 min, mitigate < 60 min. Post-mortem mandatory.

GitHub label: `incident`, `severity-p1`.

## P2 — Major (degraded, non-blocking)

Service is up but a feature is degraded or one redundancy is lost.

- One Messenger worker stuck or in a retry loop while another still processes
- `/api/health` latency > 2 s (slow dependency, but green)
- Valhalla `/status` red: `/api/health` turns 503 and new trips cannot be routed (arguably P1 when it lasts)
- External API cache miss rate > 50 % for more than 30 min
- Redis memory > 80 % `maxmemory`
- Error-tracking event spike > 100 events/h for a single fingerprint

**SLO**: acknowledge < 1 h, mitigate < 4 h (business hours). Post-mortem optional.

GitHub label: `incident`, `severity-p2`.

## P3 — Minor (warning / hygiene)

No user impact. Captured for trend analysis.

- Recurring `validation_error` for the same user (UX issue, not an outage)
- Deprecation warnings in logs
- PHPStan / Rector / markdownlint failures on `main` (build-only)
- Backup job warning (succeeded but slow / partial)
- Mercure reconnect rate > expected baseline (clients flapping)

**SLO**: triage next business day. No post-mortem.

GitHub label: `incident`, `severity-p3`.

## Mapping

`incident-create.yml` computes the severity itself; a `severity` field in the dispatch
payload is ignored.

| Trigger source | Severity assigned by `incident-create.yml` |
|---|---|
| `uptime_alert`, status `down`, URL contains `/api/healthz` or monitor name contains `healthz` / `homepage` | P1 |
| `uptime_alert`, status `down`, any other monitor (including the post-deploy smoke test) | P2 |
| `uptime_alert`, any other status (e.g. `up`) | P3 |
| `error_alert`, level `fatal` / `critical`, count > 50, or title matching `database`, `connection refused`, `out of memory`, `panic` | P1 |
| `error_alert`, level `error` | P2 |
| `error_alert`, any other level | P3 |

Re-label the issue by hand when the automatic severity is wrong.

## References

- ADR-019 / ADR-061 — Deployment infrastructure (Oracle Always Free; Ansible + GitHub Actions SSH + Traefik + Cloudflare Tunnel)
- `incident-template.md` — post-mortem template

# Severity Levels

Definitions used by alerting workflows (`incident-create.yml`, GlitchTip webhooks, Uptime Kuma, UptimeRobot). The severity label drives notification urgency and response SLO.

## P1 — Critical (user-facing outage)

Active user impact. The application is unusable for all or most users.

- `/api/healthz` returns non-2xx for more than 2 consecutive probes (≥ 60 s)
- PostgreSQL unreachable, in read-only mode, or disk full
- All 5 Messenger workers stuck or crashed (no message processed > 5 min while queue depth > 0)
- Caddy / Mercure / PHP container in restart loop
- Error rate > 5 % per minute on any `/api/*` route
- Oracle VM reclaimed or unreachable for more than 5 minutes

**SLO**: acknowledge < 15 min, mitigate < 60 min. Post-mortem mandatory.

GitHub label: `incident`, `severity-p1`.

## P2 — Major (degraded, non-blocking)

Service is up but a feature is degraded or one redundancy is lost.

- One Messenger worker stuck or in a retry loop while the others process
- `/api/health` latency > 2 s (slow dependency, but green)
- Valhalla `/status` red — routing fallback unavailable, new trips cannot be computed
- External API cache miss rate > 50 % for more than 30 min
- Redis memory > 80 % `maxmemory`
- GlitchTip event spike > 100 events/h for a single fingerprint

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

| Trigger source | Default severity | Override |
|---|---|---|
| UptimeRobot `/api/healthz` red | P1 | — |
| Uptime Kuma `/api/health` red | P1 | P2 if only optional deps red |
| GlitchTip new issue, level=fatal | P1 | — |
| GlitchTip new issue, level=error | P2 | P1 if rate > 5/min |
| GlitchTip new issue, level=warning | P3 | — |
| `incident-create.yml` manual dispatch | from payload `severity` | — |

## References

- ADR-019 — Deployment infrastructure on Oracle Always Free + Coolify
- `incident-template.md` — post-mortem template

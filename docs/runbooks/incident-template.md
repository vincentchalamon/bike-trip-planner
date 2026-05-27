# Incident Post-Mortem Template

Copy this template into a new GitHub issue (label `incident` + `post-mortem`) within 48 h of any P1, optionally for P2. Keep blameless and factual.

## Summary

One paragraph, plain language: what broke, who was affected, how long, what fixed it.

- **Severity**: P1 / P2 / P3 (see `severity-levels.md`)
- **Start**: `YYYY-MM-DD HH:MM UTC`
- **Detection**: `YYYY-MM-DD HH:MM UTC` (how it was detected — Uptime Kuma, UptimeRobot, user report, GlitchTip…)
- **Mitigation**: `YYYY-MM-DD HH:MM UTC`
- **Resolution**: `YYYY-MM-DD HH:MM UTC`
- **Duration of user impact**: `Xh Ym`
- **Linked issues / PRs**: `#…`
- **GlitchTip event(s)**: `<event id + link>`
- **Correlation IDs**: `<request_id list>`

## Impact

- Users affected (estimated count or percentage of traffic)
- Features impacted (trip creation, routing, narrative, export…)
- Data integrity impact (lost, duplicated, stale)
- External dependencies impacted (Garmin Connect callback, etc.)

## Timeline

| Time (UTC) | Event |
|---|---|
| `HH:MM` | First symptom (objective signal, not "I noticed") |
| `HH:MM` | Alert fired (which source) |
| `HH:MM` | On-call ack |
| `HH:MM` | Hypothesis 1 ruled out |
| `HH:MM` | Mitigation applied |
| `HH:MM` | Service restored |
| `HH:MM` | All clear |

## Root cause

Plain explanation of the failure mode. Avoid blame; describe the system. Reference the contributing factors:

- Trigger (what changed: deploy, traffic, dependency, time-based)
- Latent condition (what was always fragile and finally broke)
- Why monitoring did not catch it earlier (if applicable)

## Mitigation

What was done to stop the bleeding (often imperfect, that is fine — capture it as-is).

## Resolution

What was done to fully restore service and clear the alerts.

## What went well

- …

## What went badly

- …

## Action items

| # | Action | Owner | Issue | Due |
|---|---|---|---|---|
| 1 | … | @… | `#…` | `YYYY-MM-DD` |

Each action item must be an issue in the GitHub project, not a wishlist entry.

## Lessons

One or two sentences distilled from this incident that should change either the code, the runbooks, the architecture, or the on-call practice. Append this to `docs/runbooks/severity-levels.md` if it changes the severity matrix.

## References

- `severity-levels.md`
- Relevant runbook(s) used during the incident

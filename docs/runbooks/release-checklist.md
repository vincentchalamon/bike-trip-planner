# Release Checklist

Run through this before merging to `main` (which triggers the production deploy via Coolify webhook). The list is intentionally short — anything longer ends up skipped.

## Symptômes (when to use)

- About to merge a PR targeting `main`
- Cutting a `v*` tag
- Manually triggering `deploy.yml`

## Diagnostic

A green release is one where every line below is checked. If any line is unknown, treat it as red.

## Procédure

### 1. CI green on the PR

- [ ] All required checks green on the PR (`make qa`, `make test-php`, `make test-e2e`, OpenAPI lint, security check)
- [ ] Claude code review acknowledged (auto-resolves threads when the fix is pushed)
- [ ] No `[skip ci]` or test-disabling change unless explicitly justified in the PR

### 2. Schema and migrations

- [ ] No destructive migration in this PR (`DROP COLUMN`, renamed column without alias, `TRUNCATE` on a populated table) — if there is, the previous release must already have stopped writing to that column
- [ ] `bin/console doctrine:migrations:list` on staging shows the migration applied cleanly
- [ ] `make typegen` was rerun if any DTO changed (frontend compiles against the regenerated types)

### 3. Smoke on staging

- [ ] Stack started cleanly on staging (`docker compose up -d`)
- [ ] `curl https://staging.../api/healthz` returns 200 with the staging commit SHA
- [ ] `curl https://staging.../api/health | jq` reports every dependency `ok`
- [ ] End-to-end trip creation through the PWA succeeded on staging (`make test-e2e` against staging if applicable)

### 4. Observability

- [ ] GlitchTip baseline event rate on staging matches main (no new fingerprint introduced by this PR)
- [ ] No new `console.error` added without going through `logger.error`
- [ ] PR description references any GlitchTip event ID or incident issue it closes

### 5. Documentation

- [ ] Updated ADR if architecture changed
- [ ] Updated `README.md` alert-engine table and `ALERT_RULE_MAP` if an alert rule changed (per `CLAUDE.md`)
- [ ] Updated `TRACKING.md` via this PR (never directly on `main`)
- [ ] Updated the relevant runbook if the operational behavior changed

### 6. Rollback readiness

- [ ] Previous green deployment image still available in Coolify (default — but verify if many releases shipped today)
- [ ] On-call available for the next 60 min (post-deploy smoke window)

## Post-action

- Merge → `deploy.yml` runs → Coolify pulls the new image → smoke test fires.
- If smoke fails, `incident-create.yml` opens a P1 issue automatically; follow `release-rollback.md`.
- Confirm the GlitchTip release page lists the new SHA with `environment: production`.
- Note the deploy in the channel or issue tracker for visibility.

## References

- `release-rollback.md` — what to do when this checklist was not enough
- ADR-019 — Coolify deployment workflow
- `CLAUDE.md` — repo conventions (commit format, TRACKING.md policy)

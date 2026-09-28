# Release Checklist

Run through this before cutting a `v*` tag (which triggers the production deploy — `deploy-prod` SSHes to the VM and rolls the stack, ADR-061). The list is intentionally short — anything longer ends up skipped.

## When to use

- Cutting a `v*` tag (the only thing that deploys to production; `deploy-prod` only accepts
  plain `vX.Y.Z` tags)

## Diagnosis

A green release is one where every line below is checked. If any line is unknown, treat it as red.

## Procedure

### 1. CI green on the PR

- [ ] All required checks green on the PR (`make qa`, `make test-php`, `make test-e2e`, OpenAPI lint, security check)
- [ ] Claude code review acknowledged (auto-resolves threads when the fix is pushed)
- [ ] No `[skip ci]` or test-disabling change unless explicitly justified in the PR

### 2. Schema and migrations

- [ ] No destructive migration in this PR (`DROP COLUMN`, renamed column without alias, `TRUNCATE` on a populated table) — if there is, the previous release must already have stopped writing to that column
- [ ] The PR preview (`https://pr-<n>.<domain>`, deployed by `deploy-preview`) booted, which means its migrations applied (`MIGRATIONS_ON_BOOT`); for a reference-schema change, remember previews share PG-reference and do not migrate it (ADR-060)
- [ ] `make typegen` was rerun if any DTO changed (frontend compiles against the regenerated types)

### 3. Smoke on a pre-production stack

There is no staging environment. Use the PR preview, or the local iso-prod recette stack
(`make start-recette`, then `make test-recette`).

- [ ] `curl https://pr-<n>.<domain>/api/healthz` returns 200 (it does not expose the commit SHA, SEC-011; check the deployed SHA in the `Deploy` run instead)
- [ ] `curl https://pr-<n>.<domain>/api/health | jq .status` returns `"ok"`
- [ ] End-to-end trip creation through the PWA succeeded on the preview or the recette stack
- [ ] Any new runtime variable is wired into `compose.yaml` for **both** `php` and `worker` **and** into `ansible/roles/app_deploy/templates/env.j2` + Vault (see [secrets-inventory.md](secrets-inventory.md)). The `php` entrypoint refuses to boot when `APP_SECRET`, `MERCURE_JWT_KEY` (at least 32 bytes), `REFRESH_TOKEN_ENC_KEY` or `OAUTH_ENCRYPTION_KEY` is missing or default, or when the OAuth keypair equals the JWT one (`.docker/php/entrypoint.sh`); an empty `ACCESS_REQUEST_HMAC_SECRET` boots but 500s every access-request call

### 4. Observability

During the beta, error tracking is **Sentry SaaS** (ADR-039); GlitchTip is the post-beta self-hosted target (ADR-031). Read the Sentry dashboard here.

- [ ] No new Sentry fingerprint introduced by the changes since the previous tag
- [ ] No new `console.error` added without going through `logger.error`
- [ ] PR description references any Sentry issue ID or incident issue it closes

### 5. Documentation

- [ ] Updated ADR if architecture changed
- [ ] Updated `App\Enum\AlertCode` and the `docs/alert-engine.md` table if an alert rule changed (per `CLAUDE.md`)
- [ ] Updated `TRACKING.md` via this PR (never directly on `main`)
- [ ] Updated the relevant runbook if the operational behavior changed

### 6. Rollback readiness

- [ ] Previous release's `vX.Y.Z` images still on GHCR (release tags are never pruned; they are the rollback target)
- [ ] On-call available for the next 60 min (post-deploy smoke window)

## Verification and follow-up

- Tag `v*` → `deploy.yml` runs `build-images` → `deploy-prod` SSHes to the VM and runs `docker compose --env-file /etc/bike-trip-planner/app.env -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always` → `smoke-test` probes `/api/healthz` and `/api/health`. The images it runs come from `PHP_IMAGE` / `PWA_IMAGE` in the prod env file, not from the tag (see the known gap in [release-rollback.md](release-rollback.md)).
- If the smoke test fails, it dispatches an `uptime_alert` and `incident-create.yml` opens an incident issue (P2); follow [release-rollback.md](release-rollback.md).
- Confirm the Sentry release page lists the new release with `environment: production`.
- Note the deploy in the channel or issue tracker for visibility.

## References

- `release-rollback.md` — what to do when this checklist was not enough
- ADR-019 / ADR-061 — deployment workflow (Ansible + GitHub Actions SSH `deploy-prod`)
- `CLAUDE.md` — repo conventions (commit format, TRACKING.md policy)

# Release Rollback

GHCR keeps images by SHA and by tag (the `build-images` job prunes to the 10 most recent versions per service). Rollback reverts the running stack to a previous tag's images by redeploying that tag; Doctrine migrations are not rolled back automatically.

## Symptômes

- Post-deploy smoke test failed (`curl /api/healthz` or `/api/health` red after a deploy)
- New error surge in GlitchTip whose first occurrence matches the deploy timestamp
- PWA reports a regression after a release (broken feature, JS errors, 5xx on a previously-working route)

## Diagnostic

Identify the offending release:

```bash
git log --oneline -10
```

Identify the live and previous tags:

1. GitHub → Actions → `Deploy` runs, or `git tag --sort=-creatordate | head` — confirm the tag currently live (also in the `commit` field of `/api/healthz`) and the previous green tag.
2. Note both `v*` tags: the offending one and the rollback target.

Inspect the last few migrations:

```bash
docker compose -p prod exec php bin/console doctrine:migrations:list | tail -20
```

Check GlitchTip releases page — confirm the new release SHA is associated with the spike.

## Procédure

1. **Redeploy the previous tag** (fast path — images already on GHCR, no rebuild):
    - **From CI:** GitHub → Actions → the `Deploy` run for the previous green tag → "Re-run jobs". `deploy-prod` SSHes to the VM and rolls the stack to that tag.
    - **From the VM** (if CI is unavailable):

      ```bash
      cd /opt/bike-trip-planner   # ${PROD_REPO_DIR}
      git fetch --tags --force
      git checkout --force <previous-tag>
      docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always
      ```

2. **Verify the smoke test**:

    ```bash
    curl -sS https://<prod-host>/api/healthz
    curl -sS https://<prod-host>/api/health | jq
    ```

3. **Handle migrations**. Doctrine migrations are forward-only by default. Three scenarios:

    - **Additive migration only** (new column, new table) — leave the schema as-is. The old image ignores the new column; verify there is no NOT NULL without default that would break inserts.
    - **Destructive migration shipped** (dropped column, renamed table) — the old image will crash. Revert the schema manually:

      ```bash
      docker compose -p prod exec php bin/console doctrine:migrations:execute --down "DoctrineMigrations\\VersionYYYYMMDDHHMMSS"
      ```

      Only attempt this if a `down()` exists; otherwise restore from the most recent PostgreSQL backup.

    - **Data migration** (UPDATE rows) — generally non-reversible; assess data loss and decide whether to keep the new image patched-forward instead of rolling back.

4. **Inform users** via the status page if downtime exceeded 5 min.

5. **Open a follow-up issue** linking the failing PR. The PR template (`PULL_REQUEST_TEMPLATE.md`) requires the GlitchTip event ID and the incident issue link for the fix.

## Post-action

- Application back on the previous green SHA, smoke test green.
- GlitchTip release page shows the regression confined to the rolled-back release.
- Issue auto-created by `incident-create.yml` is updated with the rollback timestamp and the linked offending PR.
- Migration policy reviewed in the post-mortem: destructive migrations must follow the 2-release rule (add → migrate code → drop deprecated) per the migrations ADR.

## References

- ADR-019 / ADR-061 — Deployment infrastructure (GitHub Actions SSH deploy + `docker compose -p prod`)
- `release-checklist.md` — pre-release checks that should have caught it
- `incident-template.md` — post-mortem template

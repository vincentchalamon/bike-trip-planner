# Release Rollback

Coolify keeps the N most recent built images per service. A 1-click rollback reverts containers to a previous image; Doctrine migrations are not rolled back automatically.

## Symptômes

- Post-deploy smoke test failed (`curl /api/healthz` or `/api/health` red after a deploy)
- New error surge in GlitchTip whose first occurrence matches the deploy timestamp
- PWA reports a regression after a release (broken feature, JS errors, 5xx on a previously-working route)

## Diagnostic

Identify the offending release:

```bash
git log --oneline -10
```

In Coolify:

1. Application → Deployments tab → confirm the latest deployment finished and its commit SHA
2. Locate the previous green deployment (same view) and its SHA

Inspect the last few migrations:

```bash
docker compose exec php bin/console doctrine:migrations:list | tail -20
```

Check GlitchTip releases page — confirm the new release SHA is associated with the spike.

## Procédure

1. **Rollback containers via Coolify** (fast path, ~30 s):
   - Application → Deployments → previous green deployment → "Redeploy"
   - Coolify recreates containers from the cached image of that commit. No image rebuild needed.

2. **Verify the smoke test**:

   ```bash
   curl -sS https://<prod-host>/api/healthz
   curl -sS https://<prod-host>/api/health | jq
   ```

3. **Handle migrations**. Doctrine migrations are forward-only by default. Three scenarios:

   - **Additive migration only** (new column, new table) — leave the schema as-is. The old image ignores the new column; verify there is no NOT NULL without default that would break inserts.
   - **Destructive migration shipped** (dropped column, renamed table) — the old image will crash. Revert the schema manually:

     ```bash
     docker compose exec php bin/console doctrine:migrations:execute --down "DoctrineMigrations\\VersionYYYYMMDDHHMMSS"
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

- ADR-019 — Deployment infrastructure (Coolify rolling restart)
- `release-checklist.md` — pre-release checks that should have caught it
- `incident-template.md` — post-mortem template

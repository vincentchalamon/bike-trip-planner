# Release Rollback

GHCR keeps every image by commit SHA and, for releases, by `vX.Y.Z` tag. `build-images` prunes
each image to its 10 most recent versions but never deletes a `vX.Y.Z` one, so every release is
a rollback target. Rollback redeploys a previous tag; Doctrine migrations are not rolled back
automatically.

> **Known gap.** `deploy-prod` checks out the tag but does not pass image tags to compose: the
> images come from `PHP_IMAGE` / `PWA_IMAGE` in `/etc/bike-trip-planner/app.env`, which
> `ansible/group_vars/all.yml` sets to `:latest`, a tag `build-images` never pushes. Until the
> deploy pins images per tag, use the VM path below, which sets them explicitly.

## Symptoms

- Post-deploy smoke test failed (`/api/healthz` or `/api/health` red after a deploy; an
  incident issue is opened automatically)
- New error surge in Sentry whose first occurrence matches the deploy timestamp
- PWA reports a regression after a release (broken feature, JS errors, 5xx on a previously-working route)

## Diagnosis

Identify the live and previous tags:

1. GitHub → Actions → `Deploy` runs, or `git tag --sort=-creatordate | head`. `/api/healthz`
    does not expose the commit (SEC-011); on the VM, `git -C /opt/bike-trip-planner describe --tags`
    shows the checked-out tag.
2. Note both `v*` tags: the offending one and the rollback target.

Inspect the last few migrations (on the VM, `dc` alias from [README.md](README.md#conventions)):

```bash
dc exec php bin/console doctrine:migrations:list | tail -20
```

Check the Sentry releases page: confirm the new release is associated with the spike.

## Procedure

1. **Redeploy the previous tag** (images already on GHCR, no rebuild). On the VM:

    ```bash
    cd /opt/bike-trip-planner
    git fetch --tags --force
    git checkout --force <previous-tag>
    PHP_IMAGE=ghcr.io/vincentchalamon/bike-trip-planner-php:<previous-tag> \
    PWA_IMAGE=ghcr.io/vincentchalamon/bike-trip-planner-pwa:<previous-tag> \
      docker compose --env-file /etc/bike-trip-planner/app.env -p prod \
        -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always
    ```

    Shell variables take precedence over `--env-file`, so this pins both images to the tag.
    Re-running the `deploy-prod` job of the previous tag's `Deploy` run checks out that tag,
    but see the known gap above for the images.

2. **Verify the smoke test**:

    ```bash
    curl -sS https://<prod-host>/api/healthz
    curl -sS https://<prod-host>/api/health | jq
    ```

3. **Handle migrations**. Doctrine migrations are forward-only by default. Three scenarios:

    - **Additive migration only** (new column, new table) — leave the schema as-is. The old image ignores the new column; verify there is no NOT NULL without default that would break inserts.
    - **Destructive migration shipped** (dropped column, renamed table): the old image will crash. Revert the schema manually:

      ```bash
      dc exec php bin/console doctrine:migrations:execute --down "DoctrineMigrations\\VersionYYYYMMDDHHMMSS"
      ```

      Only attempt this if a `down()` exists; otherwise restore from the most recent PG-app backup ([ADR-062](../adr/adr-062-backup-and-disaster-recovery.md#restore-procedure)).

    - **Data migration** (UPDATE rows) — generally non-reversible; assess data loss and decide whether to keep the new image patched-forward instead of rolling back.

4. **Inform users** (GitHub issue or PWA banner) if downtime exceeded 5 min. There is no public status page in beta.

5. **Open a follow-up issue** linking the failing PR. The PR template (`.github/PULL_REQUEST_TEMPLATE.md`) asks the fix to link the error-tracking event ID and the incident issue.

## Verification and follow-up

- Application back on the previous green SHA, smoke test green.
- The Sentry release page shows the regression confined to the rolled-back release.
- Issue auto-created by `incident-create.yml` is updated with the rollback timestamp and the linked offending PR.
- Migration policy reviewed in the post-mortem: destructive migrations must follow the 2-release rule (add → migrate code → drop deprecated) per ADR-032.

## References

- ADR-019 / ADR-061 — Deployment infrastructure (GitHub Actions SSH deploy + `docker compose -p prod`)
- `release-checklist.md` — pre-release checks that should have caught it
- `incident-template.md` — post-mortem template

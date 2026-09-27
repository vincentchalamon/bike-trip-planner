# Secrets Rotation

Rotation policy for production secrets. Deliberately short: at this project's scale (one
environment, one operator, about twenty secrets), systematic calendar rotation costs more than
it returns. The default is **on-compromise**, except for the cases listed below.

The full inventory, with the Vault key of each secret, is in
[secrets-inventory.md](secrets-inventory.md).

## When to use

- Suspected leak (accidental commit, exposed log, shared screenshot, compromised workstation).
- GitHub Secret Scanning alert (GitHub notification, or
  `gh api repos/vincentchalamon/bike-trip-planner/secret-scanning/alerts`).
- A `severity-p1` incident involving the service or the operator holding the key.
- A calendar deadline (see the matrix below).

## Rotation matrix

| Secret | Default cadence | Rationale |
|---|---|---|
| JWT keypair + `JWT_PASSPHRASE` | On-compromise | Invalidates the live access tokens (15 min); clients get a new one through their refresh token. |
| OAuth keypair + `OAUTH_PASSPHRASE` | On-compromise | Invalidates the live agent access tokens; MCP clients refresh. |
| `OAUTH_ENCRYPTION_KEY` | On-compromise | Invalidates pending authorization codes and agent refresh tokens. |
| `APP_SECRET` | On-compromise | Invalidates CSRF tokens and signed URIs in flight. |
| `MERCURE_JWT_KEY` | On-compromise | Forces SSE clients to reconnect. |
| `REFRESH_TOKEN_ENC_KEY` | On-compromise | Stored refresh tokens become unreadable; users log in again. |
| `ACCESS_REQUEST_HMAC_SECRET` | On-compromise | Pending activation links stop verifying. |
| `AGE_RECIPIENT` (and its private key) | On-compromise | Re-encrypting the GFS retention is expensive. The private key is offline in Bitwarden, exposure is close to zero. |
| `B2_APPLICATION_KEY` | **Yearly** + on-compromise | Cloud standard, cheap to rotate. |
| OCI Object Storage keys | **Yearly** + on-compromise | Same. |
| `DATABASE_PASSWORD` | **Twice a year** + on-compromise | Compromise is rare in practice (isolated Docker network), but it is useful hygiene. |
| `REFERENCE_DATABASE_URL` / PG-reference superuser | On-compromise | Reachable only on the `btp-shared` Docker network. |
| `MAILER_DSN` (Brevo) | On-compromise | No structural reason to schedule it. |
| `FCM_SERVICE_ACCOUNT_JSON` | On-compromise | Same. |
| `SENTRY_DSN` / `SENTRY_AUTH_TOKEN` | On-compromise | The error-tracking project can be recreated. |
| Cloudflare Tunnel credentials | On-compromise | Recreate the tunnel credentials, update Vault, re-run the playbook. |
| `SSH_KEY` / `SSH_KNOWN_HOSTS` | On-compromise (key) / on VM host-key change | Deploy SSH key, only exposed to the `deploy.yml` jobs. New pair, public key into `deploy_ssh_public_keys`, re-run the playbook. |
| `INCIDENT_DISPATCH_TOKEN` | **90 days** | Fine-grained PAT, see [incident-alerting.md](incident-alerting.md) ("Rotating `INCIDENT_DISPATCH_TOKEN`"). |
| `CLAUDE_CODE_OAUTH_TOKEN` | Managed by Anthropic | Out of scope. |

## Common steps

Three steps recur in every procedure below.

**Update Vault** (from the `ansible/` directory on the operator workstation):

```bash
ansible-vault edit vault.yml
```

**Re-render the VM files** from Vault. The playbook is idempotent; it rewrites `app.env`, the
PEM files, the backup config and the tunnel credentials:

```bash
ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass
```

**Reload the app stack** on the VM (see [README.md](README.md#conventions) for the `dc` alias).
Changed env values recreate the affected containers; a changed PEM file does not, so force it:

```bash
cd /opt/bike-trip-planner
dc up -d                             # env-file change
dc up -d --force-recreate php worker # PEM change
```

Re-running the GHA `deploy-prod` job for the live tag, or `./deploy-prod.sh <live-tag>` on
the VM, has the same effect as `dc up -d`. Do not push a new tag just to reload secrets:
`deploy-prod` only accepts plain `vX.Y.Z` tags.

## Generic procedure (on-compromise)

For any secret without a specific procedure below:

1. **Revoke immediately** at the provider (Backblaze, Brevo, Firebase, Anthropic, GitHub
   PAT...). Cut access first, regenerate second.
2. **Generate a new value** with minimal scope (for example, restrict a B2 key to the
   `btp-backups` bucket). For a locally generated secret (`APP_SECRET`, `MERCURE_JWT_KEY`,
   `REFRESH_TOKEN_ENC_KEY`, `ACCESS_REQUEST_HMAC_SECRET`, `OAUTH_ENCRYPTION_KEY`), use
   `openssl rand -hex 32`.
3. **Update the source** listed in [secrets-inventory.md](secrets-inventory.md):
    - runtime secret: update Vault, re-render, reload the stack (see Common steps);
    - GitHub secret: `gh secret set <NAME>` or the repository settings UI.
4. **Verify**: `curl https://www.<domain>/api/healthz` returns 200 and
   `curl https://www.<domain>/api/health | jq .status` returns `"ok"`. For a CI secret, run
   the workflow that uses it.
5. **Record** in a comment on the linked incident issue (see
   [incident-template.md](incident-template.md)): who, when, which secret, why.

## Specific procedures

### JWT and OAuth keypairs

The two pairs rotate independently, but each one must stay different from the other: the
playbook and the `php` entrypoint both refuse identical pairs (ADR-079). Rotating a pair
invalidates the access tokens it signed; refresh tokens are opaque database rows, not signed
by these keys, so clients recover by refreshing. To force every user to log in again, also
rotate `REFRESH_TOKEN_ENC_KEY` (generic procedure); for agents, revoke their grants or rotate
`OAUTH_ENCRYPTION_KEY`.

1. On a trusted workstation, generate the new pair with the repository script (it encrypts
   the private key with `-aes256` and reads the passphrase from stdin, not the command line).
   Pass only the pair you are rotating:

    ```bash
    scripts/generate-keypairs.sh --jwt ./jwt --passphrase '<new passphrase>'
    # or
    scripts/generate-keypairs.sh --oauth ./oauth --passphrase '<new passphrase>'
    ```

2. Update Vault: paste the PEM blocks into `vault_jwt_private_key` / `vault_jwt_public_key`
   and the passphrase into `vault_jwt_passphrase` (or the `vault_oauth_*` equivalents). Then
   delete the local files.
3. Re-render and reload with `--force-recreate php worker` (see Common steps).
4. Verify: `/api/health` is `ok`, and a full magic-link login works (`POST /auth/request-link`
   answers 202, the emailed link logs you in).

### `age` recipient

Rotation **does not re-encrypt** the existing backups: too expensive, and the old private key
still decrypts old dumps as long as it is kept.

1. On a trusted workstation, offline if possible:

    ```bash
    age-keygen -o age-key-$(date +%Y%m%d).txt
    ```

2. In **Bitwarden vault**, **rename the current item** `bike-trip-planner / age private key`
   to `bike-trip-planner / age private key legacy YYYYMMDD` (the date it stopped being the
   current key). **Create a new item** `bike-trip-planner / age private key` (the canonical
   name is kept) holding the new private key. A restore always looks up the canonical name;
   the `legacy *` items are only used to restore older dumps and must be kept indefinitely.
3. Set `vault_age_recipient` to the new public key and re-run the playbook (it rewrites
   `/etc/bike-trip-planner/backup/backup.env`).
4. Force a backup: `make backup-now BACKUP_SSH=deploy@<vm>`. Check with
   `rclone ls b2:btp-backups/daily | tail -1` that the latest dump is newer than the rotation.
5. **Do not delete** old dumps before their natural GFS expiry.

### B2 application key

1. Backblaze B2 console -> Application Keys -> **Add a New Application Key**, scoped to
   `btp-backups` only, with `listFiles`, `readFiles`, `writeFiles`, `deleteFiles`.
2. Copy `keyID` and `applicationKey` (shown only once).
3. Set `vault_b2_account_id` (= keyID) and `vault_b2_application_key`, then re-run the
   playbook (it rewrites `/etc/bike-trip-planner/backup/rclone.conf`). The backup is a
   systemd one-shot (`btp-backup.timer`), so there is nothing to restart.
4. Validate: `make backup-now BACKUP_SSH=deploy@<vm>` succeeds and
   `rclone ls b2:btp-backups/daily` lists the new dump.
5. **Delete the old key** in the Backblaze console once the new one has worked.

### Database password (PG-app)

About 30 s of downtime. Do it off-peak.

1. On the VM, change the password inside the running database:

    ```bash
    cd /opt/bike-trip-planner
    dc exec database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "ALTER USER \"$POSTGRES_USER\" WITH PASSWORD '"'"'<NEW_PASSWORD>'"'"';"'
    ```

2. Set `vault_database_password`, re-render, then `dc up -d`: `php` and `worker` pick up the
   new `DATABASE_URL`. The backup runs `pg_dump` inside the database container and is not
   affected.
3. Verify: `curl https://www.<domain>/api/health | jq .deps.postgres` reports
   `"status": "ok"`.

## Verification and follow-up

- Update the "Rotation" column of [secrets-inventory.md](secrets-inventory.md) if a cadence
  changes or a secret is added or removed.
- If the rotation followed an incident, complete the post-mortem
  ([incident-template.md](incident-template.md)) and reference this procedure.
- For calendar rotations, create a reminder when rotating (personal calendar, or a GitHub
  issue such as `chore(security): rotate B2 key - due YYYY-MM`).

## Out of scope

- Programmatic rotation (cron, scheduler): not justified at this scale.
- Moving to a secrets manager (Bitwarden Secrets Manager, Doppler, HashiCorp Vault,
  Infisical): reconsider beyond three environments or more than one operator.

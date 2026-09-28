# Secrets Inventory

Single source of truth for every secret used by the production stack and by CI. Update it in
any PR that introduces or removes a secret (the PR template has a checkbox for it).

This is a **documentation** map only: no secrets-management SaaS (Doppler, Bitwarden Secrets
Manager...) is used. The runtime store is **Ansible Vault** (ADR-061): values are encrypted in
`ansible/vault.yml` (gitignored; template in `ansible/vault.yml.example`) and rendered onto the
VM by the playbook:

- the prod env file `/etc/bike-trip-planner/app.env` (template
  `ansible/roles/app_deploy/templates/env.j2`), passed to `docker compose -p prod` with
  `--env-file`;
- the JWT keypair under `/etc/bike-trip-planner/jwt/` and the MCP authorization-server keypair
  under `/etc/bike-trip-planner/oauth/`. These are **two distinct pairs, never the same one**:
  the playbook asserts they differ, and the `php` entrypoint compares the files and refuses to
  boot if they match (ADR-079);
- the backup config `/etc/bike-trip-planner/backup/` (`backup.env`, `rclone.conf`);
- the PG-reference compose file and the Cloudflare Tunnel credentials.

The nightly backup (ADR-062) only dumps the PG-app database. **Secrets are not in any backup**:
`vault.yml` plus the Vault password are what you need to rebuild the VM.

For rotation, see [secrets-rotation.md](secrets-rotation.md).

## Conventions

- **Source** = the system that holds the reference value. If it is lost anywhere else, recover
  it from here.
- **Bitwarden vault** means the personal Bitwarden Password Manager (Free plan), not Bitwarden
  Secrets Manager.
- **Vault key** = the variable name in `ansible/vault.yml`.

## Runtime secrets (consumed by the production stack)

| Name | Type | Source (Vault key) | Consumer | Rotation | Reference |
|---|---|---|---|---|---|
| JWT private key | PEM RSA, encrypted | `vault_jwt_private_key` -> `/etc/bike-trip-planner/jwt/private.pem` (compose secret `jwt_private_key`) | `php`, `worker` (LexikJWT) | On-compromise | ADR-023 |
| JWT public key | PEM RSA | `vault_jwt_public_key` -> `/etc/bike-trip-planner/jwt/public.pem` | `php`, `worker` | With the private key | ADR-023 |
| `JWT_PASSPHRASE` | Passphrase | `vault_jwt_passphrase` -> `app.env` | `php`, `worker` | With the private key | ADR-023 |
| OAuth private key | PEM RSA, encrypted | `vault_oauth_private_key` -> `/etc/bike-trip-planner/oauth/private.pem` (compose secret `oauth_private_key`) | `php`, `worker` (MCP authorization server) | On-compromise | ADR-079 |
| OAuth public key | PEM RSA | `vault_oauth_public_key` -> `/etc/bike-trip-planner/oauth/public.pem` | `php`, `worker` | With the private key | ADR-079 |
| `OAUTH_PASSPHRASE` | Passphrase | `vault_oauth_passphrase` -> `app.env` | `php`, `worker` | With the private key | ADR-079 |
| `OAUTH_ENCRYPTION_KEY` | Key encrypting OAuth authorization codes and refresh tokens at rest | `vault_oauth_encryption_key` -> `app.env` | `php`, `worker` | On-compromise (invalidates pending codes and agent refresh tokens) | ADR-079 |
| `APP_SECRET` | Symfony secret (CSRF, signed URIs, remember-me) | `vault_app_secret` -> `app.env` | `php`, `worker` | On-compromise | `compose.yaml` |
| `MERCURE_JWT_KEY` | HS256 key, at least 32 bytes | `vault_mercure_jwt_key` -> `app.env` | `php` (publisher, subscriber cookies, embedded Mercure hub) | On-compromise | SEC-004 |
| `REFRESH_TOKEN_ENC_KEY` | Refresh-token encryption key (libsodium) | `vault_refresh_token_enc_key` -> `app.env` | `php`, `worker` | On-compromise (invalidates encrypted refresh tokens, users log in again) | ADR-023 / ADR-052 / SEC-003 |
| `ACCESS_REQUEST_HMAC_SECRET` | HMAC-SHA256 secret | `vault_access_request_hmac_secret` -> `app.env` | `php`, `worker` | On-compromise (invalidates pending activation links) | ADR-029 / SEC-004 |
| `MAILER_DSN` | Brevo DSN (`brevo+api://KEY@default`, contains the API key) | `vault_mailer_dsn` -> `app.env` | `php`, `worker` | On-compromise | ADR-061 |
| `FCM_SERVICE_ACCOUNT_JSON` | Firebase service-account JSON (single line) | `vault_fcm_service_account_json` -> `app.env` | `php`, `worker` | On-compromise | ADR-058 |
| `DATABASE_USERNAME` | PG-app user | `vault_database_username` -> `app.env` | `php`, `worker`, `database` | Static | ADR-022 |
| `DATABASE_PASSWORD` | PG-app password | `vault_database_password` -> `app.env` | `php`, `worker`, `database` | Twice a year + on-compromise | ADR-022 |
| `DATABASE_NAME` | PG-app database name | `vault_database_name` -> `app.env` | `php`, `worker`, `database` | Static | ADR-022 |
| `REFERENCE_DATABASE_URL` | DSN of the read-only role on PG-reference (contains its password) | `vault_reference_database_url` -> `app.env` | `php`, `worker` | On-compromise | ADR-060 |
| PG-reference superuser | User, password, database name | `vault_reference_db_user`, `vault_reference_db_password`, `vault_reference_db_name` -> `/opt/shared-infra/pg-reference/compose.yaml` | `pg-reference` | On-compromise | ADR-060 |
| `SENTRY_DSN` | Error-tracking DSN (Sentry SaaS during the beta, ADR-039) | `vault_sentry_dsn` -> `app.env` | `php`, `worker`, `pwa` (server side) | On-compromise | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | Same, exposed to the browser bundle | `vault_next_public_sentry_dsn` -> `app.env`, and the GitHub secret of the same name (build arg) | `pwa` | Same as `SENTRY_DSN` | ADR-031 |
| Cloudflare Tunnel credentials | Tunnel credentials JSON (`TunnelSecret`) | `vault_cloudflared_tunnel_credentials` -> `/etc/cloudflared/credentials.json` | `cloudflared` | On-compromise | ADR-061 |
| GHCR pull token | PAT with `read:packages` (only if the images are private) | `vault_ghcr_token`, `vault_ghcr_username` | Docker on the VM | On-compromise | ADR-061 |
| `AGE_RECIPIENT` | `age` public key | `vault_age_recipient` -> `backup.env` | `btp-backup.service` (encrypts the dumps) | On-compromise (only the private key is sensitive) | ADR-062 |
| `age` private key | `age` private key | **Bitwarden vault** (never on the VM) | Operator, at restore time | On-compromise | ADR-062 |
| `B2_ACCOUNT_ID` / `B2_APPLICATION_KEY` | Backblaze application key | `vault_b2_account_id`, `vault_b2_application_key` -> `rclone.conf` | `btp-backup.service` (rclone) | **Yearly** + on-compromise | ADR-062 |
| OCI Object Storage keys | Customer Secret Key (S3-compatible) | `vault_oci_access_key_id`, `vault_oci_secret_access_key` (+ endpoint, region) -> `rclone.conf` | `btp-backup.service` (rclone, only if `oci` is in `backup_remotes`) | Yearly + on-compromise | ADR-062 |

Non-secret runtime values (`DOMAIN`, `TRUSTED_PROXIES`, `VALHALLA_BASE_URI`,
`MAILER_SENDER_EMAIL`, `CONTACT_EMAIL`, `ANDROID_APP_PACKAGE` /
`ANDROID_SHA256_CERT_FINGERPRINTS`, the image repositories) live in
`ansible/group_vars/all.yml`. The image tag is not configured anywhere: `btp-compose` takes
it from the release tag the checkout is on.

### Referenced by `compose.yaml` but not rendered by Ansible

These are wired in `compose.yaml` but absent from `env.j2`, so they are **empty in
production** today:

| Name | Consumer | Effect when empty |
|---|---|---|
| `DATATOURISME_FLUX_ID` / `DATATOURISME_APP_KEY` | `provisioner` | DataTourisme step skipped |
| `OPENAGENDA_DATASET` / `OPENAGENDA_API_KEY` (key optional, public export) | `provisioner` | OpenAgenda step skipped |

## CI/CD secrets (consumed by GitHub Actions)

| Name | Type | Consumer (workflow) | Rotation | Reference |
|---|---|---|---|---|
| `SSH_HOST` | Prod VM SSH host | `deploy.yml` (`deploy-prod`, `deploy-preview`, `teardown-preview`) | On VM change | ADR-061 |
| `SSH_USER` | Deploy SSH user | `deploy.yml` | On-compromise | ADR-061 |
| `SSH_KEY` | Deploy SSH private key | `deploy.yml` | On-compromise (new pair, public key into `deploy_ssh_public_keys`, re-run the playbook) | ADR-061 |
| `SSH_KNOWN_HOSTS` | Pinned SSH host key (optional; falls back to `ssh-keyscan`) | `deploy.yml` | On VM host-key change | ADR-061 |
| `PROD_REPO_DIR` | Checkout path on the VM (optional, default `/opt/bike-trip-planner`) | `deploy.yml` | Static | ADR-061 |
| `PREVIEW_REPO_ROOT` / `PREVIEW_ENV_FILE` | Preview checkout root and preview env file on the VM | `deploy.yml` (`deploy-preview`, `teardown-preview`) | Static | ADR-061 |
| `PROD_HEALTH_URL` | Smoke-test host (optional, default `https://www.bike-trip-planner.com`) | `deploy.yml` (`smoke-test`) | Static | ADR-061 |
| `INCIDENT_DISPATCH_TOKEN` | Fine-grained PAT (`Contents: read and write`) | `deploy.yml` (`smoke-test` opens an incident on failure); also configured in the external monitors | **90 days** | [incident-alerting.md](incident-alerting.md) |
| `SENTRY_AUTH_TOKEN` | Error-tracking org token | `deploy.yml` (`upload-sourcemaps`) | On-compromise | ADR-031 |
| `SENTRY_URL` / `SENTRY_ORG` / `SENTRY_PROJECT` | Metadata (not sensitive) | `deploy.yml` (`upload-sourcemaps`) | Static | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | Browser DSN | `deploy.yml` (`build-images` build arg, `upload-sourcemaps`) | Same as runtime | ADR-031 |
| `CLAUDE_CODE_OAUTH_TOKEN` | Anthropic OAuth token | `claude.yml`, `claude-code-review.yml` | Managed by Anthropic | `CLAUDE.md` |
| `GITHUB_TOKEN` | Built-in GHA token | All workflows | Managed by GitHub (per run) | - |

`mobile-apk.yml` uses no secret: the APK is debug-signed, and its `MOBILE_API_URL`,
`MOBILE_WEB_URL` and `CONTACT_EMAIL` inputs are repository **variables**, not secrets.

## Bootstrap (total loss)

When rebuilding from scratch (VM lost or re-provisioned):

1. Get `ansible/vault.yml` and its Vault password from the operator's workstation or
   password manager. They are the only copy of the runtime secrets.
2. Re-provision the VM with **Ansible** (`ansible-playbook -i inventory.ini playbook.yml
   --ask-vault-pass`, see [oracle-vm-reclaimed.md](oracle-vm-reclaimed.md)). The playbook
   renders `app.env`, both PEM keypairs, the backup config and the tunnel credentials.
3. Deploy the current tag (GHA `deploy-prod`, or `/opt/bike-trip-planner/deploy-prod.sh <tag>`).
   Until then `btp-compose` refuses to run: the fresh clone is not on a release tag.
4. Restore PG-app data: fetch the `age` private key from **Bitwarden vault** (canonical item
   `bike-trip-planner / age private key`; rotation keeps that name for the current key and
   renames the old one `... legacy YYYYMMDD`, see [secrets-rotation.md](secrets-rotation.md)),
   then follow the restore procedure in
   [ADR-062](../adr/adr-062-backup-and-disaster-recovery.md#restore-procedure).
5. CI secrets live in GitHub and are unaffected by a VM loss. If one must be recreated,
   regenerate it at its provider (Brevo, Backblaze, Anthropic...).

If `vault.yml` itself is lost, every runtime secret must be regenerated at its provider or
with `scripts/generate-keypairs.sh`, see [secrets-rotation.md](secrets-rotation.md).

## Out of scope

- Development secrets (`.env`, `.env.local`, `.env.test`): not sensitive; the dev keypairs are
  generated by `make start-dev` / the dev entrypoint.
- Preview env file (`PREVIEW_ENV_FILE` on the VM): must hold preview-only values, never the
  prod secrets. Ansible does not provision it yet (TODO in `deploy.yml`); without it,
  `deploy-preview` fails closed.

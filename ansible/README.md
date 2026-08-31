# Ansible — Oracle ARM VM provisioning

Reproducible, idempotent bootstrap of the single Oracle Cloud Always-Free ARM
VM that runs bike-trip-planner **without Coolify** (per the deployment plan,
Coolify removal / W9). Replaces the
manual procedure in [`../docs/runbooks/oracle-vm-reclaimed.md`](../docs/runbooks/oracle-vm-reclaimed.md):
both a first bootstrap and a full reclaim recovery are the same single command.

## Topology

```text
Internet ──TLS──> Cloudflare edge ──Tunnel (outbound only)──> cloudflared
                                                                  │  (edge network)
                                                                  ▼
                                                               Traefik  :80  (Docker provider, no ACME)
                                                                  │  (edge network, routes by container labels)
                                                                  ▼
                                     prod stack: php / worker / pwa / PG-app / redis   (docker compose -p prod)
                                     preview stacks: pr-N.* (docker compose -p pr-N)
                                                                  │  (btp-shared network)
                                                                  ▼
                                     shared: Valhalla (serve-only) + PG-reference (PostGIS)
```

The VM has **no open public port**: all ingress arrives over the outbound
Cloudflare Tunnel, so Cloudflare Access is non-bypassable (plan W7.2). Traefik
and cloudflared publish no host ports.

## What this playbook does (roles)

| Role | Responsibility |
|------|----------------|
| `common` | Timezone, base packages, unattended-upgrades, `deploy` user + SSH keys, `/etc/bike-trip-planner{,/jwt}`, optional anti-reclaim heartbeat timer |
| `docker` | Docker Engine + compose plugin (arm64), `edge` + `btp-shared` networks, optional GHCR login |
| `traefik` | Traefik as the single reverse proxy — Docker provider, plain HTTP, **no ACME**, no published ports |
| `cloudflared` | Cloudflare Tunnel with a **locally-managed `config.yml`** (wildcard `*.${DOMAIN}` + `www.${DOMAIN}` -> `http://traefik:80`), credentials from Vault |
| `app_deploy` | Repo checkout (for compose bind mounts), prod `.env` + JWT PEM from Vault, `deploy-prod.sh` hook, optional events-refresh timer |
| `shared_infra` | Shared Valhalla (`deploy/valhalla/compose.yaml`, project `valhalla-shared`) + PG-reference (PostGIS) on `btp-shared`, seed `valhalla-tiles` volume from a shipped tar |

> **Backup role = PR-G / W10.** The `playbook.yml` carries a
> `TODO(PR-G / W10)` where `- role: backup` will be added (pg_dump PG-app ->
> `age` -> `rclone` to B2/OCI). It is intentionally omitted here so PR-I and
> PR-G do not collide on `playbook.yml`.

## Prerequisites (on your workstation)

```bash
ansible-galaxy collection install -r requirements.yml
```

## Steps done OUTSIDE Ansible

These are not automated (no OCI/Cloudflare provider here; optional `terraform/`
could cover the OCI half later — plan W5.3):

1. **OCI instance** — create a `VM.Standard.A1.Flex` (4 OCPU / 24 GB), Ubuntu
   ARM64, **without a reserved public IP** (the tunnel is outbound-only). Handle
   `Out of host capacity` on A1 by retrying / changing availability domain or
   region; use PAYG only with a `$0` budget alert. For the very first SSH, use
   the OCI serial console or a temporary public IP (remove it afterwards).
2. **Cloudflare (plan W8)** — domain at Cloudflare Registrar; create the named
   tunnel (`cloudflared tunnel login && cloudflared tunnel create bike-trip-planner`),
   note the tunnel UUID + `<UUID>.json` credentials; DNS `www` + `*` CNAME to
   `<UUID>.cfargotunnel.com` (proxied), apex -> `www` redirect; Universal SSL;
   Cloudflare Access apps (`www` UI, bypass `/api/*` + `/.well-known/*`,
   wildcard owner-only with `www` excluded); Email Routing + Brevo DKIM/SPF/DMARC.
3. **Routing tiles (plan W6)** — build off-VM and ship with
   `make routing-publish france belgium`; put the resulting tar on the VM and
   set `valhalla_tiles_tar` before re-running (or seed once, manually).
4. **GHA SSH access** — expose SSH through a Cloudflare Access `ssh` app so the
   `deploy-prod` / `deploy-preview` jobs (plan W3/W4, delivered by PR-C/PR-E)
   can reach the `deploy` user. Put those public keys in `deploy_ssh_public_keys`.

## Configure

```bash
cp inventory.example inventory.ini      # fill in host + SSH connection
$EDITOR group_vars/all.yml              # set domain, deploy_ssh_public_keys, tunnel id, images…

cp vault.yml.example vault.yml          # fill in REAL secrets
ansible-vault encrypt vault.yml         # encrypt in place (edit later: ansible-vault edit vault.yml)
```

`vault.yml` relocates **every** runtime secret that used to live in Coolify
(see `../docs/runbooks/secrets-inventory.md`, updated by plan W9): JWT keypair +
passphrase, `MERCURE_JWT_KEY`, `REFRESH_TOKEN_ENC_KEY`,
`ACCESS_REQUEST_HMAC_SECRET`, `FCM_SERVICE_ACCOUNT_JSON`, `MAILER_DSN` (Brevo),
DB creds, `REFERENCE_DATABASE_URL`, `SENTRY_DSN`, and the Cloudflare Tunnel
credentials. `VALHALLA_BASE_URI` is a non-secret in `group_vars/all.yml`.

> **`.env` gotcha:** docker compose interpolates `$` in `.env`. If a secret
> value contains a literal `$`, double it as `$$` in `vault.yml`.
> **FCM JSON** must be a single line (`jq -c . key.json`) — `.env` is line-oriented.

## Run

```bash
ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass
```

Idempotent — re-run any time. A reclaimed / rebuilt VM is recovered by running
this exact command against the fresh host.

## Deploy hook (who brings the app up)

Ansible provisions the host and the **shared** infra (Traefik, cloudflared,
Valhalla, PG-reference) but does **not** bring the app stack up. The prod app is
deployed by GHA `deploy-prod` at cutover (first `v*` tag; plan W4 / PR-C), which
SSHes in and runs the equivalent of the rendered `{{ app_dir }}/deploy-prod.sh`:

```bash
cd /opt/bike-trip-planner
git checkout refs/tags/<tag>
docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d
```

`deploy/prod/compose.yaml` is delivered by PR-C.

## Validation status

This is infra-as-code with no repo build/test leg. YAML well-formedness of
every `*.yml` was verified with PyYAML in the worktree. `ansible-lint` and
`ansible-playbook --syntax-check` were **not** run here (Ansible is not
installed in this environment and cannot be pip-installed without network); the
operator / CI must run them:

```bash
ansible-galaxy collection install -r requirements.yml
ansible-lint
ansible-playbook -i inventory.ini playbook.yml --syntax-check
```

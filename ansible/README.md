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
                                     preview stacks: pr-N.* (docker compose -p pr-N, via edge-preview)
                                                                  │  (btp-shared / btp-shared-preview)
                                                                  ▼
                                     shared: Valhalla (serve-only) + PG-reference (PostGIS)
```

All **HTTP** ingress arrives over the outbound Cloudflare Tunnel, so Cloudflare
Access is non-bypassable for the web (plan W7.2): Traefik and cloudflared publish
no host ports. **SSH is the exception.** The GHA deploy jobs connect straight to
`SSH_HOST` on port 22 (`deploy.yml`, plain `ssh`, no `cloudflared access`), so
the VM needs a public address with 22 reachable from GitHub-hosted runners. The
`hardening` role keeps that surface small (key-only sshd, `AllowUsers`, an
nftables filter, sshguard); [Restrict SSH](#restrict-ssh) narrows who can reach it.

Prod and previews never share a network (ADR-061 addendum): prod php sits on
`edge` + `btp-shared`, preview php on `edge-preview` + `btp-shared-preview`, and
only Traefik, Valhalla and PG-reference join both sides. Traefik trusts
`X-Forwarded-For` from the fixed `edge` subnet (`edge_subnet`, cloudflared) only.

## What this playbook does (roles)

| Role | Responsibility |
|------|----------------|
| `common` | Timezone, base packages, unattended-upgrades, `deploy` user + SSH keys, `preview-deploy` user + forced-command SSH keys (both lists exclusive: a key removed from the list is revoked), `/etc/bike-trip-planner{,/jwt}`, optional anti-reclaim heartbeat timer |
| `hardening` | sshd drop-in (`00-btp-hardening.conf`: keys only, no root, `AllowUsers` = `ssh_operator_users` + the two deploy users, checked with `sshd -t`), host nftables INPUT filter (`btp-firewall.service`, table `inet btp_host`; port 22 from `ssh_allowed_cidrs` only; Docker's tables untouched), sshguard |
| `docker` | Docker Engine + compose plugin (arm64), `edge` / `btp-shared` (prod) and `edge-preview` / `btp-shared-preview` (previews) networks, optional GHCR login |
| `traefik` | Traefik as the single reverse proxy on both edge networks: Docker provider through a read-only socket proxy (`tecnativa/docker-socket-proxy`, GET on containers/events only), plain HTTP, **no ACME**, no published ports |
| `cloudflared` | Cloudflare Tunnel with a **locally-managed `config.yml`** (wildcard `*.${DOMAIN}` + `www.${DOMAIN}` -> `http://traefik:80`), credentials from Vault |
| `app_deploy` | Repo checkout (first clone only, for compose bind mounts), prod env file (`/etc/bike-trip-planner/app.env`) + JWT PEM from Vault, release records dir, `btp-compose` wrapper, `deploy-prod.sh` hook, preview root + `main` checkout + `btp-preview` forced command, optional events-refresh timer |
| `shared_infra` | Shared Valhalla (`deploy/valhalla/compose.yaml`, project `valhalla-shared`) + PG-reference (PostGIS) on `btp-shared` and its read-only `reference_ro` app role (SELECT on `osm` + `tourism`), seed `valhalla-tiles` volume from a shipped tar |
| `backup` | Nightly PG-app backup: `pg_dump` -> `age` (recipient-only) -> `rclone` to B2 and/or OCI, GFS retention (pruned by the script, or by bucket lifecycle rules with a key that cannot delete), heartbeat ping + `OnFailure=` alert, via a systemd timer (`btp-backup.timer`). On-demand with `make backup-now`. PG-reference is NOT backed up (reproducible). See [ADR-062](../docs/adr/adr-062-backup-and-disaster-recovery.md) and [backup-restore.md](../docs/runbooks/backup-restore.md). |

## Prerequisites (on your workstation)

```bash
ansible-galaxy collection install -r requirements.yml
```

## Steps done OUTSIDE Ansible

These are not automated (no OCI/Cloudflare provider here; optional `terraform/`
could cover the OCI half later — plan W5.3):

1. **OCI instance**: create a `VM.Standard.A1.Flex` (4 OCPU / 24 GB), Ubuntu
   ARM64. Web ingress needs no public IP (the tunnel is outbound-only), but the
   GHA deploy jobs SSH straight to `SSH_HOST`, so give it a public IP and open
   only TCP 22 in its security list / NSG (same sources as `ssh_allowed_cidrs`).
   Handle `Out of host capacity` on A1 by retrying / changing availability
   domain or region; use PAYG only with a `$0` budget alert.
2. **Cloudflare (plan W8)** — domain at Cloudflare Registrar; create the named
   tunnel (`cloudflared tunnel login && cloudflared tunnel create bike-trip-planner`),
   note the tunnel UUID + `<UUID>.json` credentials; DNS `www` + `*` CNAME to
   `<UUID>.cfargotunnel.com` (proxied), apex -> `www` redirect; Universal SSL;
   Cloudflare Access apps (`www` UI, bypass `/api/*` + `/.well-known/*`,
   wildcard owner-only with `www` excluded); Email Routing + Brevo DKIM/SPF/DMARC.
3. **Routing tiles (plan W6)** — build off-VM and ship with
   `make routing-publish france belgium`; put the resulting tar on the VM and
   set `valhalla_tiles_tar` before re-running (or seed once, manually).
4. **GHA SSH access**: the `deploy-prod` / `deploy-preview` /
   `teardown-preview` jobs run plain `ssh` to `SSH_HOST` with the pinned
   `SSH_KNOWN_HOSTS` (mandatory). Two key pairs: the `production` environment's
   goes in `deploy_ssh_public_keys` (`deploy` user), the `preview` environment's
   in `preview_deploy_ssh_public_keys` (`preview-deploy` user, forced command
   `btp-preview`). The playbook refuses a key present in both lists. Both lists
   are exclusive: rotate by replacing the key in the list and re-running.

## Configure

```bash
cp inventory.example inventory.ini      # fill in host + SSH connection
$EDITOR group_vars/all.yml              # set domain, deploy_ssh_public_keys, tunnel id, image repos…

cp vault.yml.example vault.yml          # fill in REAL secrets
ansible-vault encrypt vault.yml         # encrypt in place (edit later: ansible-vault edit vault.yml)
```

`vault.yml` relocates **every** runtime secret that used to live in Coolify
(see `../docs/runbooks/secrets-inventory.md`, updated by plan W9): JWT keypair +
passphrase, `APP_SECRET`, `MERCURE_JWT_KEY`, `REFRESH_TOKEN_ENC_KEY`,
`ACCESS_REQUEST_HMAC_SECRET`, `FCM_SERVICE_ACCOUNT_JSON`, `MAILER_DSN` (Brevo),
DB creds, the PG-reference owner and read-only (`vault_reference_ro_password`,
from which `env.j2` derives `REFERENCE_DATABASE_URL`) passwords, the
DataTourisme / OpenAgenda feed credentials, `SENTRY_DSN`, the Cloudflare Tunnel
credentials, and the backup secrets (`AGE_RECIPIENT` public key, `B2_*`, `OCI_*`
— ADR-062). `VALHALLA_BASE_URI` is a non-secret in `group_vars/all.yml`.

> **Env-file gotcha:** docker compose interpolates `$` in the env file. If a
> secret value contains a literal `$`, double it as `$$` in `vault.yml`.
> **FCM JSON** must be a single line (`jq -c . key.json`) — the file is line-oriented.
>
> **Why `/etc/bike-trip-planner/app.env` and not `{{ app_dir }}/.env`:** the repo
> now versions a root `.env` (dev defaults), and `deploy-prod` does a forced
> checkout in `app_dir` — a prod env file there would be overwritten on every
> deploy. `btp-compose` passes this path with `--env-file`, which also stops
> compose auto-loading the repo's `.env`.

## Run

```bash
ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass
```

## Restrict SSH

Variables (`group_vars/all.yml`):

| Variable | Default | Effect |
|----------|---------|--------|
| `ssh_operator_users` | `[ansible_user]` (`ubuntu`) | Accounts besides the two deploy users that sshd lets in. Never `root` (asserted). |
| `ssh_allowed_cidrs` | `0.0.0.0/0`, `::/0` | Sources the host firewall accepts on port 22. Open by default because GitHub-hosted runners have no fixed address. |
| `ssh_allow_github_actions` | `false` | Adds the `actions` ranges of `https://api.github.com/meta`, fetched on each run. They change: re-run the playbook regularly (a deploy failing with a connection timeout is the symptom). They are shared by every GitHub customer, so this cuts scanner noise, not targeted attempts. |
| `ssh_tunnel_hostname` | `""` | Hostname of a Cloudflare Access SSH application. Set, cloudflared routes it to the host sshd (at `edge_gateway`) and the firewall accepts 22 from `edge_subnet`. |

The firewall is the `inet btp_host` nftables table, loaded by `btp-firewall.service`,
which filters the INPUT hook only. Docker's `iptables-nft` tables, and the container
traffic they route, are left untouched; `nftables.service` is not used because its
stop action flushes the whole ruleset, Docker's included. Already-open sessions survive
a rule change (established traffic is accepted first).

Three postures:

- **Default (open 22, key only)**: what `deploy.yml` works with today.
- **GitHub ranges + operator**: `ssh_allowed_cidrs: ["<your IP>/32"]`,
  `ssh_allow_github_actions: true`.
- **Tunnel only**: `ssh_allowed_cidrs: []`, `ssh_allow_github_actions: false`,
  `ssh_tunnel_hostname: ssh.<domain>`, and remove the public IP / port 22 rule in
  OCI. **Not usable as-is**: the deploy jobs would first have to go through
  `cloudflared access ssh` with an Access service token, which `deploy.yml` does not do.

Rollout order, so a mistake cannot lock you out:

1. Keep a way in that does not depend on sshd or the firewall: the OCI **serial
   console** (Compute -> Instance -> Console connection), tested beforehand.
2. Open a second SSH session to the VM and keep it open until the end.
3. First run with the defaults (22 open): it only installs the sshd drop-in,
   sshguard and an allow-all firewall. Check from a NEW terminal that you can
   still log in as the operator, and that a `deploy-prod` re-run still connects.
4. Only then narrow `ssh_allowed_cidrs` (or switch to the tunnel), re-run, and
   check again from a new terminal before closing the old sessions.
5. Mirror the same sources in the OCI security list: the host firewall is the
   second layer, not the only one.

If you are locked out: serial console, then
`systemctl stop btp-firewall` (drops only the `btp_host` table) or remove
`/etc/ssh/sshd_config.d/00-btp-hardening.conf` and `systemctl reload ssh`.

Idempotent — re-run any time. A reclaimed / rebuilt VM is recovered by running
this exact command against the fresh host.

## Deploy hook (who brings the app up)

Ansible provisions the host and the **shared** infra (Traefik, cloudflared,
Valhalla, PG-reference) but does **not** bring the app stack up. The prod app is
deployed by GHA `deploy-prod` at cutover (first `v*` tag; plan W4 / PR-C), which
SSHes in and runs the equivalent of the rendered `{{ app_dir }}/deploy-prod.sh`:

```bash
/opt/bike-trip-planner/deploy-prod.sh <tag> <commit> <php-digest> <pwa-digest> <provisioner-digest>
```

The first deploy of a tag records its commit and digests in
`/etc/bike-trip-planner/releases/<tag>`; a different set for an already recorded
tag is refused, and so is a tag that no longer points at its recorded commit.
`deploy-prod.sh <tag>` alone (rollback) reuses the record.

**Every** prod `docker compose` call goes through `/usr/local/bin/btp-compose`
(rendered from `roles/app_deploy/templates/btp-compose.j2`): the deploy, the
rollback (`deploy-prod.sh <previous tag>`), the events-refresh timer and manual
provisioning. It refuses to run unless the checkout sits on a recorded `vX.Y.Z`
tag at its recorded commit, sets `PHP_IMAGE` / `PWA_IMAGE` / `PROVISIONER_IMAGE`
to `<*_image_repo>@<recorded digest>`, and passes `--env-file
/etc/bike-trip-planner/app.env -p prod -f compose.yaml -f deploy/prod/compose.yaml`.
So the images always match the compose files that run them, e.g.
`btp-compose ps`, `btp-compose logs -f php`.

Previews never go through the `deploy` user. GHA `deploy-preview` /
`teardown-preview` SSH in as `preview-deploy`, whose only command is
`/usr/local/bin/btp-preview` (`deploy <pr> <php-digest> <pwa-digest>` or
`teardown <pr>`). It runs the `-preview` images by digest with the compose
files of `/opt/bike-trip-planner-previews/base` (a checkout of `main` it fetches
on each deploy) and `/opt/bike-trip-planner-previews/preview.env`. Ansible renders
that file and the preview keypairs from the `vault_preview_*` secrets
(`root:preview-deploy 0640`), and stops if one of them equals its prod value.

Ansible clones the repo once and never moves the checkout afterwards
(`update: false`): the deploy owns the revision. `btp-compose` therefore fails
until the first deploy has checked out a release tag.

## Validation status

`ansible-playbook --syntax-check` and `ansible-lint` run against this tree (with
`vault.yml.example` standing in for the vault); the operator should re-run them before a
change reaches the VM:

```bash
ansible-galaxy collection install -r requirements.yml
ansible-lint
ansible-playbook -i inventory.ini playbook.yml --syntax-check
```

`api/tests/Unit/Edge` (PHPUnit, in CI) also pins the invariants that would hurt most if they
regressed: the sshd drop-in, the firewall never flushing Docker's rules, exclusive
`authorized_keys`, the backup `OnFailure=` and the lifecycle mode never deleting.

# ADR-061: Deployment via Ansible + GitHub Actions SSH + Traefik + Cloudflare Tunnel — removal of Coolify

- **Status:** Accepted
- **Date:** 2026-08-31
- **Amends:** [ADR-019](adr-019-deployment-infrastructure-strategy.md) (Deployment Infrastructure Strategy — replaces the Coolify control plane)
- **Impacts:** [ADR-037](adr-037-docker-dev-prod-convergence.md) (the FrankenPHP edge now serves plain HTTP behind Traefik, auto-HTTPS disabled)
- **Depends on:** [ADR-060](adr-060-pg-split-app-reference.md) (PG split app/référence), ADR-032 (migrations at boot)
- **Related:** ADR-062 (Backup & DR, runtime without Coolify)

## Context and Problem Statement

ADR-019 chose **Coolify** (self-hosted PaaS) as the control plane on the Oracle Cloud Always Free VM: a web UI driving Git-webhook deploys, an auto-configured Traefik with Let's Encrypt, and a 1-click rollback. The deployment model has since moved to **feature-deploys by pull request** on a single VM (ADR-060): production and every preview run as isolated `docker compose -p` stacks side by side, images built on GitHub Actions (arm64 → GHCR). Against that model Coolify is the wrong shape:

- **Two overlapping deployers.** CD already builds and pushes images from GitHub Actions; Coolify would then pull and roll them from its own UI. The source of truth for "what is deployed" is split between a workflow and a PaaS database.
- **No PR-stack primitive.** Coolify deploys *an application*; it has no first-class notion of N ephemeral `docker compose -p pr-<n>` stacks sharing one Valhalla and one PG-référence on the same host. We would be scripting around it.
- **A public ingress it does not need.** Coolify + Traefik terminate Let's Encrypt on open ports 80/443. The privacy posture (private beta, no indexing, Cloudflare Access) is far stronger with **no public port at all**.
- **~500 MB and a moving target.** Coolify is a stateful service to keep patched and backed up, and its exact `docker compose` invocation is undocumented (ADR-037 had to name the override file `compose.dev.yaml` precisely because of that opacity).

## Decision

**Remove Coolify.** The control plane becomes three cooperating, boring pieces, each already understood in the repo:

1. **Provisioning = Ansible + Vault.** One idempotent `ansible-playbook` owns the VM: OS + Docker, the **Traefik** reverse proxy, the **`cloudflared`** tunnel, the `edge` / `btp-shared` networks, the shared infra (Valhalla, PG-référence PostGIS), the repo checkout, the prod `.env`, the JWT PEM keypair under `/etc/bike-trip-planner/jwt/`, the deploy SSH key, and the crons/timers (backup, events-refresh, optional anti-reclaim). Every secret is stored in **Ansible Vault**; bootstrap and reclaim are the same one playbook run.
2. **Deploy = GitHub Actions over SSH.** `.github/workflows/deploy.yml` builds the arm64 images and pushes them to GHCR, then a `deploy-prod` job (tag-only, `github.ref_type == 'tag'`) **SSHes to the VM**, checks out the tag and runs `docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml up -d --pull always`, followed by a smoke test on `https://www.${DOMAIN}`. Previews are deployed by symmetric `deploy-preview` / `teardown-preview` jobs, added by the preview-deploys change (W3). Every deploy job is **no-op when its SSH secrets are absent**, the same transition-safety contract the old Coolify webhook job had, so merges to `main` are never disturbed before the infra exists.
3. **Reverse proxy = Traefik, ingress = Cloudflare Tunnel.** Traefik is the **sole** proxy, configured by Docker labels (see `deploy/prod/compose.yaml`), speaking **plain HTTP** on the `edge` network — no ACME, no open port. Ingress is the outbound-only **`cloudflared`** tunnel (`www` and `*.${DOMAIN}` as CNAMEs to `<id>.cfargotunnel.com`). Because the VM publishes **no public port**, Cloudflare Access in front of the tunnel is **non-bypassable**: there is no origin IP to hit directly.

### Impact on ADR-037 (FrankenPHP edge)

ADR-037's dev/prod convergence on a single FrankenPHP image **stands**. What changes is only the edge's role in prod: FrankenPHP no longer terminates TLS. The prod overlay drops the base's published `80`/`443` (`ports: !reset []`), sets `SERVER_NAME` to `http://…` so FrankenPHP serves **plain HTTP**, and lets Traefik (HTTP) → Cloudflare Tunnel → Cloudflare edge own TLS. Dev and recette keep the base's published ports and FrankenPHP's local TLS unchanged.

### Mailer

The transactional mailer moves to **Brevo** (`brevo+api://KEY@default`), aligned with Cloudflare Email Routing (SPF/DKIM/DMARC) — see the DNS/email workstream. `MAILER_DSN` is a Vault secret like every other runtime secret.

### Secret store relocation

Coolify's env store is gone, so the runtime secret store moves to **Ansible Vault** (the JWT PEM lives as a file on the VM, the `age` private key stays out of the VM in Bitwarden). `docs/runbooks/secrets-inventory.md` is the documentation map; no secret value lives in the repo.

### Rollback

There is no Coolify "Redeploy" button. Rollback = **redeploy the previous tag**: re-run the `deploy-prod` job on an earlier `v*` tag (or check out that tag on the VM and `docker compose … up -d --pull always`). Images are retained by SHA and tag on GHCR (10 most recent per image), so any recent release is a rollback target. Migration handling is unchanged (forward-only, ADR-032; destructive migrations follow the 2-release rule).

## Consequences

### Positive

- **One deployer, one source of truth.** GitHub Actions builds *and* triggers the deploy; the VM only ever runs `docker compose`. Nothing to reconcile against a PaaS database.
- **No public port.** `cloudflared` is outbound-only; `nmap` on the VM shows no 443. Cloudflare Access cannot be bypassed, satisfying the private-beta posture at the network layer, not just the app.
- **Reproducible host.** The VM is an Ansible artifact: bootstrap and post-reclaim rebuild are one playbook, versioned in the repo, no click-ops.
- **Less resident software.** ~500 MB of Coolify reclaimed; one fewer stateful service to patch and back up.

### Negative

- **Loss of the Coolify UI.** No web dashboard for logs, deploy history or 1-click rollback. Replaced by `docker logs` / journald, redeploy-by-tag, and the existing Sentry/UptimeRobot monitors (repointed at the new domain). Acceptable for a solo-operated beta.
- **Hard dependency on Cloudflare.** Ingress, DNS, Access and email all sit at Cloudflare; a Cloudflare outage takes the tunnel down. Accepted — the stack was already consolidating DNS/email there, and the tunnel removes the reserved-IP and origin-cert dependencies in exchange.
- **Operational surface shifts to Ansible.** The team now maintains playbooks and a Vault instead of a PaaS UI. This is a deliberate trade: code-reviewed, versioned infra over opaque click-ops.

### Neutral

- The `compose.yaml` base is still read as-is (now by CI and the SSH deploy, no longer by Coolify); the prod-only deltas live in `deploy/prod/compose.yaml`, layered explicitly on the VM.
- Backup & DR is decoupled from Coolify and finalised in ADR-062 (Backup & DR).

## Addendum (2026-11-05): previews are isolated from production

The model above let a pull request reach production. `deploy-preview` checked out the PR on the
VM and ran *its* compose files as the `deploy` user, who is in the `docker` group (root-equivalent
on the host) and can read `app.env`. A PR run also had `packages: write` on the release images,
which prod pulled by mutable tag, and every SSH secret was a repository secret, readable by any
workflow a branch of this repository adds. Previews and prod also shared the `edge` and
`btp-shared` networks, where `php` (the worker's Mercure host) could resolve to a preview
container. The deploy model changes on four points.

1. **Two GitHub Environments hold the secrets.** `production` (deployment policy: `v*` tags only)
   holds the prod SSH key and every prod-side secret (`SENTRY_*`, `INCIDENT_DISPATCH_TOKEN`,
   `PROD_HEALTH_URL`, `PROD_REPO_DIR`); `upload-sourcemaps`, `deploy-prod` and `smoke-test` bind
   it. `preview` holds a **second** SSH key; `deploy-preview` / `teardown-preview` bind it,
   gated by required reviewers. No SSH secret stays at repository level. `SSH_KNOWN_HOSTS` is
   mandatory: there is no `ssh-keyscan` fallback any more.
2. **The preview key can only run a forced command.** It belongs to a separate VM user,
   `preview-deploy`, whose `authorized_keys` entry is
   `restrict,command="/usr/local/bin/btp-preview"`. `btp-preview` accepts
   `deploy <pr> <php-digest> <pwa-digest>` and `teardown <pr>`, nothing else. It runs the
   compose files of a VM-side checkout of `main` (`/opt/bike-trip-planner-previews/base`), never
   the PR's, and pulls the images by digest from separate `-preview` packages. A PR therefore
   changes what a preview's containers run, not how they are run (mounts, networks, Traefik
   routes). That user can read neither `app.env` nor the prod keypairs. The cost is that a PR
   changing `compose.yaml` or `deploy/preview/compose.yaml` is previewed with main's version of
   those files.
3. **Prod runs images by digest.** `build-images` pushes PR images to
   `bike-trip-planner-<service>-preview:pr-<n>` only, and job outputs carry the digests of the
   tag build. `deploy-prod` passes the tag, its commit and the three digests to
   `deploy-prod.sh`, which records them in `/etc/bike-trip-planner/releases/<tag>` on the first
   deploy and refuses a different set for a tag it already recorded. `btp-compose` runs
   `<repo>@<digest>` from that record and refuses to run when the checkout is not on the
   recorded commit. A re-pushed image tag or a moved git tag no longer reaches prod. Rollback is
   unchanged in use (re-run the old tag's `deploy-prod`, or `deploy-prod.sh <tag>` on the VM,
   which reads the record). On a rebuilt VM the records are gone: re-run the tag's
   `deploy-prod` job, or pass the commit and digests listed in that run's summary.
4. **Prod and previews never share a network.** Previews join `edge-preview` and
   `btp-shared-preview`; Traefik, Valhalla and PG-reference join both sides. On `edge` and
   `btp-shared`, `php` can only be prod's. `edge` has a fixed subnet (`edge_subnet`), and Traefik
   trusts `X-Forwarded-For` from that subnet only (cloudflared), so a preview container that
   reaches Traefik cannot forge a client IP.

Not addressed here: GHCR still lets the repository's `GITHUB_TOKEN` write the release packages
(narrowing that needs a release-only credential), and images are not signed. Digest pinning
makes both matter for availability only, not for what prod runs.

## Addendum (2026-11-05): SSH is the one public port

"No public port" holds for the web, not for SSH. The deploy jobs run plain `ssh` to
`SSH_HOST`, so port 22 has to be reachable from GitHub-hosted runners, whose addresses are not
fixed; an Access SSH application was described but never wired. The VM therefore keeps a public
IP with 22 open, and the new `hardening` role makes that port cheap to expose: an sshd drop-in
(keys only, no root, `AllowUsers` limited to the operator and the two deploy users, checked
with `sshd -t` before any reload), an nftables INPUT filter in its own table that leaves
Docker's tables alone, and sshguard. `ssh_allowed_cidrs` / `ssh_allow_github_actions` narrow
the sources, and `ssh_tunnel_hostname` adds the tunnel route for operators. Closing 22
entirely requires the deploy jobs to go through `cloudflared access ssh` with an Access service
token; that is a separate change, not made here. Both `authorized_keys` lists are now exclusive,
so rotating a deploy key revokes the old one.

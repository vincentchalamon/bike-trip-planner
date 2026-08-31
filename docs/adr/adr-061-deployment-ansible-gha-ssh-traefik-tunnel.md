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

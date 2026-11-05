# Oracle VM Reclaimed

Oracle Cloud Infrastructure can reclaim Always Free instances after 7 consecutive days where p95 CPU < 20 %, network < 20 %, and memory < 20 % (ADR-019). The application stack is sized to stay above the threshold (steady-state ~29 % memory), but a long quiet period plus a worker crash can trip it.

**Recovery is a single `ansible-playbook` run (ADR-061).** The whole VM (Docker, Traefik, cloudflared, shared Valhalla + PG-reference, the prod env file and both keypairs from Vault, backup timer) is described in [`ansible/`](../../ansible/README.md); bootstrapping a fresh VM and recovering a reclaimed one are the same command.

## Symptoms

- UptimeRobot external monitor red on `/api/healthz`
- SSH to the VM times out / refuses connections
- OCI console: the instance is in `STOPPED`, `TERMINATED`, or has been removed entirely
- Email from Oracle stating "Always Free resources reclaimed"

## Diagnosis

From a workstation:

```bash
ssh ubuntu@<vm-host>     # public IP on port 22 (or the Access SSH app, ansible/README.md "Restrict SSH")
curl -sS -o /dev/null -w '%{http_code}\n' https://www.<domain>/api/healthz
```

A Cloudflare `502`/`530` on the public URL means the tunnel has no live `cloudflared`
connector, i.e. the VM (or its Docker daemon) is down.

In the OCI console:

1. Compute → Instances → check the instance state
2. Compute → Boot volumes → confirm the boot volume is still listed (volumes survive instance termination for 7 d by default)
3. Audit → search for the `TerminateInstance` event with reason

## Procedure

1. **If the instance is `STOPPED`**, just start it from the OCI console (Compute → Instances → Start). Docker services (`restart: unless-stopped`) come back on boot; the stack is up in a few minutes. No action needed beyond confirming health.

2. **If the instance was terminated but the boot volume is preserved** (the common reclaim path):
    - OCI console → Compute → Create Instance
    - Shape: `VM.Standard.A1.Flex`, 4 OCPU / 24 GB RAM
    - Image source: "Boot volume" → select the preserved volume
    - Subnet: same VCN as before. Web ingress is the outbound Cloudflare Tunnel, nothing to reattach; attach a public IP for the GHA deploy SSH and update `SSH_HOST` if it changed.
    - Launch. The VM boots with everything already configured. Confirm `cloudflared` reconnected and `/api/healthz` is green.
    - If the host SSH key changed, update the `SSH_KNOWN_HOSTS` secret of both the `production` and `preview` environments, or the deploy jobs will refuse the host.

3. **If the boot volume is also gone** (rare: full reclaim after long inactivity), **re-run the Ansible playbook** on a fresh VM:
    - Provision a fresh `VM.Standard.A1.Flex` (Ubuntu ARM64, 4 OCPU / 24 GB) with a public IP reachable on TCP 22 only (the GHA deploy jobs SSH to it). On `Out of host capacity`, retry or change availability domain or region. Keep the OCI serial console available until the first playbook run has applied the SSH hardening.
    - From a workstation, in `ansible/`: `ansible-galaxy collection install -r requirements.yml`, then `ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass`. This reinstalls Docker, Traefik, cloudflared, the shared Valhalla and PG-reference, the prod env file, both keypairs and the backup timer. See [`ansible/README.md`](../../ansible/README.md).
    - Seed the routing graph: `make routing-publish deploy@<vm-host> <slug> [slug...]` from a workstation that holds a built graph (see [valhalla-routing-graph.md](valhalla-routing-graph.md)), or ship the tar, set `valhalla_tiles_tar` in `group_vars/all.yml` and re-run the playbook.
    - Deploy the app: re-run GHA `deploy-prod` for the current tag. A fresh VM has no release record, so `deploy-prod.sh <tag>` alone is refused; on the VM, pass the commit and digests from that run's summary (`deploy-prod.sh <tag> <commit> <php-digest> <pwa-digest> <provisioner-digest>`). The fresh clone is on `main`, and `btp-compose` (every prod compose call) refuses to run until this step has put it on a recorded release tag.
    - Restore PG-app from the latest backup: follow the restore procedure in [ADR-062](../adr/adr-062-backup-and-disaster-recovery.md#restore-procedure) (the `age` private key is in Bitwarden, see [secrets-inventory.md](secrets-inventory.md#bootstrap-total-loss)).
    - PG-reference is not backed up: it is reproducible by re-opening each zone (see [zone-opening.md](zone-opening.md)).
    - Update the `SSH_HOST` / `SSH_KNOWN_HOSTS` secrets of both GitHub environments if the host changed.
    - Cloudflare DNS already points `www` + `*` at the tunnel CNAME (`<UUID>.cfargotunnel.com`); no DNS record to update.

4. **Notify users** — use a GitHub repository issue or a pinned PWA banner once the app is back.

## Verification and follow-up

- `/api/healthz` green from UptimeRobot and a manual curl.
- A new incident issue with severity P1 documenting the reclaim cause (likely "VM idle for 7 d").
- If reclaim recurs, enable the optional anti-reclaim heartbeat timer (`enable_reclaim_heartbeat: true` in `ansible/group_vars/all.yml`; it hits `/api/healthz` through the tunnel). The steady-state footprint already clears the 20 % threshold, so this is belt-and-braces.
- File a post-mortem using `incident-template.md` — even if recovery was quick, the data loss risk warrants the analysis.

## References

- ADR-019 — Deployment infrastructure (Oracle Always Free reclaim policy)
- `incident-template.md` — post-mortem template

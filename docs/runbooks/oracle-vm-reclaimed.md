# Oracle VM Reclaimed

Oracle Cloud Infrastructure can reclaim Always Free instances after 7 consecutive days where p95 CPU < 20 %, network < 20 %, and memory < 20 % (ADR-019). The application stack is sized to stay above the threshold (steady-state ~29 % memory), but a long quiet period plus a worker crash can trip it.

**Recovery is now a single `ansible-playbook` run — there is no Coolify (ADR-061).** The whole VM (Docker, Traefik, cloudflared, shared Valhalla + PG-reference, `.env` + JWT from Vault) is described in [`../../ansible/`](../../ansible/README.md); bootstrapping a fresh VM and recovering a reclaimed one are the same command.

## Symptômes

- UptimeRobot external monitor red on `/api/healthz` (Uptime Kuma also down because it runs on the same VM, so it is silent)
- SSH to the VM times out / refuses connections
- OCI console: the instance is in `STOPPED`, `TERMINATED`, or has been removed entirely
- Email from Oracle stating "Always Free resources reclaimed"

## Diagnostic

From a workstation:

```bash
ssh ubuntu@<vm-ip>
ping <vm-ip>
```

In the OCI console:

1. Compute → Instances → check the instance state
2. Compute → Boot volumes → confirm the boot volume is still listed (volumes survive instance termination for 7 d by default)
3. Audit → search for the `TerminateInstance` event with reason

## Procédure

1. **If the instance is `STOPPED`**, just start it from the OCI console (Compute → Instances → Start). Docker services (`restart: unless-stopped`) come back on boot; the stack is up in a few minutes. No action needed beyond confirming health.

2. **If the instance was terminated but the boot volume is preserved** (the common reclaim path):
   - OCI console → Compute → Create Instance
   - Shape: `VM.Standard.A1.Flex`, 4 OCPU / 24 GB RAM
   - Image source: "Boot volume" → select the preserved volume
   - Subnet: same VCN as before. **No reserved public IP** — ingress is the outbound Cloudflare Tunnel (ADR-061); nothing to reattach.
   - Launch — the VM boots with everything already configured. Confirm `cloudflared` reconnected and `/api/healthz` is green.

3. **If the boot volume is also gone** (rare — full reclaim after long inactivity) — **re-run the Ansible playbook**:
   - Provision a fresh `VM.Standard.A1.Flex` (Ubuntu ARM64, 4 OCPU / 24 GB) with **no public IP**. On `Out of host capacity`, retry / change availability domain or region (plan W5.3). First SSH via the OCI serial console or a temporary public IP.
   - From a workstation: `cd ansible && ansible-galaxy collection install -r requirements.yml && ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass`. This reinstalls Docker + Traefik + cloudflared + shared Valhalla/PG-reference and renders `.env` + JWT from Vault. See [`ansible/README.md`](../../ansible/README.md).
   - Ship the Valhalla tiles tar (`make routing-publish …`, plan W6) and set `valhalla_tiles_tar`, or seed the volume manually, then re-run so Valhalla can serve.
   - Restore PostgreSQL (PG-app) from the most recent backup (backup role = PR-G / W10; runbook TBD). PG-reference is reproducible — re-run `make provision <zone>` per opened zone.
   - Deploy the app: GHA `deploy-prod` on the current tag, or SSH in and run `/opt/bike-trip-planner/deploy-prod.sh <tag>`.
   - Cloudflare DNS already points `www` + `*` at the tunnel CNAME (`<UUID>.cfargotunnel.com`); no A record to update.

4. **Notify users** — use a GitHub repository issue or a pinned PWA banner once the app is back.

## Post-action

- `/api/healthz` green from UptimeRobot and a manual curl.
- A new incident issue with severity P1 documenting the reclaim cause (likely "VM idle for 7 d").
- If reclaim recurs, enable the optional anti-reclaim heartbeat timer (`enable_reclaim_heartbeat: true` in `ansible/group_vars/all.yml`; it hits `/api/healthz` through the tunnel). The steady-state footprint already clears the 20 % threshold, so this is belt-and-braces.
- File a post-mortem using `incident-template.md` — even if recovery was quick, the data loss risk warrants the analysis.

## References

- ADR-019 — Deployment infrastructure (Oracle Always Free reclaim policy)
- `incident-template.md` — post-mortem template

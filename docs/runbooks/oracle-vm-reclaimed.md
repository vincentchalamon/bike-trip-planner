# Oracle VM Reclaimed

Oracle Cloud Infrastructure can reclaim Always Free instances after 7 consecutive days where p95 CPU < 20 %, network < 20 %, and memory < 20 % (ADR-019). The application stack is sized to stay above the threshold, but a long quiet period plus a worker crash can trip it.

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

1. **If the instance is `STOPPED`**, just start it from the OCI console (Compute → Instances → Start). Coolify auto-starts on boot; the stack comes back in 5-10 min.

2. **If the instance was terminated but the boot volume is preserved** (the common reclaim path):
   - OCI console → Compute → Create Instance
   - Shape: `VM.Standard.A1.Flex`, 4 OCPU / 24 GB RAM
   - Image source: "Boot volume" → select the preserved volume
   - Subnet: same VCN as before (keep the existing reserved public IP if available)
   - Launch — the VM boots with Docker + Coolify already configured
   - Reattach the reserved public IP (Networking → Public IPs)

3. **If the boot volume is also gone** (rare — full reclaim after long inactivity):
   - Provision a fresh ARM Ampere A1 instance (Ubuntu 22.04 LTS, 4 OCPU / 24 GB, 150 GB boot volume) per ADR-019
   - Install Coolify: `curl -fsSL https://cdn.coollabs.io/coolify/install.sh | sudo bash`
   - Restore PostgreSQL from the most recent backup (see backups plan — out of scope of this ADR but referenced)
   - In Coolify, re-import the project from the GitHub repository; environment variables must be re-entered (publisher/JWT secrets, OAuth credentials, `INCIDENT_DISPATCH_TOKEN`)
   - Re-run `make provision` to rebuild Valhalla tiles for the configured regions
   - Update FreeDNS A record to point to the new public IP

4. **Notify users** — the status page (`status.biketrip.mooo.com`) is also down. Use the GitHub repository issue or a pinned banner once the PWA is back.

## Post-action

- `/api/healthz` green from UptimeRobot and a manual curl.
- A new incident issue with severity P1 documenting the reclaim cause (likely "VM idle for 7 d").
- Add a synthetic load job (cron pinging `/api/health` from the VM itself) to keep the instance above the reclaim threshold. Document the cron in ADR-019 follow-up.
- File a post-mortem using `incident-template.md` — even if recovery was quick, the data loss risk warrants the analysis.

## References

- ADR-019 — Deployment infrastructure (Oracle Always Free reclaim policy)
- `incident-template.md` — post-mortem template

# ADR-062: Backup & Disaster Recovery (Ansible-managed, de-Coolified)

- **Status:** Accepted
- **Date:** 2026-08-31
- **Depends on:** ADR-019 (Beta right-sizing — OCI Always Free), ADR-022 (Persistent Storage Strategy), ADR-037 (Docker dev/prod convergence), ADR-060 (Split PG — PG-app is the backup target), ADR-061 (No-Coolify deploy — Ansible + GHA-SSH)
- **Supersedes:** the Coolify-coupled Backup & DR design sketched in Sprint 39 (#527-#532), which the secrets inventory / rotation runbooks reference as "ADR-038 (à créer)". This ADR is its de-Coolified successor and the reference those rows should point at once PR-D relocates the secrets store.

> **Numbering history.** The backup strategy was first reserved as **ADR-038**, but
> that number is taken by the accepted ADR-038 (Hide Forbidden As Not Found).
> **ADR-048** has since also been claimed (In-Ride Assistance Without AI). So the
> Backup & DR ADR lands at the next free number, **ADR-062**. The secrets-inventory /
> secrets-rotation rows still citing "ADR-038 (#527)" should be repointed here by
> PR-D (W9).

## Context and Problem Statement

The only irreplaceable data in the stack is **PG-app** (`public` schema: users,
trips, stages, device tokens, notification preferences — ADR-060). Everything
else is reproducible from source:

- **PG-reference** (`osm` / `tourism` / `provisioner` schemas) is rebuilt from OSM
  extracts by `make provision <zone>` (ADR-040/049). It is large, append-only and
  regenerable, so backing it up would cost storage for no recovery value.
- The Valhalla routing graph is rebuilt off-VM by `make routing-build` and shipped
  by `make routing-publish` (plan W6).
- Container images live in GHCR; the app checkout is a `git` clone.
- Runtime secrets live in Ansible Vault (ADR-061) and the age/JWT private material
  is held off-VM (Bitwarden). A total VM loss is recovered by re-running the
  Ansible playbook, not from a backup.

So DR reduces to one job: **a durable, encrypted, off-site copy of PG-app**, kept
with enough history to survive a late-noticed corruption or an erroneous delete.

The original design (Sprint 39) ran this as a Coolify-managed `backup` service
with its env in Coolify. Coolify is removed (ADR-061); runtime is now an
Ansible-provisioned VM with `docker compose` and systemd. This ADR re-homes the
backup on that runtime.

## Decision

A **nightly, off-site, encrypted `pg_dump` of PG-app**, run by a systemd timer on
the VM, with GFS retention. One destination suffices for the private beta; both
are kept configurable.

- **Scope = PG-app only.** PG-reference is *not* backed up (reproducible; see
  above). The dump is a single `pg_dump -Fc` (custom format, compressed) of the
  app database.
- **Runtime = systemd timer + one-shot, not Coolify.** The Ansible `backup` role
  installs `/usr/local/bin/btp-backup.sh`, a `btp-backup.service` (`Type=oneshot`,
  runs as the `deploy` user) and a nightly `btp-backup.timer`
  (`OnCalendar=*-*-* 02:30:00`, server tz `Europe/Paris`, `Persistent=true`). This
  mirrors the events-refresh / anti-reclaim timers already in the playbook.
- **Dump from the running container.** The script runs
  `docker compose -p prod -f compose.yaml -f deploy/prod/compose.yaml exec -T
  database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc'`, i.e. it
  reads the credentials the PG-app container already holds (no separate DB
  password on the backup path, no extra network exposure). `database` is the
  PG-app service of the prod stack (ADR-060).
- **Encrypt with `age` (recipient-only).** The dump is piped through
  `age -r $AGE_RECIPIENT`. `AGE_RECIPIENT` is a **public** key; the matching
  **private** key is never on the VM — it lives off-VM in Bitwarden and is only
  used at restore time. A VM compromise therefore cannot decrypt the backups.
- **Off-site via `rclone` to B2 and/or OCI.** The encrypted blob is `rclone`-copied
  to every configured remote (`backup_remotes`, default `b2`; add `oci` to mirror).
  OCI Object Storage is reached over its S3-compatible endpoint. Bucket:
  `btp-backups`. rclone credentials come from a Vault-rendered `rclone.conf`
  (mode `0640`, `root:deploy`).
- **GFS retention.** Each run also copies into `weekly/` on Mondays and `monthly/`
  on the 1st, then prunes each tier to `backup_keep_daily` (7) / `backup_keep_weekly`
  (4) / `backup_keep_monthly` (6). Timestamped, UTC, lexicographically sortable
  object names (`pg-app-YYYYMMDDTHHMMSSZ.dump.age`) make pruning a sort + drop.
- **Secrets in Ansible Vault (ADR-061), not Coolify.** `AGE_RECIPIENT`, `B2_*` and
  `OCI_*` are added to `ansible/vault.yml.example` as placeholders. PG-app creds
  are **not** duplicated onto the backup path (the dump runs inside the container).
- **On-demand trigger.** `make backup-now BACKUP_SSH=user@host` SSHes in as the
  deploy user and runs the exact same script path (sources `backup.env`, runs
  `btp-backup.sh`), for pre-migration snapshots and post-rotation verification.

## Restore procedure

Restore is deliberately manual (a restore is a rare, high-attention event):

1. **Fetch the age private key** from Bitwarden (canonical item
   `bike-trip-planner / age private key`; `legacy *` items decrypt older dumps —
   see secrets-rotation.md).
2. **Pull the chosen dump** from a remote:
   `rclone copyto b2:btp-backups/daily/pg-app-<ts>.dump.age ./restore.age`.
3. **Decrypt:** `age -d -i age-key.txt -o restore.dump restore.age`.
4. **Restore into a fresh PG-app** (greenfield VM already provisioned by Ansible +
   the prod stack up): copy the dump into the container and
   `pg_restore --clean --if-exists -U "$POSTGRES_USER" -d "$POSTGRES_DB" restore.dump`.
5. **Verify:** `curl https://www.${DOMAIN}/api/health` green (PG-app healthy) and a
   spot-check that a known user/trip is present.

`make backup-now` on a fresh dump + a scratch decrypt is the standing smoke test
that the loop is intact end-to-end (dump readable, age recipient correct, remote
reachable).

## Alternatives considered

- **Back up PG-reference too.** Rejected: reproducible by `make provision`, large,
  append-only — storage cost for zero recovery value.
- **Continuous archiving / PITR (WAL-G, `pgBackRest`).** Rejected for the beta:
  a single-operator, single-VM beta does not need sub-day RPO, and PITR adds a
  stateful archiver + base-backup lifecycle to maintain. A nightly logical dump
  with GFS is enough; revisit if data volume or RPO expectations grow.
- **Managed snapshots (OCI block-volume backups).** Rejected as the primary path:
  ties recovery to the OCI tenancy that may itself be lost/reclaimed (ADR-019),
  and is not application-consistent for Postgres. Off-site encrypted logical dumps
  are portable across providers.
- **Keep it in Coolify.** Moot — Coolify is removed (ADR-061).
- **Push the private age key to the VM for a self-restoring backup.** Rejected:
  the whole point of recipient-only encryption is that a VM compromise cannot read
  the off-site history. The private key stays off-VM.

## Consequences

- One irreplaceable dataset (PG-app) has a durable, encrypted, off-site, versioned
  copy; a corruption noticed within the GFS window is recoverable.
- A VM compromise cannot decrypt the backups (private key off-VM).
- The backup adds negligible load: one nightly `pg_dump` of a small beta DB,
  streamed straight to `age` + `rclone` with no on-disk plaintext.
- Restore is manual and documented; RPO ~24 h, RTO = provision + `pg_restore`
  (minutes for a beta-sized DB). Acceptable for the private beta.
- New Vault secrets (`AGE_RECIPIENT`, `B2_*`, `OCI_*`); rotation cadences already
  documented in secrets-rotation.md (age = on-compromise, B2/OCI = annual).
- The secrets-inventory / secrets-rotation rows that cite "ADR-038 (#527)" for
  Backup & DR should be repointed to this ADR when PR-D (W9) relocates the secrets
  store from Coolify to Vault.

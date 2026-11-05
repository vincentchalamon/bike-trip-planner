# Backups: retention, alerting and restore test

How the nightly PG-app backup ([ADR-062](../adr/adr-062-backup-and-disaster-recovery.md)) is
kept out of reach of the VM, how a failed run gets noticed, and how to prove a dump actually
restores. The full restore procedure itself is in ADR-062, "Restore procedure".

## When to use

- Setting up the backup bucket, or moving it to lifecycle-based retention.
- Wiring the heartbeat / failure alerts.
- The monthly restore test, or after any change to the backup path (age recipient, B2 key,
  PG major version).
- A heartbeat alert fired.

## Retention the VM cannot rewrite

With `backup_retention_mode: script` (the default) the backup script prunes old dumps itself
with `rclone deletefile`, so the application key on the VM must hold `deleteFiles`: anyone
with the VM can wipe the history. `backup_retention_mode: lifecycle` moves retention to the
bucket and takes that right away from the VM.

### Backblaze B2

1. **Object Lock.** In the B2 console, enable Object Lock on `btp-backups` (a new bucket can
   be created with it; on an existing one it can be turned on afterwards) and set a default
   retention, mode **Governance** or **Compliance**, at least as long as the longest window
   you must survive, e.g. 30 days. A locked version cannot be deleted or overwritten by any key
   until its retention expires (in Compliance mode, not even by the account owner).
2. **Lifecycle rules**, one per tier prefix, so expiry stays automatic:

    | Prefix | `daysFromUploadingToHiding` | `daysFromHidingToDeleting` |
    |---|---|---|
    | `daily/` | 7 | 1 |
    | `weekly/` | 28 | 1 |
    | `monthly/` | 180 | 1 |

    B2 counts days, not objects, so these are the time equivalent of the `backup_keep_*`
    counts. A version still under Object Lock is only deleted once its retention ends.

3. **Application key without delete.** Create a key scoped to `btp-backups` with
   `listBuckets`, `listFiles`, `readFiles`, `writeFiles` only (no `deleteFiles`, no
   `writeBucketRetentions`, no `bypassGovernance`). Put it in Vault (`vault_b2_account_id`,
   `vault_b2_application_key`).
4. Set `backup_retention_mode: lifecycle` (host or group vars) and re-run the playbook.
5. Run `make backup-now BACKUP_SSH=deploy@<vm>` and check it ends with
   `backup: shipped ...` and no prune error; then delete the old key in the B2 console.

`writeFiles` still lets the key **hide** a file (a B2 delete marker). Hidden versions stay
restorable (`rclone --b2-versions`) and Object Lock keeps them until their retention ends, so
a hidden dump is recoverable within that window.

### OCI Object Storage (if `oci` is in `backup_remotes`)

Same idea: a **retention rule** on the bucket (time-bound, locked once you are sure of the
duration), object **lifecycle policy rules** per prefix for expiry, and a Customer Secret Key
for a user whose IAM policy grants `OBJECT_CREATE` / `OBJECT_INSPECT` / `OBJECT_READ` on the
bucket but not `OBJECT_DELETE`.

## Alerting

Two optional URLs, kept in Vault because they carry a token:

| Vault key | Pinged | Monitor to create |
|---|---|---|
| `vault_backup_heartbeat_url` | After every run that shipped to every remote | Heartbeat check (healthchecks.io-style) or Uptime Kuma **push** monitor, period ~25 h. It alerts when the ping stops arriving: VM down, timer disabled, script hanging. |
| `vault_backup_failure_url` | By `btp-backup-failed@.service`, the `OnFailure=` unit of `btp-backup.service` | The same check's failure endpoint (healthchecks: `<url>/fail`; Uptime Kuma: `<push url>?status=down`). It alerts at once instead of a day later. |

A manual `make backup-now` pings the heartbeat too, but a failure there only shows in your
terminal: `OnFailure=` fires for the systemd unit. To investigate a failure on the VM:

```bash
systemctl status btp-backup.service
journalctl -u btp-backup.service -n 100
```

## Restore test

The VM only holds the age **public** recipient, by design (ADR-062), so the test runs where the
private key is: the operator workstation. `scripts/backup-restore-test.sh` fetches the newest
daily dump (or the object you name), decrypts it straight into `pg_restore` inside a throwaway
`postgis/postgis:18-3.6-alpine` container on tmpfs, and checks that the `user` table holds at
least `MIN_USERS` rows (default 1). No plaintext dump touches the disk, and the container is
removed on exit.

Needs `age`, `rclone` and `docker` on the workstation. Use a **read-only** rclone remote
(a B2 key with `listFiles` + `readFiles`), never the VM's.

```bash
# The private key from Bitwarden ("bike-trip-planner / age private key"), in a file
# readable by you only. Remove it afterwards.
make backup-restore-test AGE_IDENTITY=./age-key.txt
# A specific dump, another remote:
make backup-restore-test AGE_IDENTITY=./age-key.txt BACKUP_REMOTE=b2ro:btp-backups \
  OBJECT=monthly/pg-app-20261101T013000Z.dump.age
```

It prints `restore-test: <object>: <n> tables, <n> users` then `restore-test: OK`, and exits
non-zero otherwise (missing dump, wrong key, a `pg_restore` error, too few users). To run it on a
schedule, call the script from a local timer or cron with `HEARTBEAT_URL` set to a second
heartbeat check (period ~31 days for a monthly test): a skipped or failing test then alerts.

## Verification and follow-up

- After a retention change: `rclone lsf b2:btp-backups/daily` still lists the recent dumps the
  next morning, and the heartbeat check is green.
- After a restore test failure: treat it as a P2 ([severity-levels.md](severity-levels.md)). The
  backups are not usable until the cause is fixed.

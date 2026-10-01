# Deployment

How to ship, preview, roll back and configure Bike Trip Planner in production.

Production is a single Oracle Cloud Always Free ARM VM. **Ansible** provisions it (Docker,
Traefik, `cloudflared`, the shared Valhalla and PG-reference, secrets from Vault); the
`.github/workflows/deploy.yml` workflow **deploys over SSH**. Traffic reaches the VM only through
a **Cloudflare Tunnel**, and **Traefik** routes it to the stacks by Docker labels; the VM exposes
no public port. The reasoning is in [ADR-061](adr/adr-061-deployment-ansible-gha-ssh-traefik-tunnel.md)
(amending [ADR-019](adr/adr-019-deployment-infrastructure-strategy.md)) and the topology in
[`ansible/README.md`](../ansible/README.md).

## What the deploy workflow does

| Job                 | Runs on                        | Does                                                                 |
|---------------------|--------------------------------|----------------------------------------------------------------------|
| `build-images`      | `v*` tag, same-repo pull request | Builds `php`, `pwa` and `provisioner` for `linux/arm64` on a native ARM runner and pushes them to `ghcr.io/vincentchalamon/bike-trip-planner-<service>`, tagged `:<sha>` plus `:<tag>` or `:pr-<n>`. Keeps the 10 most recent versions of each image, never pruning `vX.Y.Z` tags |
| `upload-sourcemaps` | `v*` tag                       | Builds the web app with the `SENTRY_*` secrets so `withSentryConfig` uploads the source maps, then deletes them. Skipped when those secrets are missing; the deployed image never ships source maps |
| `deploy-prod`       | `v*` tag                       | SSHes to the VM, checks out the tag and runs `btp-compose up -d --pull always` (images pinned to that tag) |
| `smoke-test`        | after `deploy-prod`            | Probes `/api/healthz`, then `/api/health` until `status` is `ok`; on failure raises a `repository_dispatch` (`uptime_alert`) that `incident-create.yml` turns into an incident issue |
| `deploy-preview`    | same-repo pull request         | Deploys the PR to `pr-<n>.${DOMAIN}` from its own checkout, with its own PG-app and Redis |
| `teardown-preview`  | pull request closed            | `docker compose down -v` on the preview and deletes its checkout     |

The SSH jobs are no-ops while `SSH_HOST`, `SSH_USER` or `SSH_KEY` is unset (forks, or before the
VM exists): a tag then only builds and pushes the images. Forks never build or deploy.

Database migrations run when the `php` container boots
([ADR-032](adr/adr-032-migrations-and-rollback-strategy.md)).

## Release to production

1. Go through the [release checklist](runbooks/release-checklist.md).
2. Tag `main` with a plain semantic version and push the tag:

    ```bash
    git tag v1.4.0
    git push origin v1.4.0
    ```

    `deploy-prod` refuses any tag that is not exactly `vMAJOR.MINOR.PATCH`.

3. Follow the `Deploy` run in GitHub Actions. A green `smoke-test` means the new stack answers and
   at least one worker is consuming messages.

## Preview a pull request

Open the pull request from a branch of this repository. `deploy-preview` publishes it at
`https://pr-<n>.${DOMAIN}`; every push redeploys it and closing the PR tears it down.

A preview shares the production Valhalla and PG-reference, but has its own PG-app and Redis and
reads its own non-production env file (`preview.env`). Each one budgets about 2 GB of RAM, so keep
the number of open previews small. A change to the reference schema cannot be previewed, because
previews never migrate the shared PG-reference.

## Roll back

Redeploy the previous tag: re-run the `deploy-prod` job of that tag's run in GitHub Actions. On the
VM, the equivalent is:

```bash
/opt/bike-trip-planner/deploy-prod.sh v1.3.2
```

Both check out the tag and roll the stack through `btp-compose`, the Ansible-installed wrapper
every production compose call goes through: it takes the image tag from the tag the checkout is
on, so the images always match the compose files that run them.

Then check that `/api/healthz` and `/api/health` answer `ok`. Migration caveats and the full
procedure are in the [rollback runbook](runbooks/release-rollback.md).

## Configure the GitHub secrets

| Secret                    | Needed for                   | Purpose                                                        |
|---------------------------|------------------------------|----------------------------------------------------------------|
| `GITHUB_TOKEN`            | always (built in)            | Push images to GHCR                                            |
| `NEXT_PUBLIC_SENTRY_DSN`  | image build                  | Client DSN inlined into the web bundle; without it, browser error capture is off |
| `SENTRY_AUTH_TOKEN`, `SENTRY_URL`, `SENTRY_ORG`, `SENTRY_PROJECT` | source maps | Release creation and source-map upload                  |
| `SSH_HOST`, `SSH_USER`, `SSH_KEY` | prod and previews    | SSH access to the VM as the Ansible-provisioned `deploy` user  |
| `SSH_KNOWN_HOSTS`         | prod and previews (recommended) | Pinned host key; without it the job trusts a live `ssh-keyscan` |
| `PROD_REPO_DIR`           | optional                     | Checkout on the VM, default `/opt/bike-trip-planner`           |
| `PROD_HEALTH_URL`         | optional                     | Host probed by `smoke-test`, default `https://www.bike-trip-planner.com` |
| `PREVIEW_REPO_ROOT`       | optional                     | Root of the preview checkouts, default `/opt/bike-trip-planner-previews` |
| `PREVIEW_ENV_FILE`        | optional                     | Preview env file, default `<PREVIEW_REPO_ROOT>/preview.env`    |
| `INCIDENT_DISPATCH_TOKEN` | smoke-test failure           | Fine-grained PAT (`Contents: write`, `Issues: write`) that raises the incident dispatch |

## Configure the runtime environment

The repository versions a root `.env` holding the development defaults. Production never reads it:
Ansible renders `/etc/bike-trip-planner/app.env` from Vault, outside the checkout that
`deploy-prod` force-updates, and `btp-compose` passes it with `--env-file`.

| Variable              | Default                          | Role                                                     |
|-----------------------|----------------------------------|----------------------------------------------------------|
| `DOMAIN`              | `localhost`                      | The DNS zone. `SERVER_NAME`, `FRONTEND_URL`, `TRUSTED_HOSTS`, `CORS_ALLOW_ORIGIN`, `DEFAULT_URI`, `MERCURE_PUBLIC_URL`, the Traefik router and the previews all derive from it; `deploy/prod/compose.yaml` adds the `www.` prefix |
| `MAILER_SENDER_EMAIL` | `noreply@bike-trip-planner.com`  | Sender of every transactional email                      |
| `CONTACT_EMAIL`       | `contact@bike-trip-planner.com`  | Legal contact shown by the web and mobile apps           |

`DOMAIN` is the zone rather than the served host because Compose can concatenate but not split:
the previews and the Cloudflare wildcard need the zone. The two addresses are separate because
`noreply@localhost` has no TLD and the sending domain may differ from the served one.

The `php` entrypoint refuses to boot when `APP_SECRET`, `MERCURE_JWT_KEY` or
`REFRESH_TOKEN_ENC_KEY` is unset or still a default, when `MERCURE_JWT_KEY` is shorter than
32 bytes, when `OAUTH_ENCRYPTION_KEY` is unset, or when the OAuth keypair is missing or identical
to the session JWT keypair ([ADR-079](adr/adr-079-authorizing-an-agent-without-giving-it-a-session.md)).
`ACCESS_REQUEST_HMAC_SECRET` fails closed at request time instead. The full list of secrets is in
the [secrets inventory](runbooks/secrets-inventory.md).

A new runtime variable must be passed through in `compose.yaml` for both `php` and `worker`, and
added to the Ansible env template when production must set it: a variable only declared in
`compose.dev.yaml` is empty in production.

## Provision or rebuild the VM

Follow [`ansible/README.md`](../ansible/README.md): the steps done outside Ansible (OCI instance,
Cloudflare tunnel and DNS, routing tiles, SSH access for GitHub Actions), then

```bash
ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass
```

The playbook is idempotent; a reclaimed VM is recovered by running it against the new host (see
[Oracle VM reclaimed](runbooks/oracle-vm-reclaimed.md)). It does not start the application stack:
the next `deploy-prod` does.

The routing graph is built off the VM and shipped with `make routing-publish <user@host> <country>...`
([Valhalla routing graph](runbooks/valhalla-routing-graph.md)); reference zones are opened with the
provisioner ([Opening a zone](runbooks/zone-opening.md)).

## Scheduled jobs on the VM

All are systemd timers installed by Ansible; there is no in-stack cron.

| Timer             | Default                           | Does                                                              |
|-------------------|-----------------------------------|-------------------------------------------------------------------|
| `btp-backup`      | daily 02:30                       | `pg_dump` of PG-app, encrypted with `age`, uploaded with `rclone` to B2 and/or OCI, GFS retention ([ADR-062](adr/adr-062-backup-and-disaster-recovery.md)). On demand: `make backup-now BACKUP_SSH=deploy@<vm>` |
| `events-refresh`  | off; Monday 04:00 when enabled    | Re-imports DataTourisme and OpenAgenda events for the open zones and purges past events ([events refresh](runbooks/events-refresh.md)) |
| reclaim heartbeat | off; every 10 minutes when enabled | Keeps the VM above Oracle's idle-reclaim threshold               |

PG-reference is not backed up: it is rebuilt from the sources. Times are in the VM timezone
(`Europe/Paris` by default).

## Monitoring

- **Health:** `GET /api/healthz` (liveness, no dependency) and `GET /api/health` (readiness:
  PostgreSQL, Redis, Valhalla and a live Messenger consumer are required; PG-reference and Mercure
  are reported but not required, [ADR-075](adr/adr-075-readiness-covers-the-consumers.md)).
- **Errors:** the Sentry SDKs (`sentry/sentry-symfony`, `@sentry/nextjs`) report to the DSN
  configured in `SENTRY_DSN` / `NEXT_PUBLIC_SENTRY_DSN`. A self-hosted GlitchTip stack is kept in
  [`.docker/glitchtip`](../.docker/glitchtip/README.md) but not deployed
  ([ADR-031](adr/adr-031-error-tracking-strategy.md)).
- **Uptime:** an external probe on `/api/healthz`; a self-hosted Uptime Kuma stack is kept in
  [`.docker/uptime-kuma`](../.docker/uptime-kuma/README.md) but not deployed
  ([uptime monitoring](runbooks/uptime-monitoring.md)).
- **Incidents:** alerts become issues through `incident-create.yml`; the playbooks are in the
  [runbooks](runbooks/README.md).

## Logs: what they hold and how long they stay

Every container writes to stdout/stderr, kept by Docker's `json-file` driver. The `docker`
Ansible role writes `/etc/docker/daemon.json` with `max-size: 20m` and `max-file: 5`
(`docker_log_max_size` / `docker_log_max_file` in `group_vars/all.yml`). Each container
therefore keeps at most 100 MB of logs on disk, and the oldest file goes first. Retention is
bounded by size, not by time: on a quiet stack that is weeks, under load a few days. The options
only apply to containers created after the daemon read them. The first playbook run after this
change restarts Docker, and the containers that already existed keep unbounded logs until they
are recreated (`docker compose up -d --force-recreate`, or the next deploy that changes them).

What reaches those logs is kept free of personal data and credentials, at the source:

- **Application (Monolog):** every record goes through `App\Logger\RedactionProcessor`, and the
  prod JSON lines through `RedactingJsonFormatter`. Email addresses, verify tokens, share codes,
  FCM tokens, signed or keyed query values and positions are redacted. Users are logged by id,
  addresses without a user by `EmailFingerprint`, client IPs not at all.
- **Edge:** Caddy's access log redacts the same URL parts (URI and Referer). Traefik's logs no
  path and no query.
- **PostgreSQL:** slow statements are logged without their bound values
  (`log_parameter_max_length=0`).
- **Errors (Sentry):** request bodies are never read, and query strings, cookies, URL secrets
  and addresses are scrubbed before an event leaves (backend `EventScrubber`, PWA
  `sentry-scrub.ts`). How long the tracker keeps events is set in the tracker, not here.

One store holds personal data by design: the Messenger `failed` transport (a Redis stream). A
message that exhausted its retries is kept there whole, so it can be retried. That includes the
waypoint coordinates of a `RecalculateRouteSegment`, and the title, body and FCM tokens of a
`SendPushNotification`. Nothing expires it. Triage it with
[worker-stuck](runbooks/worker-stuck.md), then retry or remove it.

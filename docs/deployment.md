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
| `build-images`      | `v*` tag, same-repo pull request | Builds `php`, `pwa` and `provisioner` for `linux/arm64` on a native ARM runner. A tag pushes `ghcr.io/vincentchalamon/bike-trip-planner-<service>:<sha>` and `:<tag>`; a pull request pushes only `bike-trip-planner-<service>-preview:pr-<n>`, a separate package. Outputs the image digests. Keeps the 10 most recent versions of each image, never pruning `vX.Y.Z` tags |
| `upload-sourcemaps` | `v*` tag                       | Builds the web app with the `SENTRY_*` secrets so `withSentryConfig` uploads the source maps, then deletes them. Skipped when those secrets are missing; the deployed image never ships source maps |
| `deploy-prod`       | `v*` tag                       | SSHes to the VM and runs `deploy-prod.sh <tag> <commit> <digests>`: records them on the first deploy of the tag, checks out the tag and rolls the stack through `btp-compose`, which runs the images by digest |
| `smoke-test`        | after `deploy-prod`            | Probes `/api/healthz`, then `/api/health` until `status` is `ok`; on failure raises a `repository_dispatch` (`uptime_alert`) that `incident-create.yml` turns into an incident issue |
| `deploy-preview`    | same-repo pull request         | Sends `deploy <pr> <php-digest> <pwa-digest>` to the VM's `btp-preview` forced command, which runs the PR images at `pr-<n>.${DOMAIN}` with its own PG-app and Redis, using main's compose files |
| `teardown-preview`  | pull request closed            | Sends `teardown <pr>`: `docker compose down -v` on the preview      |

The SSH jobs are no-ops while `SSH_HOST`, `SSH_USER` or `SSH_KEY` is unset (before the VM
exists): a tag then only builds and pushes the images. When they are set, `SSH_KNOWN_HOSTS` is
required; a job fails rather than trust a host key it scanned. Forks never build or deploy.

Only `build-images` gets `packages: write`. `upload-sourcemaps`, `deploy-prod` and `smoke-test`
bind the `production` environment, the preview jobs the `preview` one (see
[the GitHub configuration](#configure-github)).

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

Open the pull request from a branch of this repository. Once a reviewer of the `preview`
environment approves the run, `deploy-preview` publishes it at `https://pr-<n>.${DOMAIN}`; every
push redeploys it and closing the PR tears it down (the teardown waits for the same approval).

The PR only chooses the images. The VM side is the `btp-preview` forced command of a dedicated
`preview-deploy` user: it pulls the PR images by digest and runs them with the compose files of
its own checkout of `main` (`/opt/bike-trip-planner-previews/base`). A PR that changes
`compose.yaml` or `deploy/preview/compose.yaml` is therefore previewed with main's version of
those files.

A preview reads the production Valhalla and PG-reference (over `btp-shared-preview`, never prod's
networks), but has its own PG-app and Redis and reads its own non-production env file
(`/opt/bike-trip-planner-previews/preview.env`), rendered by Ansible from the `vault_preview_*`
secrets; the playbook refuses a preview secret or keypair equal to the prod one. Each one budgets about 2 GB of RAM, so keep the
number of open previews small. A change to the reference schema cannot be previewed, because
previews never migrate the shared PG-reference.

## Roll back

Redeploy the previous tag: re-run the `deploy-prod` job of that tag's run in GitHub Actions. On the
VM, the equivalent is:

```bash
/opt/bike-trip-planner/deploy-prod.sh v1.3.2
```

Both check out the tag and roll the stack through `btp-compose`, the Ansible-installed wrapper
every production compose call goes through. It runs the images recorded for the tag the checkout
is on, by digest, and refuses to run if the tag moved since. The record
(`/etc/bike-trip-planner/releases/<tag>`) is written by the tag's first `deploy-prod`; a VM
rebuilt since has none, so re-run that job, or pass the commit and digests listed in its run
summary: `deploy-prod.sh <tag> <commit> <php-digest> <pwa-digest> <provisioner-digest>`.

Then check that `/api/healthz` and `/api/health` answer `ok`. Migration caveats and the full
procedure are in the [rollback runbook](runbooks/release-rollback.md).

## Configure GitHub

A pull_request run executes the workflow file of the PR, so a branch can rewrite `deploy.yml`.
What protects production is therefore GitHub configuration, not the workflow:

- **Environment `production`**: deployment branches and tags limited to the tag pattern `v*`
  (required reviewers optional). A pull_request run cannot read its secrets.
- **Environment `preview`**: required reviewers (the owner). Its key can only run `btp-preview`.
- **Tag ruleset** on `v*`: only the owner may create, update or delete release tags.
- **No SSH, Sentry or incident secret at repository level**: a repository secret is readable by
  any workflow a branch adds.

| Secret                    | Where                  | Purpose                                                        |
|---------------------------|------------------------|----------------------------------------------------------------|
| `GITHUB_TOKEN`            | built in               | Push images to GHCR                                            |
| `NEXT_PUBLIC_SENTRY_DSN`  | repository             | Client DSN inlined into the web bundle (public); without it, browser error capture is off |
| `SENTRY_AUTH_TOKEN`, `SENTRY_URL`, `SENTRY_ORG`, `SENTRY_PROJECT` | `production` | Release creation and source-map upload |
| `SSH_HOST`, `SSH_USER`, `SSH_KEY` | `production`   | SSH access as the `deploy` user (`deploy_ssh_public_keys`)     |
| `SSH_HOST`, `SSH_USER`, `SSH_KEY` | `preview`      | SSH access as `preview-deploy` (`preview_deploy_ssh_public_keys`), a different key |
| `SSH_KNOWN_HOSTS`         | both environments      | Pinned host key, required                                      |
| `PROD_REPO_DIR`           | `production`, optional | Checkout on the VM, default `/opt/bike-trip-planner`           |
| `PROD_HEALTH_URL`         | `production`, optional | Host probed by `smoke-test`, default `https://www.bike-trip-planner.com` |
| `INCIDENT_DISPATCH_TOKEN` | `production`           | Fine-grained PAT (`Contents: write`, `Issues: write`) that raises the incident dispatch |

The `-preview` packages are created by the first PR build and are private by default: make them
public (or log the `preview-deploy` user in to GHCR) so the VM can pull them.

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
32 bytes, when `OAUTH_ENCRYPTION_KEY` or `JWT_PASSPHRASE` is empty, when `DATABASE_PASSWORD` or
`REDIS_PASSWORD` is still the compose default (only the local iso-prod stacks opt out, with
`ALLOW_LOCAL_DEFAULT_CREDENTIALS=true`), or when the OAuth keypair is missing or identical
to the session JWT keypair ([ADR-079](adr/adr-079-authorizing-an-agent-without-giving-it-a-session.md)).
`ACCESS_REQUEST_HMAC_SECRET` fails closed at request time instead. The full list of secrets is in
the [secrets inventory](runbooks/secrets-inventory.md).

The containers run with `no-new-privileges` and every capability dropped except the few each
one uses (`compose.yaml`); PostgreSQL and Redis sit on a `backend` network that pwa does not
join, and Redis requires `REDIS_PASSWORD`. The Caddy admin API is off. Traefik reads the Docker
API through a read-only socket proxy, never the socket itself.

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

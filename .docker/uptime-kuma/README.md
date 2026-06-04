# Uptime Kuma — self-hosted monitoring

> **Beta posture (Sprint 34.5, issue #568):** this stack is **not deployed**
> during the restricted beta. Availability is monitored solely by the external
> **UptimeRobot** probe on `/api/healthz` (see
> [`docs/runbooks/uptime-monitoring.md`](../../docs/runbooks/uptime-monitoring.md)).
> These files are kept for reversibility: deploy the stack below to restore the
> self-hosted primary layer post-beta.

Uptime Kuma is the **primary** uptime monitor for Bike Trip Planner. It runs on
the same Coolify host as the application (Oracle Cloud Always Free VM, see
[ADR-019](../../docs/adr/adr-019-deployment-infrastructure-strategy.md)) and provides
sub-minute detection for backend and frontend outages.

Because it shares the VM with the app, a host-level outage will take Uptime Kuma
down too. The **external** UptimeRobot monitor documented in
[`docs/runbooks/uptime-monitoring.md`](../../docs/runbooks/uptime-monitoring.md)
acts as the off-host safety net.

## 1. Coolify installation

1. Log into the Coolify dashboard.
2. **+ New resource → Docker Compose**.
3. Name the resource `uptime-kuma`. Paste the content of
   [`docker-compose.yml`](./docker-compose.yml) (or point the resource at this
   path in the repository).
4. Set the **domain** to `https://status.biketrip.mooo.com`. Coolify will
   provision the Let's Encrypt certificate via Traefik automatically (FreeDNS
   `mooo.com` A record must already point at the VM public IP).
5. Click **Deploy**. Wait for the healthcheck to turn green
   (`curl -f http://localhost:3001` succeeds).
6. Open `https://status.biketrip.mooo.com` and create the **admin** account
   (only the first visitor can register; subsequent visitors land on the login
   page).

> Storage: the named volume `uptime-kuma-data` persists the SQLite database
> (`/app/data/kuma.db`) and all monitor configuration. Back it up with the
> Coolify volume backup feature; restoring the volume is enough to recover all
> monitors and history.

## 2. Monitors to create

All monitors are created manually via the Uptime Kuma UI
(**+ Add New Monitor**). The table below is the source of truth — keep it in
sync with the deployed configuration.

| # | Type     | Target                                                              | Interval | Retries | Severity | Notes                                                       |
| - | -------- | ------------------------------------------------------------------- | -------- | ------- | -------- | ----------------------------------------------------------- |
| 1 | HTTP(s)  | `https://biketrip.mooo.com/api/healthz`                             | 60 s     | 2       | **P1**   | Liveness probe. Must answer `200` with body `ok`.           |
| 2 | HTTP(s)  | `https://biketrip.mooo.com/api/health`                              | 300 s    | 2       | **P2**   | Readiness probe (DB + Redis + Mercure). `503` = degraded.   |
| 3 | Keyword  | `https://biketrip.mooo.com/`, keyword `Bike Trip Planner`           | 300 s    | 2       | **P1**   | Detects PWA shell regressions (blank page, SSR crash).      |
| 4 | DNS      | `biketrip.mooo.com`, resolver `1.1.1.1`, record type `A`            | 300 s    | 2       | **P2**   | Detects FreeDNS / DynDNS expiration.                        |
| 5 | HTTP(s)  | `https://biketrip.mooo.com/.well-known/mercure?topic=test`          | 300 s    | 2       | **P2**   | Accept HTTP status `200,401`. Anything `5xx` = down.        |

### Per-monitor configuration tips

- **Monitor 1 (`/api/healthz`)** — `Accepted Status Codes: 200`. Add the
  notification channel **immediately**: this is the only sub-minute alarm.
- **Monitor 2 (`/api/health`)** — `Accepted Status Codes: 200`. A `503` here
  means at least one dependency (PostgreSQL / Redis / Mercure) is degraded.
- **Monitor 3 (keyword)** — `Keyword: Bike Trip Planner`,
  `Case Sensitive: yes`. Validates the PWA actually renders the app shell.
- **Monitor 4 (DNS)** — `DNS Resolver Server: 1.1.1.1`,
  `Resource Record Type: A`. Compare with the expected IP of the Oracle VM.
- **Monitor 5 (Mercure)** — `Accepted Status Codes: 200-299, 401`. Mercure
  returns `401` for unauthenticated subscribers, which is the healthy answer
  here. Any `502/503/504` indicates the hub is unreachable.

## 3. Notification webhook (prepares P1.3)

Uptime Kuma must push DOWN / UP events to GitHub's `repository_dispatch` API
so the incident workflow (#P1.3) can react automatically.

**Settings → Notifications → Setup Notification**:

- **Notification Type**: `Webhook`
- **Friendly Name**: `github-incident-dispatch`
- **Post URL**: `https://api.github.com/repos/vincentchalamon/bike-trip-planner/dispatches`
- **Request Body**: `Custom Body`
- **Body**:

  ```json
  {
    "event_type": "uptime_alert",
    "client_payload": {
      "source": "uptime-kuma",
      "monitor": "{{ monitorJSON.name }}",
      "status": "{{ status }}",
      "msg": "{{ msg }}"
    }
  }
  ```

- **Additional Headers**:

  ```json
  {
    "Accept": "application/vnd.github+json",
    "Authorization": "Bearer <INCIDENT_DISPATCH_TOKEN>",
    "X-GitHub-Api-Version": "2022-11-28"
  }
  ```

  Replace `<INCIDENT_DISPATCH_TOKEN>` with a fine-grained GitHub PAT scoped to
  the repository with the **Contents: Read & write** permission (required for
  `repository_dispatch`). Store the token in the Coolify secret store, never in
  the Uptime Kuma database backup.

- **Apply on all existing monitors**: enable.
- Test with **Test** — GitHub answers `204 No Content` on success.

## 4. Public status page

**Settings → Status Pages → New Status Page**:

- **Slug**: `public` (final URL: `https://status.biketrip.mooo.com/status/public`)
- **Title**: `Bike Trip Planner — Status`
- **Theme**: Auto
- **Published**: yes
- **Monitors**: include all five above, grouped as:
  - **Public services**: keyword (#3), DNS (#4)
  - **API**: healthz (#1), health (#2), Mercure (#5)
- **Show Tags**: no
- **Show Powered By**: optional
- **Domain Names**: leave empty (served on the default Uptime Kuma host)

No authentication is required to view `/status/public`; the page surfaces only
monitor names and uptime percentages, never internal URLs.

## 5. Maintenance

- **Backups**: schedule a weekly snapshot of the `uptime-kuma-data` volume in
  Coolify. The SQLite DB is < 50 MB even with one year of history.
- **Upgrades**: bump the image tag in
  [`docker-compose.yml`](./docker-compose.yml) after reading the release notes,
  then redeploy from Coolify. Never use `latest`.
- **Resource budget**: ~80 MB RAM, < 1% CPU on the Always Free VM.

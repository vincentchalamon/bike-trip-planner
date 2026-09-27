# Getting Started

In this tutorial you clone the repository, boot the development stack, create your own account,
load a small region of reference data and compute your first trip. It takes about 30 minutes,
most of it spent waiting for the first image build and the reference import.

Everything runs in Docker: you do not need PHP or Node.js on your machine.

## Before you start

You need:

- Docker Engine with the Compose v2 plugin (`docker compose version` must work);
- Git;
- GNU Make;
- free ports 80, 443 and 1080 on `localhost`.

## 1. Clone the repository

```bash
git clone https://github.com/vincentchalamon/bike-trip-planner.git
cd bike-trip-planner
```

## 2. Boot the development stack

```bash
make start-dev
```

The first run builds the PHP and Node images, installs the dependencies and applies the database
migrations, so expect several minutes. The command returns once every container is healthy.

You now have:

| Service       | Where                   | Role                                                           |
|---------------|-------------------------|----------------------------------------------------------------|
| `php`         | `https://localhost/docs`| API Platform backend, served by FrankenPHP (Caddy + Mercure hub) |
| `pwa`         | `https://localhost`     | Next.js web app, proxied by the same Caddy                     |
| `worker`      | internal                | Symfony Messenger consumers (2 replicas by default)            |
| `database`    | internal                | PostgreSQL 18 with PostGIS                                     |
| `redis`       | internal                | Cache, computation state and Messenger transport               |
| `mailcatcher` | `http://localhost:1080` | Catches every email the app sends                              |

The Valhalla routing engine is not started: it needs a routing graph that takes hours to build,
and you do not need it for this tutorial.

## 3. Check that it answers

Open `https://localhost` in your browser. Caddy serves a self-signed certificate for `localhost`:
accept the warning once. You should see the landing page.

From a terminal, the liveness probe should answer `{"status":"ok"}`:

```bash
curl -k https://localhost/api/healthz
```

The readiness probe, `https://localhost/api/health`, answers `503` with `"status":"degraded"`
because Valhalla is not running. That is expected here.

## 4. Create your account

Registration is invite-only and sign-in is passwordless. Create a user from the console:

```bash
docker compose exec php bin/console app:create-user you@example.com --locale=en
```

Open Mailcatcher at `http://localhost:1080`, open the invitation email and click its magic link.
You land in the app, signed in.

## 5. Load a region of reference data

Accommodations, points of interest, water points and most alerts come from a local PostGIS index
built from OpenStreetMap, one region at a time. Import Corsica, one of the smallest regions:

```bash
make provision corse -- --allow-unrouted-zone
```

The provisioner downloads the Geofabrik extract and imports it. `--allow-unrouted-zone` skips the
check that the routing graph covers the region; the `--` separator is required so that `make`
passes the flag through. DataTourisme and OpenAgenda imports are skipped with a warning unless
their credentials are set: OpenStreetMap data alone is enough.

## 6. Plan your first trip

Back in the app, give it a route located in Corsica: either paste a public Komoot tour, Strava
route or RideWithGPS route link, or drop a GPX file. The trip computes straight away; you can set
dates and pacing afterwards.

The stages appear first; weather and the other enrichments fill in block by block as the workers
finish them, pushed to the page over Mercure. The walkthrough of the planning screens is in
[Plan your first trip](plan-your-first-trip.md).

Only rerouting a stage to an accommodation or a point of interest needs Valhalla. Everything else
works without it.

## 7. Stop the stack

```bash
make stop    # stop the containers, keep the data
make clean   # remove the containers and every volume (database included)
```

## Troubleshooting

- **Port 80 or 443 already in use:** stop the other process, or set `HTTP_PORT` / `HTTPS_PORT`
  in your shell before `make start-dev`.
- **Code changes to a Dockerfile are not picked up:** `make start-dev` only builds missing images.
  Rebuild with `docker compose up --wait --build`.
- **No invitation email:** check `docker compose logs php`; the dev stack sends mail to
  Mailcatcher only.

## Next steps

- [Contributing](contributing.md): the daily development workflow, tests and quality gates.
- [Architecture](architecture.md): how the pieces fit together.
- [Opening a zone](runbooks/zone-opening.md): choosing and importing other regions.
- [Mobile development build](runbooks/mobile-dev-build-install.md): running the native app.

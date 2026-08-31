# Runbook: refreshing the events layer

Events are the one reference layer that is **not** append-only. A place is there next year;
an event that ended last week is dead weight. So events get a temporal lifecycle (ADR-051
§4): a periodic re-import that adds what is upcoming and purges what has passed, run out of
band by the `events-refresh` command — distinct from `provision <zone>`, which opens a
*place* dataset and never expires a row.

This runbook covers running the refresh by hand, and the production systemd timer that
runs it weekly. Opening a zone is a separate procedure ([zone-opening.md](zone-opening.md)).

## What it does

For every open zone (read from `osm.zones`, the same registry `provision` writes):

1. Re-downloads and parses each configured feed **once** (DataTourisme + OpenAgenda).
2. **Upserts** the zone's events into `tourism.events` — `ON CONFLICT (id) DO UPDATE` of the
   mutable fields (name, start/end date, url, description, price, category, tags, geom,
   source, `last_seen_at`). An event whose dates moved is updated in place, not duplicated.
3. **Purges** past events in the same transaction — `DELETE … WHERE end_date < today`, with
   `today` computed once in `Europe/Paris`. Open-ended events (`end_date IS NULL`) survive.

It writes **only `tourism.events`**. No schema swap, no `osm` / place tables touched, no
Valhalla restart, no Docker socket — which is exactly why it can run on a schedule where
`osm-cron` could not (ADR-036).

## Run it by hand

```bash
# All open zones:
make events-refresh

# A single open zone:
make events-refresh -- --zone=bretagne

# See what it would do (open zones + purge date) without downloading anything:
make events-refresh -- --dry-run
```

Directly, without the Makefile (the form the scheduled task uses):

```bash
docker compose --profile provisioning run --rm \
  --entrypoint php provisioner -d memory_limit=512M bin/events-refresh
```

A source with no credentials (`DATATOURISME_FLUX_ID` / `DATATOURISME_APP_KEY`,
`OPENAGENDA_DATASET`) is skipped, not failed; a feed that fails to download degrades only
its own events, and a zone whose promotion fails does not abort the others (ADR-041
continue-on-error). The exit code is non-zero if any zone or source failed.

The command takes the same provisioning lock as `provision`, so a refresh and a zone
opening never run concurrently.

## Production: the weekly systemd timer (Ansible-provisioned)

The refresh runs on the prod VM as a **systemd timer** provisioned by Ansible (ADR-061) —
there is no in-repo scheduler. The service unit runs the provisioner image with the
`events-refresh` entrypoint against the prod stack:

| Field | Value |
|---|---|
| Command | `docker compose -p prod --profile provisioning run --rm --entrypoint php provisioner -d memory_limit=512M bin/events-refresh` |
| Schedule (`OnCalendar`) | `Sun *-*-* 03:00:00 UTC` — **weekly, Sunday 03:00 UTC** (default; tune the cadence in the Ansible timer var) |

The provisioner container inherits the same environment as the `provisioner` service in
`compose.yaml` (the `PG*` connection and the `DATATOURISME_*` / `OPENAGENDA_*` feed
credentials, rendered by Ansible from Vault), so there is nothing else to wire. The
frequency is a single `OnCalendar` field: raise it if events churn faster than weekly,
lower it to reduce feed bandwidth. Inspect a run with `journalctl -u btp-events-refresh`.

Verify a run:

```bash
# Recently-refreshed events carry a fresh last_seen_at; none should remain past.
psql -c "SELECT count(*) FILTER (WHERE end_date < current_date) AS past,
                max(last_seen_at) AS last_refresh
         FROM tourism.events;"
```

`past` should be `0` right after a run, and `last_refresh` within the last cycle.

## References

- [ADR-051](../adr/adr-051-multi-source-events-openagenda-temporal-lifecycle.md) §4 — the temporal lifecycle decision and the upsert + purge mechanism.
- [ADR-036](../adr/adr-036-manual-osm-data-refresh.md) — why `osm-cron` was removed, and why an events refresh does not bring it back.
- [zone-opening.md](zone-opening.md) — opening a reference zone (the place dataset), which also refreshes that zone's events inline.
- [deployment.md](../deployment.md) — where the scheduled task sits among the other deployment concerns.

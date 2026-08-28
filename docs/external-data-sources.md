# External data sources

Reference for every external dataset the planner ingests: its role, licence, coverage, and the
configuration it needs. The summary table lists all four sources; the sections below detail how
each is imported, cached, and refreshed.

| Source | Role | Licence | Coverage | Prerequisite |
|--------|------|---------|----------|-------------|
| **OpenStreetMap** | Primary: roads, bike infra, water points, bike shops, resupply, base POIs & accommodations | [ODbL](https://opendatacommons.org/licenses/odbl/) | Global | None |
| **DataTourisme** | Complementary: enriched accommodations and cultural POIs; dated events | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | France | `DATATOURISME_API_KEY` |
| **OpenAgenda** | Complementary: dated events (multi-source with DataTourisme), always link-bearing | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | France | `OPENAGENDA_DATASET` |
| **Wikidata** | Cross-cutting enricher: multilingual descriptions, images, Wikipedia links via Q-IDs | [CC0](https://creativecommons.org/publicdomain/zero/1.0/) | Europe | None |

## OpenStreetMap

All geographic and infrastructure data is derived from [OpenStreetMap](https://www.openstreetmap.org). The provisioner imports OSM extracts into a local PostGIS reference index that the API queries directly with spatial predicates (`ST_DWithin` / `ST_Covers`); there is no runtime Overpass dependency. See [ADR-040](adr/adr-040-local-first-reference-data-postgis.md).

**Licence:** [ODbL 1.0](https://opendatacommons.org/licenses/odbl/) — attribution required: "© OpenStreetMap contributors".

### OSM provisioning and refresh

OSM feeds **two independent datasets**, on two grains and two calendars — they share no file and no command:

| | Reference (PostGIS) | Routing (Valhalla) |
|---|---|---|
| Answers | what is near this track? | can we go from A to B? |
| Grain | region (`nord-pas-de-calais`) | country (`france`) |
| Command | `make provision` | `make routing-build france` |
| Cadence | often, one zone at a time | rarely, per country opening |

See [ADR-049](adr/adr-049-zone-opening-and-import-time-completeness.md) for the import model (zone opening, transactional promotion, import-time completeness gate, append-only storage), [ADR-020](adr/adr-020-dynamic-overpass-region-provisioning.md) and [ADR-036](adr/adr-036-manual-osm-data-refresh.md) for the refresh rationale, and [the routing-graph runbook](runbooks/valhalla-routing-graph.md) for the build + upload procedure.

**Opening a zone (one zone per run):**

```bash
make provision bretagne
```

The zone is a **mandatory argument** — a Geofabrik slug or display name. The `provision` command runs inside the `provisioner` container (Compose profile `provisioning`) and loads **all reference sources** for that one zone:

- **OSM + PostGIS** — downloads that zone's Geofabrik extract into the shared `/data` volume, tags-filters it, imports the bikepacking-relevant OSM features (POI, accommodations, water points) into a staging schema scoped to the zone via `osm2pgsql` (flex style `provisioner/osm2pgsql/tier1.lua`), then promotes into the live `osm` schema the keys it does not already hold — registry row, coverage polygon and metadata included — in one transaction. This local-first reference index is what the API reads via `ST_DWithin` corridor queries — see [ADR-040](adr/adr-040-local-first-reference-data-postgis.md).
- **DataTourisme** — downloads the configured flux and promotes the places covered by that zone into the `tourism` schema. Skipped gracefully (with a warning) when `DATATOURISME_FLUX_ID` / `DATATOURISME_APP_KEY` are absent, so OSM still provisions.
- **OpenAgenda** — downloads the national events export and promotes the events covered by that zone into `tourism.events`, deduplicated against DataTourisme. Skipped gracefully when `OPENAGENDA_DATASET` is absent. Unlike the append-only place tables, events are perishable: they are upserted and past events purged, on every `provision` and on the scheduled `events-refresh` (see [ADR-051](adr/adr-051-multi-source-events-openagenda-temporal-lifecycle.md) and [the events-refresh runbook](runbooks/events-refresh.md)).

Three properties of that model (ADR-049), all of them checkable from the command's own report:

- **Opening a second zone keeps the first.** Promotion is an `INSERT ... SELECT` restricted to keys absent from the live tables, never a schema swap, so no other zone is exposed to the run.
- **Re-opening an unchanged zone inserts 0 rows** and rewrites nothing. `last_seen_at` is the single exception, and it is metadata; the payload of an imported row is never overwritten.
- **A zone the routing graph does not cover is refused**, before anything is downloaded, with the `make routing-build <country>` command to run first. The provisioner reads the routing volume read-only to observe that perimeter, and records it so `/api/health` reports the containment invariant.

`osm.zones` is the source of truth for what is open; there is no selection file.

Each opening also writes `.docker/osm/data/zones/<zone>/rejected.tsv`: what the completeness
gate refused, **ranked by distance to the nearest signed cycle route**, so an operator works
the thirty refusals that border a véloroute rather than the three thousand lost in open
country. Corrections go back in as an `override.tsv` via `make provision-override <zone>` —
no endpoint, no interface, no versioning, and nothing stores the file. See the
[corrections runbook](runbooks/zone-opening-corrections.md).

**Refresh (manual):**

Both datasets are refreshed manually — there is no scheduled job — and independently:

```bash
make provision bretagne          # reference: re-open one zone, picking up what
                                 # OSM has gained since (0 new rows if nothing did)
make routing-build france        # routing: rebuild the graph for the perimeter
```

`make routing-build` runs the one-shot `valhalla-builder` (Compose profile `routing-build`): it downloads the national extracts into the `valhalla-tiles` volume, rebuilds tiles + elevation + admin + timezone data, then exits. The `valhalla` service only ever serves that result, so a long or failed build cannot take routing down. Because routing needs a graph that no clean checkout has, it is opt-in: `make start-dev` does not start it, `make routing-up` does.

**Iso-prod recette stack:**

`make provision <zone>` inherits the dev `COMPOSE_FILE` and targets the dev provisioner overlay. To provision against the iso-prod recette stack started with `make start-recette`, use the dedicated target instead:

```bash
make provision-recette nord-pas-de-calais    # open one zone on the recette index
```

It targets `-f compose.yaml -f compose.recette.yaml`. The recette JWT keys and passphrase are wired in `compose.recette.yaml` (no `JWT_*` passed on the command line), and the `prod` / `dev` provisioner images now carry distinct tags (`bike-trip-planner-provisioner:prod` vs `:dev`), so the previous forced `--build` is no longer needed. Like `make provision`, it loads OSM + DataTourisme + OpenAgenda and takes the zone as a mandatory argument — no seed file, and nothing to prepare beyond a routing graph covering the zone's country.

See [ADR-036](adr/adr-036-manual-osm-data-refresh.md) for why the automated nightly job (`osm-cron`) was dropped.

## DataTourisme

[DataTourisme](https://www.datatourisme.fr) provides enriched POI data (accommodations, cultural sites, dated events) for France. It is used as an optional supplementary source alongside OpenStreetMap. What the flux actually carries — fill rates per object type, Accueil Vélo coverage, pricing granularity — is measured in the [DataTourisme flux field audit](datatourisme-flux-audit.md).

**Licence:** [Licence Ouverte 2.0 Etalab](https://www.etalab.gouv.fr/licence-ouverte-open-licence) — commercial use and modification permitted; attribution required.

**Quota:** 1 000 requests/hour, ~10 req/s sustained. Rate limiting is enforced server-side via a `fixed_window` limiter.

**Registration:** [https://www.datatourisme.fr/](https://www.datatourisme.fr/) — free sign-up, personal API key delivered by email.

To enable DataTourisme integration, set the following environment variables:

```env
DATATOURISME_API_KEY=your-api-key
DATATOURISME_ENABLED=true
```

When `DATATOURISME_ENABLED=false` (the default) or the API key is absent, all DataTourisme calls are skipped and the application falls back to OpenStreetMap data exclusively.

## OpenAgenda

[OpenAgenda](https://openagenda.com) is a second, complementary source of **dated events** for France, imported from the national Opendatasoft export. It carries a canonical URL on every record — so an event a rider cannot open is never imported — and is deduplicated against DataTourisme events at read time (`NearbyNameDeduplicator`), with DataTourisme winning ties. Its keywords are mapped onto the same event vocabulary the app filters on (festival, concert, exhibition, sports, fair, show); young-audience records are dropped. See [ADR-051](adr/adr-051-multi-source-events-openagenda-temporal-lifecycle.md).

**Licence:** [Licence Ouverte 2.0 Etalab](https://www.etalab.gouv.fr/licence-ouverte-open-licence) — attribution required (credited on `/legal`).

**Temporal lifecycle:** unlike the append-only place tables, events are perishable. `provision <zone>` and the scheduled `events-refresh` command upsert the feeds and purge past events in the same transaction, so the layer stays current without ever swapping a schema. See [the events-refresh runbook](runbooks/events-refresh.md).

To enable OpenAgenda integration, set the dataset (and, if the export requires it, an API key):

```env
OPENAGENDA_DATASET=evenements-publics-openagenda
OPENAGENDA_API_KEY=your-api-key
```

When `OPENAGENDA_DATASET` is absent, OpenAgenda is skipped gracefully and events come from DataTourisme alone.

## Wikidata

[Wikidata](https://www.wikidata.org) enriches POI, accommodation, and event data already returned by other sources that carry a Wikidata Q-ID (via OSM tag `wikidata=Q12345` or DataTourisme property `owl:sameAs`). Coverage is **European**. Licence is **CC0** — no attribution required.

Fields added: description, Wikimedia Commons thumbnail, Wikipedia article link, and structured opening hours when available.

Enrichment runs **at provision time**, not at request time (ADR-040/041): the provisioner batches the Wikidata SPARQL queries over the bounded set of Q-IDs imported into PostGIS and stores the result in the `osm.*` / `tourism.*` columns, behind a persistent cache (`provisioner.wikidata_cache`). The API reads the enriched columns from the local database — no runtime Wikidata call, no configuration required. A Wikidata outage degrades only the next provisioning refresh, never a user request.

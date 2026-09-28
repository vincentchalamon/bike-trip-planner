# External data sources

Reference for every external dataset or service the planner relies on: its role, licence,
coverage, when it is read, and the configuration it needs. Procedures (opening a zone,
refreshing events, building the routing graph) live in the [runbooks](runbooks/README.md).

| Source | Role | Licence | Read | Configuration |
|--------|------|---------|------|---------------|
| **OpenStreetMap** | Primary: roads, bike infrastructure, water points, shops, services, accommodations, POIs, admin boundaries; routing graph | [ODbL](https://opendatacommons.org/licenses/odbl/) | Imported (provision time) | None |
| **DataTourisme** | Complementary (France): enriched accommodations, cultural POIs, food places, dated events | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | Imported (provision time) | `DATATOURISME_FLUX_ID`, `DATATOURISME_APP_KEY` |
| **OpenAgenda** | Complementary (France): dated events, always link-bearing | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | Imported (provision time + events refresh) | `OPENAGENDA_DATASET` (`OPENAGENDA_API_KEY` optional) |
| **Wikidata** | Enricher: descriptions, images, Wikipedia links, names via Q-IDs | [CC0](https://creativecommons.org/publicdomain/zero/1.0/) | Imported (provision time) | None |
| **Open-Meteo** | Weather forecasts per stage | [CC-BY 4.0](https://creativecommons.org/licenses/by/4.0/) | Runtime, cached 3 h | None |
| **Nominatim** (OpenStreetMap) | Place search and geocoding (stage departure / arrival search, manual accommodation address) | [ODbL](https://opendatacommons.org/licenses/odbl/) | Runtime, cached 24 h | None |

Public holidays are computed locally with the [Yasumi](https://github.com/azuyalabs/yasumi)
library; no external call is made.

## OpenStreetMap

All geographic and infrastructure data is derived from [OpenStreetMap](https://www.openstreetmap.org).
The provisioner imports OSM extracts into a local PostGIS reference index that the API
queries directly with spatial predicates (`ST_DWithin` / `ST_Covers`); there is no runtime
Overpass dependency. See [ADR-040](adr/adr-040-local-first-reference-data-postgis.md).

**Licence:** [ODbL 1.0](https://opendatacommons.org/licenses/odbl/). Attribution required:
"© OpenStreetMap contributors".

OSM feeds **two independent datasets**, on two grains and two calendars; they share no file
and no command:

| | Reference (PostGIS) | Routing (Valhalla) |
|---|---|---|
| Answers | what is near this track? | can we go from A to B? |
| Grain | region (`nord-pas-de-calais`) | country (`france`) |
| Command | `make provision <zone>` | `make routing-build <country>` |
| Cadence | often, one zone at a time | rarely, per country opening |

Properties of the reference import (ADR-049):

- **One zone per run**, the zone being a mandatory argument (a Geofabrik slug or display
  name). A run loads all reference sources for that zone: OSM, then DataTourisme and
  OpenAgenda when configured.
- **Opening a second zone keeps the first.** Promotion inserts only keys absent from the
  live tables, never swaps a schema.
- **Append-only.** Re-opening an unchanged zone inserts 0 rows; the payload of an imported
  row is never overwritten (`last_seen_at` is metadata).
- **Completeness gate.** Rows no resolver can complete are rejected at import time by a
  per-category `CHECK`, and listed in `.docker/osm/data/zones/<zone>/rejected.tsv`, ranked
  by distance to the nearest signed cycle route.
- **Routing perimeter ⊇ reference perimeter.** A zone the routing graph does not cover is
  refused before anything is downloaded; `/api/health` reports the containment invariant.
- **Manual refresh.** There is no scheduled OSM job ([ADR-036](adr/adr-036-manual-osm-data-refresh.md)).

`osm.zones` is the source of truth for what is open. Procedures:
[zone opening](runbooks/zone-opening.md), [corrections](runbooks/zone-opening-corrections.md),
[routing graph](runbooks/valhalla-routing-graph.md). The `valhalla` service only serves a
graph built out of band; it is opt-in in dev (`make routing-up`).

## DataTourisme

[DataTourisme](https://www.datatourisme.fr) provides enriched POI data (accommodations,
cultural sites, food places, dated events) for France. The provisioner downloads the
configured flux from `diffuseur.datatourisme.fr` and promotes the places covered by the
zone into the `tourism` schema; the API only reads that local copy. What the flux actually
carries is measured in the [DataTourisme flux audit](datatourisme-flux-audit.md).

**Licence:** [Licence Ouverte 2.0 Etalab](https://www.etalab.gouv.fr/licence-ouverte-open-licence).
Commercial use and modification permitted; attribution required.

**Configuration:** `DATATOURISME_FLUX_ID` and `DATATOURISME_APP_KEY` on the `provisioner`
service. When either is absent, the DataTourisme step is skipped with a warning and OSM
still provisions.

## OpenAgenda

[OpenAgenda](https://openagenda.com) is a second source of **dated events** for France,
imported from the national Opendatasoft export (`public.opendatasoft.com`). Every record
carries a canonical URL, so an event a rider cannot open is never imported. Events are
deduplicated against DataTourisme at read time (`NearbyNameDeduplicator`), DataTourisme
winning ties. Keywords are mapped onto the app's event types (festival, concert,
exhibition, fair or show); young-audience records are dropped. See
[ADR-051](adr/adr-051-multi-source-events-openagenda-temporal-lifecycle.md).

**Licence:** [Licence Ouverte 2.0 Etalab](https://www.etalab.gouv.fr/licence-ouverte-open-licence).
Attribution required (credited on `/legal`).

**Temporal lifecycle:** unlike the append-only place tables, events are perishable.
`make provision <zone>` and `make events-refresh` upsert the events and purge past ones in
the same transaction; in production the refresh runs on a weekly timer. See
[the events-refresh runbook](runbooks/events-refresh.md).

**Configuration:** `OPENAGENDA_DATASET` (e.g. `evenements-publics-openagenda`), plus
`OPENAGENDA_API_KEY` if the export requires one. When the dataset is absent, OpenAgenda is
skipped and events come from DataTourisme alone.

## Wikidata

[Wikidata](https://www.wikidata.org) enriches POIs, accommodations and events that carry a
Q-ID (OSM tag `wikidata=Q12345`, or DataTourisme `owl:sameAs`). Fields added: description,
Wikimedia Commons thumbnail, Wikipedia article link, and a label used to name otherwise
unnamed accommodations.

Enrichment runs **at provision time**, not at request time: the provisioner batches SPARQL
queries against `query.wikidata.org` over the Q-IDs imported into PostGIS, behind a
persistent cache (`provisioner.wikidata_cache`), and stores the result in the `osm.*` /
`tourism.*` columns. A Wikidata outage degrades only the next import, never a user request.

**Licence:** CC0, no attribution required.

## Open-Meteo

[Open-Meteo](https://open-meteo.com) provides the per-stage forecast (temperature,
feels-like, precipitation, wind and gusts, hourly slots), up to 16 days ahead. It is called
at runtime through a scoped HTTP client (`api.open-meteo.com`) and cached in Redis for
3 hours. Only coordinates and dates are sent.

**Licence:** [CC-BY 4.0](https://creativecommons.org/licenses/by/4.0/). Credit Open-Meteo.

## Nominatim

The public [Nominatim](https://nominatim.openstreetmap.org) service backs the place search
used to move a stage's departure or arrival, geocodes the address of a manually added
accommodation, and serves `/geocode/reverse`. Stage end-point labels do not use it: they
are resolved from the local admin-boundary index. It is called through a scoped client that follows no redirect, rate-limited,
and cached in Redis for 24 hours.

**Licence:** OpenStreetMap data, [ODbL](https://opendatacommons.org/licenses/odbl/).

# ADR-044: Removal of the data.gouv.fr Weekly-Markets Source

- **Status:** Accepted
- **Date:** 2026-06-23
- **Depends on:** ADR-026 (Multi-Source Data Integration)
- **Supersedes (in part):** ADR-026 — the data.gouv.fr weekly-markets source only. The OpenStreetMap, DataTourisme, and Wikidata sources introduced by ADR-026 are unaffected.

## Context and Problem Statement

ADR-026 added the French open-data portal **data.gouv.fr** as a fourth reference source, dedicated to **weekly markets** (marchés forains). The "Marchés forains et brocantes" dataset was imported offline into a PostgreSQL `market` table via the `app:markets:import` CLI command, and markets were attached to each stage as events of type `'market'` (source `'data_gouv_markets'`) during the event scan.

Two facts make this source untenable:

1. **The resource is dead.** The data.gouv.fr dataset referenced by the importer is no longer available, and there is no reliable national equivalent that provides geocoded markets with a trading day and time slot.
2. **A market is not actionable without its trading day.** The entire value of the feature was telling a cyclist "there is a market here, today". Without a trustworthy day-of-week, a market POI is indistinguishable from a generic resupply point — which OSM (`marketplace` / `supermarket`) already covers as a ravitaillement POI, independently of this source.

The feature therefore carries maintenance and surface-area cost (a Doctrine entity, a repository, a CLI command, a scheduled GitHub workflow, a scoped HTTP client, an event type, a translation key, UI attribution) for no usable output.

## Decision Drivers

- **Remove dead code** — a source that cannot return usable data should not stay in the tree.
- **Reduce surface area** — fewer entities, commands, workflows, and scoped clients to maintain and secure.
- **Honest attribution** — the UI must not credit a source the application no longer uses.
- **No collateral damage** — the OSM `marketplace` / `supermarket` ravitaillement POIs are a separate concern and must stay intact.

## Considered Options

### Option A — Keep the feature, swap the dataset

Rejected. No reliable national replacement dataset exists with the required day-of-week + time-slot granularity. Keeping the code on the hope of a future source is speculative.

### Option B — Keep the `market` table, stop the import

Rejected. An empty table produces no events but keeps all the dead machinery (entity, repository, command, workflow, scoped client, event type, attribution) on the books, which is the cost we want to remove.

### Option C — Remove the data.gouv.fr markets source entirely (Chosen)

Remove all code, configuration, import tooling, the event type, and the UI attribution tied to the data.gouv.fr markets feature.

## Decision

**Option C.** The data.gouv.fr weekly-markets source is removed in full:

- **Entity / persistence:** the `Market` entity, `MarketRepository`, `MarketRepositoryInterface`, and the `Version20260418000000` migration that created the `market` table are deleted. Because the project is pre-production, the migration file is removed rather than shipping a `DROP TABLE` migration.
- **Import:** the `app:markets:import` command (`ImportMarketsCommand`), its tests, the `import-markets.yml` GitHub workflow, the `markets-import` Makefile target, and the three `app:markets:import` calls in the `provision` / `provision-update` / `provision-recette` targets are removed. The `MARKETS_DATASET_URL` environment variable is no longer referenced.
- **Event scan:** `ScanEventsHandler` no longer fetches markets — it keeps only the DataTourisme event scan. The `'market'` event type and the `'data_gouv_markets'` source disappear from the pipeline; the `market.weekly_description` translation key (and the now-empty `messages.*.yaml` default-domain files) are removed.
- **HTTP client:** the `markets.client` scoped HTTP client (scoped to `data.gouv.fr`) is removed from `framework.php`.
- **Frontend:** the `market` event-type mapping, the `"market"` map-icon category, and the data.gouv.fr attribution block (with its `events.type_market` and `attribution.datagouvCredit` translation keys) are removed; the map-legend "event" label no longer mentions markets.
- **Docs:** ADR-026 carries a partial-supersession note pointing here; the data.gouv.fr rows and sections are removed from `README*`, `docs/README*`, `docs/legal-and-licensing*`, and the provisioning runbooks/ADRs.

The OSM `marketplace` and `supermarket` categories — used as **ravitaillement** POIs in `FixedSchedule`, `ScanPoisHandler`, `WaypointMapper`, and the LLM summary builder — are explicitly **out of scope** and untouched.

## Consequences

### Positive

- **Less dead code and surface area** — one entity, one repository, one command, one workflow, one scoped HTTP client, one event type, and the related translations/attribution are gone.
- **Honest UI** — the attribution footer no longer credits a source the application does not use; three sources remain (OpenStreetMap, DataTourisme, Wikidata).
- **Simpler provisioning** — `make provision` / `provision-update` / `provision-recette` load OSM + DataTourisme only; there is no separate markets import step.

### Negative

- **No weekly-market events** — stages no longer surface market events. In practice this output was already unusable once the dataset died, so no working capability is lost. OSM `marketplace` resupply POIs remain available through the regular POI scan.

### Neutral

- **Pre-production, no DROP migration** — the table-creation migration is deleted outright. A fresh database never creates the `market` table; environments that already ran the old migration can drop the orphan table manually if desired.
- **Translation `messages` domain retired** — the default `messages` domain no longer has any keys (every remaining `trans()` call targets the `auth`, `access_request`, or `alerts` domains), so the empty `messages.*.yaml` files are removed.

## Sources

- [ADR-026: Multi-Source Data Integration](adr-026-multi-source-data-integration.md)

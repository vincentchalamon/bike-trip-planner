# ADR-048: In-Ride Assistance Without AI

- **Status:** Accepted
- **Date:** 2026-08-06
- **Depends on:** ADR-040 (Local-first reference data — single PostGIS source), ADR-043 (Synchronous structural computation), ADR-023 (Authentication strategy)
- **Supersedes (in part):** ADR-028, ADR-030, ADR-039 (their in-ride paragraphs only — the self-hosted-AI reasoning is untouched), ADR-042 (§7 and §4: the in-ride mode leaves the BYO-token perimeter), ADR-045 (its "Out of scope: the in-ride / loaded-trip chat" paragraph), ADR-046 (the in-ride chat bubble is no longer a masked AI surface)
- **Numbering note:** reserved by issue [#936](https://github.com/vincentchalamon/bike-trip-planner/issues/936) (Sprint 51); ADR-050 already records the reservation.

## Context and Problem Statement

Before this sprint the in-ride surface was an **AI feature**. A rider on a stage typed a free-text request ("where can I refill water?") into the loaded-trip chat (`POST /trips/{id}/ai-chat`); an LLM **classifier** turned that into one of four intents (water, shelter, food, mechanic), the backend read the POIs, and an LLM **narrator** wrote the answer. Both LLM passes already had a deterministic fallback: the classifier fell back to keyword matching, the narrator to a templated string, because the assistant had to work when the provider was down or the user had no token.

That design carried three costs the sprint removes:

- **A token requirement for a mid-ride need.** ADR-042 gates every AI feature on a per-user BYO token; ADR-046 then hid the whole AI surface (including the in-ride chat bubble) behind `NEXT_PUBLIC_ENABLE_AI`. So a rider had to configure a cloud provider, and the operator had to un-hide the flag, before "find me water" worked at all. Requiring a paid credential to answer a safety-adjacent question in the middle of a stage is disproportionate.
- **The LLM added nothing the fallbacks did not.** The classifier picked one of a tiny closed set of intents — a job eight buttons do exactly, with no latency, no quota, no misclassification. The narrator produced one sentence per POI — a job an i18n template does, translated, deterministic, testable.
- **The chat carried mutation actions and persisted history** (`split_stage`, `merge_stages`, `find_poi`, …, and the `trip_chat_message` table). None of that is needed to read nearby POIs, and all of it is surface to secure, translate and maintain.

The decision this ADR records: **remove AI from the in-ride path entirely** and rebuild it as a deterministic, always-available reader over the local-first Tier-1 PostGIS index (ADR-040). It also records the fourteen non-obvious arbitrations made along the way, because several are deliberate divergences that are only defensible written down.

## Decision

Replace the LLM-driven in-ride chat with a **stateless, AI-free POI finder**: eight fixed intent buttons, a `POST /trips/{id}/nearby-pois` endpoint (`NearbyPoiSearchProcessor` → `NearbyPoiFinder`), a PostGIS read (`InRidePoiRepository`), a deterministic opening-hours filter (`OpeningHoursParser`), a server-side detour approximation (`DetourCalculator`) and a one-tap handoff to the rider's maps app (`DeeplinkBuilder`). No token, no LLM, no persisted conversation.

### 1. Why AI disappears from the in-ride path

The classifier and the narrator each already had a deterministic fallback, so neither was load-bearing. **Eight buttons replace the classifier** — the rider names the intent directly, so there is nothing to infer. **An i18n template replaces the narrator** — POI name, distance, opening status and a warning render from typed fields, translated. Requiring a BYO token (ADR-042) for a function used in the middle of a stage was disproportionate, and hiding it behind the AI flag (ADR-046) meant it was invisible in prod. Removing the LLM makes the feature always available, offline-friendly and free.

### 2. What remains of AI

AI is **not** removed from the product — only from the in-ride path. Two AI surfaces stand, both still per-user BYO-token (ADR-042) and still behind `NEXT_PUBLIC_ENABLE_AI` (ADR-046):

- **Trip analysis** — the async trigger, the per-stage briefing and the whole-trip synthesis (ADR-027 Phase 2).
- **Route creation** — generation from a free-text brief (`POST /trips/ai-generate`) and the pre-trip framing chat (`POST /trips/ai-chat`, ADR-045).

What is gone is **AI on a loaded trip**: the loaded-trip chat, its mutation actions and its history. There is no AI acting on an existing, computed trip any more.

### 3. The eight intents and their Tier-1 mapping

`InRidePoiCategory` widens the four legacy intents to the eight actionable buckets the index already holds. Each case drives one `osm.*` table, a candidate cap, a name requirement and a default radius:

| Intent | Table | Deliberate scoping |
|--------|-------|--------------------|
| `water` | `osm.water_points` | real drinking-water points (not the old cemetery proxy) |
| `shelter` | `osm.accommodations` (`category = 'shelter'`) | exclusion list, not whitelist — see §5 |
| `food` | `osm.pois` | `restaurant, cafe, fast_food, bar, pub` |
| `resupply` | `osm.pois` | shopping half: `supermarket, convenience, bakery, butcher, greengrocer, deli, general, pastry, farm, marketplace` |
| `mechanic` | `osm.bike_shops` | — |
| `health` | `osm.health_services` | — |
| `train` | `osm.railway_stations` | — |
| `charging` | `osm.charging_stations` | bike-usable posts only (`bicycle=yes` or a bike-compatible socket key) |

**Deliberate exclusions.** `fuel` and `pharmacy` are **not** in the resupply bucket: a fuel station is not resupply, and a pharmacy is served by the `health` bucket instead — so a pharmacy indexed in both `osm.pois` and `osm.health_services` surfaces once, never twice. There is **no DataTourisme layer**, **no dated-events layer** and **no viewpoints** in the in-ride reader: those serve trip planning and discovery, not a rider deciding where to refill or take cover right now.

### 4. Tri-state opening hours, and the assumed divergence from planning

`OpeningHoursParser::status()` returns a **tri-state** verdict — `OPEN`, `CLOSED`, `UNKNOWN` — and the finder acts on it as follows:

- `CLOSED` → the POI is **dropped**. A `Mo-Fr 09:00-17:00` café on a Sunday is genuinely closed; the tag simply omits the day.
- `UNKNOWN` (tag empty or unreadable) → the POI is **kept with a `HOURS_UNVERIFIED` warning**.
- `OPEN` → kept, with a `CLOSES_SOON` warning if it shuts within 30 minutes.

This **diverges on purpose** from `App\Engine\OpeningHours::isOpenAt()` and its `FixedSchedule`, which trip planning uses: **in-ride discards a line it judges almost certainly closed** (a closed shop is noise to a rider deciding where to stop now), while **planning keeps it with an uncertainty flag** (a planner wants to see it and judge). The two engines are not unified because their jobs differ. They **agree on the one invariant that matters**: "no information" is never rendered as "closed". Planning is not touched by this ADR.

### 5. Bus shelters kept and labelled, against a whitelist that never existed

The shelter bucket keeps the **exclusion-list** stance decided in #928: on the ride, an `amenity=shelter` of any kind keeps rain off, so only useless street furniture is dropped — `carport`, `gazebo`, `umbrella`, `shopping_cart`. In particular `shelter_type=public_transport` (the bus shelter, ~75% of the layer per the [audit](../audit/878-hebergements-osm-sans-nom.md)) is **deliberately kept** and labelled distinctly ("Abribus" / "bus shelter") rather than as a generic shelter.

The README documented, before #928, a **strict whitelist** `basic_hut|weather_shelter|lean_to`. That whitelist **never existed in the code** — it was documentation describing an implementation that was never written. The motive stands: in route, a bus shelter shelters from rain, so excluding it would drop the most common useful cover.

### 6. Nameless rows kept in-ride, dropped in planning

Trip planning does **not** surface a nameless bookable accommodation: `osm.accommodations` enforces a per-category name `CHECK` at import (#884, ADR-049), so a bookable row without a name cannot be inserted and `OsmAccommodationSource` needs no read filter. The in-ride reader, by contrast, **keeps nameless rows for water points, shelters and e-bike chargers**: a fountain, a bus shelter and a charging post are found by position, so a name is not required to act on them. The finder labels them from a localised category string (`shelter_bus` → "Abribus"). Buckets where a nameless row is not actionable — `food`, `mechanic`, `health`, `train` — still require a name, pushed to SQL. **Planning is not modified**; the divergence lives entirely in the in-ride reader.

### 7. Read-time filtering, not a dedicated `osm.shelters` table

The shelter/charger scoping (exclusion list, bike-usable filter) is done as a **jsonb predicate at read time** against the existing `osm.accommodations` / `osm.charging_stations` tables, indexed by their GiST `geom`. The alternative — a dedicated `osm.shelters` table populated by the provisioner — was rejected: it means a provisioner change plus a **full reprovision** of every zone to backfill, for a filter that a WHERE clause on an already-indexed table expresses at no import cost.

### 8. POST, not GET

`POST /trips/{id}/nearby-pois` carries the rider's GPS position **in the body**, never the query string. The position must not land in server access logs, the `Referer` header, or the browser history — all of which capture a URL. `radiusMeters` carries no validation range: an out-of-bounds value is **clamped** to `[MIN_RADIUS_METERS, MAX_RADIUS_METERS]`, not rejected, so a rider never gets a 422 for asking too wide or too narrow.

### 9. The candidate cap, and why "widen the search" needs it

The KNN read caps candidates per category (`candidateLimit()`): **200** for buckets whose opening-hours filter runs in PHP *after* the read (`food`, `resupply`, `mechanic`, `health`), **50** for the always-available ones (`water`, `shelter`, `train`, `charging`). This is what makes the front-end "widen the search" affordance meaningful. Because the opening-hours filter is **not** pushed down to SQL, the 50 nearest can all be closed and leave an empty answer; and widening the radius alone would not change *which* N are nearest — it is a no-op on the KNN order. A larger, category-dependent cap is what lets a wider search actually surface more open venues.

### 10. The out-of-coverage envelope

A position outside `osm.coverage` (ADR-040) short-circuits before any read and returns a **distinct** envelope (`outOfCoverage: true`), so the UI can say "you are outside the covered area" rather than the misleading "nothing nearby" — the two are genuinely different facts and must not collapse into one message.

### 11. One-tap handoff to the maps app — and its non-goals

Each suggestion carries a **Google Maps directions deeplink in bicycling mode** (`DeeplinkBuilder`). Only the **destination** is encoded; the rider's live position is left to the map app, so it never leaves this backend in a URL (consistent with §8). The handoff is a **read-only navigation launch**. Its **non-goals are explicit**:

- **nothing is pushed to the GPS device**;
- **no waypoint is added** to the trip;
- **no route is recomputed**;
- **no GPX is re-exported**.

A `geo:` URI was rejected: it enables offline routing on Android but **iOS ignores the scheme**, so a Google Maps HTTPS directions URL is the portable choice that renders on both mobile and desktop.

### 12. Detour: `DetourCalculator` revived, `detour_m` made nullable

`DetourCalculator` existed but was **dead code**: it needs the remaining-route polyline and nothing supplied it. It is now fed **server-side from `stage.geometry`** (`getStageGeometry`, truncated at the rider position by `RouteTail`), projecting each of the (at most ten) provisional candidates onto the polyline to approximate the extra distance, which then weights the ranking. Consequently **`detour_m` becomes nullable**: when no stage geometry is available the detour is genuinely unknown, and a `0` there would falsely assert "no detour". `null` means "not computed"; ranking falls back to straight-line distance alone.

### 13. Deletion before construction

The sprint **removed the AI in-ride surface before building the AI-free replacement**, inverting the intuitive "add the new, then retire the old" order. Justification: the surface was **already hidden in prod** by the ADR-046 flag, so there was no user-facing window to protect. Keeping the old chat mounted while the new panel was built bought **no guarantee** — the two never coexisted for a user — and it **broke compilation**, because the new endpoints replaced the types the old chat imported. Deleting first kept the tree compiling at every step.

### 14. Dropping `trip_chat_message` is deferred

The in-ride chat's persisted history lived in the `trip_chat_message` table. This sprint stops writing to it but **does not drop it**: per ADR-032's migrations-and-rollback policy, dropping a table is a destructive, hard-to-roll-back change and is deferred to the **following release**, once the code that stopped using it has shipped and been observed stable. The table sits unused until then.

## Consequences

### Positive

- **Always available, offline-friendly, free.** No token, no provider, no quota, no LLM latency in the middle of a stage.
- **Deterministic and testable.** Intent is chosen, not inferred; the answer renders from typed fields through i18n templates; opening hours and detour are pure functions with unit coverage.
- **Smaller AI surface to secure and maintain.** The loaded-trip chat, its mutation actions and its history store are gone.
- **The rider's GPS position stays out of logs, `Referer` and history** (§8), and never leaves the backend in a deeplink (§11).

### Negative

- **Opening-hours filtering in PHP** (not SQL) forces the larger candidate cap of §9 and its extra rows read per query — a deliberate trade for correctness of the "open now" filter and a meaningful "widen search".
- **Two opening-hours engines** (`App\InRide\OpeningHoursParser` and `App\Engine\OpeningHours`) now coexist with an assumed divergence (§4). This is intentional but is duplicated logic to keep in step on the one shared invariant.
- **`trip_chat_message` lingers** as dead schema until the next release (§14).

### Neutral

- **The alert engine is untouched.** The in-ride path holds **no reference to `App\Enum\AlertCode`**, emits no alerts, and `AlertDocumentationTest` is unaffected. In-ride POIs and the rule-based alert engine (ADR-012) are independent surfaces.
- **Trip analysis and route-creation AI are unchanged** (§2) — still per-user BYO-token, still behind the build flag.
- **Planning is unchanged** (§4, §6) — the divergences live entirely in the in-ride reader.

## Alternatives considered

### Keep the LLM classifier + narrator (status quo)

**Rejected.** Both passes already had deterministic fallbacks, so the LLM was not load-bearing; it added latency, a token requirement and a quota dependency to answer a closed-set question and render one sentence per POI. Eight buttons and an i18n template do the same work with none of the cost.

### A dedicated `osm.shelters` table for the shelter/charger scoping

**Rejected** (§7). It requires a provisioner change and a full reprovision to backfill, versus a jsonb WHERE clause on an already-indexed table.

### GET with query-string coordinates

**Rejected** (§8). It leaks the rider's live position into logs, `Referer` and history. POST with a body keeps it out of every URL.

### A `geo:` deeplink

**Rejected** (§11). Offline routing on Android, but iOS ignores the scheme; a Google Maps HTTPS directions URL is portable across both mobile and desktop.

### Unify the two opening-hours engines

**Rejected** (§4). Their verdicts diverge on purpose — in-ride drops a certainly-closed line, planning keeps it flagged. Forcing one behaviour would break one of the two callers.

## Sources

- [ADR-040: Local-First Reference Data — Single PostGIS Source](adr-040-local-first-reference-data-postgis.md)
- [ADR-042: Optional, Multi-Provider AI on a Bring-Your-Own Token](adr-042-optional-multi-provider-ai-byo-token.md) — the in-ride mode leaves its perimeter
- [ADR-045: Conversational AI Trip-Brief Chat](adr-045-conversational-ai-trip-brief-chat.md) — its in-ride out-of-scope paragraph is superseded here
- [ADR-046: Temporary Masking of the AI Feature Behind a Build Flag](adr-046-temporary-ai-feature-flag.md) — the in-ride chat bubble is no longer a masked AI surface
- [ADR-032: Migrations and Rollback Strategy](adr-032-migrations-and-rollback-strategy.md) — the deferred `trip_chat_message` drop
- Issues [#927](https://github.com/vincentchalamon/bike-trip-planner/issues/927) / [#928](https://github.com/vincentchalamon/bike-trip-planner/issues/928) / [#930](https://github.com/vincentchalamon/bike-trip-planner/issues/930) / [#933](https://github.com/vincentchalamon/bike-trip-planner/issues/933) / [#936](https://github.com/vincentchalamon/bike-trip-planner/issues/936)

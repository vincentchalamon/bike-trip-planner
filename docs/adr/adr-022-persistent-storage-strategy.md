# ADR-022: Persistent Storage Strategy

- **Status:** Accepted
- **Date:** 2026-03-22
- **Depends on:** ADR-001 (Global Architecture), ADR-003 (Local-First Data Persistence)
- **Enables:** #56 (Persistance BDD), #76 (Auth), #50 (Liste des trips), #45 (Duplication), #52 (Verrouillage)

## Context and Problem Statement

Bike Trip Planner currently stores all trip computation state in a Redis cache pool (`cache.trip_state`) with a 30-minute TTL. This design, established in ADR-001, was appropriate for the MVP phase where the application was purely "local-first" — trip data lived in the browser's Zustand store and the backend acted as a stateless computational engine.

As the product roadmap progresses (Sprints 11–15), several features require server-side persistence beyond a short-lived cache:

- **Trip retrieval after browser close** (#56) — users must find their trip upon return
- **User authentication and trip ownership** (#76) — trips must be linked to authenticated users
- **Trip listing, duplication, and locking** (#50, #45, #52) — CRUD operations on persisted trips
- **Shared read-only trips** (#80) — publicly accessible trip snapshots
- **Offline mobile access** (#72) — syncing persisted trips to a local device cache

The Redis TTL model cannot support these use cases. A durable storage layer is required.

### Data Model Characteristics

Before evaluating storage engines, it is important to characterize the data:

| Aspect | Observation |
|--------|-------------|
| **Volume** | Small. A trip has ≤20 stages; each stage carries ~1.5K geometry points plus nested weather/alerts/POIs/accommodations. A fully computed trip is ~50–200 KB JSON. |
| **Shape** | Document-like. A Trip is the aggregate root containing a `list<Stage>`, each with deeply nested sub-objects (weather forecast, alerts, POIs, accommodations). |
| **Write pattern** | Async: 5 Messenger workers write computed results concurrently for different stages of the same trip. |
| **Read pattern** | Whole-trip reads dominate (load a trip → display all stages). Per-stage reads occur for GPX/FIT export. |
| **Relationships** | Currently none. Sprint 12 introduces `User → Trip` ownership. Sprint 14 adds shared-trip tokens. Classical relational patterns. |
| **Querying** | Simple key-based lookups today. Future: list trips by user, filter by date, paginate. No full-text search, no geospatial queries on trip data itself (Overpass/Valhalla handle geospatial). |

---

## Decision Drivers

- **Durability** — Trip data must survive indefinitely, not expire after 30 minutes.
- **Ecosystem fit** — Must integrate cleanly with Symfony 8, API Platform 4.3, and the existing State Provider/Processor pattern.
- **Operational simplicity** — Minimal additional infrastructure for a single-developer project.
- **Future-proofing** — Must support relational patterns (User → Trip) introduced in Sprint 12.
- **Performance** — Concurrent writes from async workers must not degrade under normal load.

---

## Considered Options

### Option A: MongoDB + Doctrine ODM

Use MongoDB as the primary store with Doctrine ODM for object mapping.

**Advantages:**

- The Trip→Stages→Weather/Alerts/POIs data model is naturally document-shaped — a Trip document containing embedded Stage sub-documents maps directly to the current DTO structure.
- No schema migrations required when adding new computed fields to stages.
- API Platform provides native MongoDB ODM support (automatic providers/processors).

**Disadvantages:**

- **Overkill for the data volume.** MongoDB's strengths (horizontal sharding, massive throughput, flexible schema at scale) are irrelevant for an application where a fully computed trip is under 200 KB and the expected user count is in the hundreds, not millions.
- **Heavier Docker footprint.** The official MongoDB image consumes ~400 MB RAM at idle vs. ~50 MB for PostgreSQL Alpine.
- **Weaker relational support.** Sprint 12 introduces `User → Trip` ownership, Sprint 14 adds shared-trip tokens with revocation. These are classic relational patterns. MongoDB's `$lookup` aggregation is significantly more complex and less performant than a SQL JOIN for these use cases.
- **ACID transactions are available but not the default.** Multi-document transactions (needed when a worker updates multiple stages atomically) require explicit session management, adding complexity.
- **Ecosystem friction.** PHPStan extensions, Foundry factory support, and Symfony Maker recipes are all primarily designed for Doctrine ORM, not ODM.

### Option B: PostgreSQL + Pomm Project (no ORM)

Use PostgreSQL as the storage engine but bypass Doctrine ORM entirely, using Pomm Project for direct SQL access with minimal abstraction.

**Advantages:**

- Direct access to PostgreSQL features (JSONB operators, CTEs, window functions) without ORM translation overhead.
- No Unit of Work, no proxy objects, no lazy-loading surprises — queries do exactly what you write.
- Since the project already uses custom State Providers/Processors (not Doctrine auto-generated ones), API Platform compatibility is not a concern.

**Disadvantages:**

- **Pomm is abandoned.** The last commit on `pomm-project/foundation` dates from 2020. There is no support for PHP 8.1+, let alone PHP 8.5 or Symfony 8. The project is effectively dead.
- **No migration tooling.** Schema changes would require hand-written SQL files with no diffing or version tracking.
- **No ecosystem.** No Foundry factory integration, no PHPStan extensions, no Symfony Flex recipes, no Maker bundle support.
- **Maintenance burden.** Every query, hydration, and persistence operation would need to be hand-written and maintained, including type casting, null handling, and JSON serialization/deserialization.

The underlying philosophy (thin SQL layer, no ORM magic) has merit, but Pomm is not a viable project in 2026. The closest living alternative would be using `doctrine/dbal` alone (without ORM), but this sacrifices migration tooling integration and entity hydration for no measurable performance gain at this data scale.

### Option C: PostgreSQL + Doctrine ORM with JSONB strategy (chosen)

Use PostgreSQL as the storage engine with Doctrine ORM for entity mapping, but store deeply nested computed data (weather, alerts, POIs, accommodations) as JSONB columns rather than normalized relational tables.

**Advantages:**

- **Hybrid approach.** Relational structure for Trip and Stage entities (enabling JOINs, foreign keys, indexes for Sprint 12+ features), combined with JSONB for computed sub-objects that are always read/written atomically.
- **Full Symfony ecosystem.** Doctrine Migrations for schema versioning, Zenstruck Foundry for test factories and dev fixtures, PHPStan Doctrine extensions, Symfony Maker recipes — all work out of the box.
- **Minimal ORM overhead.** The project already uses custom State Providers/Processors; Doctrine is used purely as a hydration/persistence mapper, not as a framework. No auto-generated providers, no lazy loading (explicit `JOIN FETCH`), no lifecycle listeners beyond `PreUpdate` for timestamps.
- **PostgreSQL JSONB** provides the document-model flexibility of MongoDB for nested computed data, with the added benefit of indexable JSON paths if needed later.
- **Lightweight Docker footprint.** `postgres:18-alpine` uses ~50 MB RAM at idle.
- **Future-proof.** `User → Trip` ownership (Sprint 12), shared-trip tokens (Sprint 14), and trip listing with pagination (Sprint 13) are natural relational operations.

**Disadvantages:**

- Doctrine ORM adds a dependency layer. However, at this data scale (hundreds of trips, not millions), the Unit of Work overhead is negligible.
- JSONB columns sacrifice strict database-level schema validation for computed sub-objects. This is mitigated by the fact that these objects are always serialized/deserialized through typed PHP DTOs.

---

## Decision Outcome

**Chosen: Option C — PostgreSQL 18 + Doctrine ORM with JSONB strategy.**

### Architecture

```text
┌─────────────────────────────────────────────────────────────┐
│                    API Platform Layer                        │
│              (Custom State Providers/Processors)             │
└──────────────────────┬──────────────────────────────────────┘
                       │
         ┌─────────────┴─────────────┐
         │                           │
    Doctrine ORM               Redis Cache
    (persistent)               (transient)
         │                           │
    ┌────┴────┐              ┌───────┴───────┐
    │ Trip    │              │ Raw points    │
    │ Stage   │              │ Decimated pts │
    │ (User)  │              │ Tracks data   │
    └────┬────┘              │ Computation   │
         │                   │   tracking    │
    PostgreSQL 18            │ Generation    │
                             │   tracking    │
                             └───────┬───────┘
                                  Redis 8
```

**What moves to PostgreSQL:**

- Trip configuration (source URL, dates, pacing parameters, locale, title)
- Stage data (geometry, distance, elevation, labels, rest-day flag)
- Stage computed data (weather, alerts, POIs, accommodations) as JSONB columns
- Future: User entity, shared-trip tokens

**What stays in Redis:**

- Raw and decimated route points (computation artifacts, re-fetchable)
- Multi-track data (Komoot Collection intermediary)
- Computation status tracking (transient lifecycle: pending → running → done)
- Generation counter (stale-message detection for Messenger workers)
- Messenger transport (async job queue)
- External API caches (OSM 24h, weather 3h, routing 24h, DataTourisme 24h)

### Entity Design

Two Doctrine entities, intentionally minimal:

| Entity | Key columns | JSONB columns |
|--------|-------------|---------------|
| `Trip` | id (UUID v7), source_url, title, start_date, end_date, fatigue_factor, elevation_penalty, ebike_mode, departure_hour, max_distance_per_day, average_speed, source_type, locale, created_at, updated_at | enabled_accommodation_types |
| `Stage` | id (UUID v7), trip_id (FK), position, day_number, distance, elevation, elevation_loss, start_lat/lon/ele, end_lat/lon/ele, label, is_rest_day | geometry, weather, alerts, pois, accommodations, selected_accommodation |

Entities are **separate from ApiResource DTOs**. The existing DTOs (`Trip`, `TripRequest`, `Stage`, `StageRequest`, `StageResponse`) remain the API contract. State Providers map Entity → DTO; State Processors map DTO → Entity.

### Migration Strategy

The migration is split into 7 incremental PRs to minimize review complexity:

1. **Entities + migrations** — Doctrine setup, PostgreSQL Docker service, Entity classes, initial migration
2. **Repositories** — Doctrine-backed `TripRequestRepositoryInterface` implementation replacing Redis
3. **State Providers** — Updated to read from Doctrine instead of Redis cache
4. **State Processors** — Updated to persist via Doctrine EntityManager
5. **Functional tests** — Test database setup, updated assertions
6. **Foundry factories + dev fixtures** — Zenstruck Foundry factories, seedable dev data
7. **CLAUDE.md update** — Revise the architecture description to reflect the new PostgreSQL-backed persistence layer

Redis remains fully operational throughout the migration for Messenger transport, computation tracking, and external API caching.

---

## Consequences

### Positive

- Trips persist indefinitely — closing the browser no longer loses data.
- Clean relational foundation for User authentication (Sprint 12) and trip sharing (Sprint 14).
- JSONB columns avoid the complexity of 6+ normalized tables for computed sub-objects while retaining query capability via PostgreSQL JSON operators.
- Full Symfony/Doctrine ecosystem available (migrations, Foundry, PHPStan, Maker).

### Negative

- Adds PostgreSQL as an infrastructure dependency (~50 MB RAM Docker overhead).
- Doctrine ORM is an additional abstraction layer, though used minimally (no auto-providers, no lazy loading).
- JSONB columns for computed data sacrifice database-level schema validation (mitigated by typed PHP DTOs).

### Neutral

- Redis role changes from "primary trip store" to "transient computation cache + Messenger transport". Its operational importance remains unchanged.
- The `TripRequestRepositoryInterface` contract is preserved — only the implementation changes from cache-backed to Doctrine-backed.

---

## Sources

- [PostgreSQL JSONB Documentation](https://www.postgresql.org/docs/18/datatype-json.html)
- [Doctrine ORM 3.x Documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/3.4/index.html)
- [Zenstruck Foundry](https://github.com/zenstruck/foundry)
- [Pomm Project (archived)](https://github.com/pomm-project/Foundation) — last activity 2020

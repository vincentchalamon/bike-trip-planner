# Audit — offline locality labels

Measurements requested by [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880)
(sprint 48). They arbitrate two choices: the source of locality labels (nearest `place=*`
nodes **versus** administrative polygons), and the fix for the coverage defect
suspected in the issue.

**Short answer.** The defect is confirmed: `osm.admin_boundaries` is **empty** on the local
dataset and `osm.coverage` contains a single row with a `NULL` geometry. The `place=*` option is
ruled out by the measurement: it names the right municipality in only **72.1%** of cases versus
**99.5%** for municipal polygons, for a cost difference that stays within the noise of a
multi-minute import (+7.0 MB of filtered PBF, +54 s of `osm2pgsql`).

## Measured dataset

| | |
|---|---|
| Provisioned regions | `nord-pas-de-calais` + `rhone-alpes` |
| Merged PBF (`default.osm.pbf`) | 767,068,802 bytes |
| Reference import | `osm.metadata.refreshed_at` = `2026-08-04 13:09:44+00` |
| Quality check points | 3,000 rows of `osm.accommodations` (`TABLESAMPLE SYSTEM (20)` sample) |

## 1. The coverage defect is confirmed

```text
$ psql -c "SELECT admin_level, count(*) FROM osm.admin_boundaries GROUP BY 1 ORDER BY 1;"
 admin_level | count
-------------+-------
(0 rows)

$ psql -c "SELECT count(*) FROM osm.admin_boundaries;"
 total_boundaries
------------------
                0

$ psql -c "SELECT ST_IsEmpty(geom) AS empty, geom IS NULL AS isnull FROM osm.coverage;"
 empty | isnull
-------+--------
       | t
```

Cause: Geofabrik regional extracts are **clipped**. The country's boundary relation
therefore has missing ways, `as_multipolygon()` returns `nil` and the row is skipped. The
`ST_Union(geom) WHERE admin_level = 2` in `PostgisImporter::buildDerived()` then runs
on zero rows and produces a row with a `NULL` geometry.

Consequences observed in the read code:

- `App\Osm\CoverageRepository` tests `geom IS NOT NULL AND NOT ST_Covers(...)`, so no
  trip is ever flagged as out of zone — the check is **disabled**, not a false positive. The issue
  assumed the opposite.
- `AdminBoundaryRepository::findCountryAt()` resolves **no** country, so
  `CheckBorderCrossingHandler` never emits anything.

Reconstruction rate per level, measured by comparing the number of relations present in
the PBF with the number of polygons actually imported:

| `admin_level` | relations in the PBF | polygons imported | comment |
|---|---|---|---|
| 2 (country) | 12 | 0 | never complete on a regional extract |
| 4 (region) | 23 | 0 | same |
| 6 (département) | 64 | 11 | exactly the départements fully contained |
| 8 (municipality) | 4,795 | 4,308 | 89.8%; the missing ones are on the edge of the extract |

Hence the two fixes: import levels 2, 4, 6 and 8, and build `osm.coverage`
as the union of **all** imported levels. Since the levels nest, a département that is present
fills the holes left by its edge municipalities.

## 2. Cost: `place=*` nodes versus administrative polygons

Marginal cost on the merged PBF (`osmium tags-filter`):

| Filter added | Output size | Objects |
|---|---|---|
| `n/place=city,town,village,hamlet` | 621,750 B | 26,169 nodes |
| `r/admin_level=2,4,6,8` | 9,297,249 B | 5,077 relations, 18,578 ways, 1,270,394 nodes |

Full chain, run twice on the same source PBF (osmium + `osm2pgsql --create
--slim --drop`, 800 MB cache, PostgreSQL 18 / PostGIS 3.6 limited to 1 GB):

| | baseline (`r/admin_level=2`) | chosen (`r/admin_level=2,4,6,8`) | difference |
|---|---|---|---|
| `osmium tags-filter` | 11 s | 12 s | +1 s |
| Filtered PBF | 184,564,517 B | 191,586,757 B | +7,022,240 B (+3.8%) |
| `osm2pgsql` | 106 s | 160 s | +54 s (+51%) |
| Rows in `admin_boundaries` | 0 | 4,319 | +4,319 |
| Coverage union | 0 s (`NULL` geometry) | 11 s | +11 s |
| Resulting coverage | none | 92,887 points, 57,991 km² | — |

The real overhead is therefore **+3.8% of filtered PBF** and **+65 s** on an import that took
106: significant in relative terms on the `osm2pgsql` step, negligible compared with the full
provisioning cycle (extract downloads included, ~10 min). It is not "trivial", but
it is a price worth paying for a repaired coverage defect.

## 3. Quality: the nearest-node lookup is wrong one time in four

Protocol: for each of the 3,000 real points, compare the name of the **containing** municipality
(`ST_Covers` on the `admin_level = 8` polygon) with the name of the **nearest** `place=*` node
(`ORDER BY geom <-> point`), with and without hamlets.

| Measure | Value |
|---|---|
| Points with a containing municipality | 2,985 / 3,000 (**99.5%**) |
| Nearest `city,town,village` = containing municipality | 2,152 / 2,985 (**72.1%**) |
| Nearest `city,town,village,hamlet` = containing municipality | 1,309 / 2,985 (**43.9%**) |
| Distance to the nearest `city,town,village` | median 1,025 m, p90 2,792 m, max 10,284 m |
| Distance to the nearest, hamlets included | median 539 m, p90 1,709 m |

The 28% of mismatches are not acceptable equivalents; they are the errors a
user spots immediately:

```text
 containing municipality   |   nearest place=*        | distance
---------------------------+--------------------------+----------
 Saint-Étienne             | Saint-Priest-en-Jarez    |   861 m
 Bourg-Saint-Maurice       | Arc 1600                 |  1007 m
 La Plagne-Tarentaise      | Mâcot-la-Plagne          |  4537 m
 Saint-Martin-d'Uriage     | Chamrousse               |  2958 m
 Crolles                   | Bernin                   |  1034 m
 Corenc                    | La Tronche               |   775 m
```

Including hamlets makes it worse: the nearest point becomes a locality name nobody
recognizes (only 43.9% agreement).

**Decision.** Municipal polygons. The label is correct by construction (containment, not
proximity), available for 99.5% of points, and the `osm.admin_boundaries` table already exists —
no migration, no extra table. The `place=*` table is **not** imported: it
would only provide a fallback for the 0.5% of points on the edge of an extract, at the cost of a second
source of truth for the same label.

## 4. Locality resolution, without network

`AdminBoundaryRepository::findLocalityAt()` takes the **finest** polygon covering the point
(`admin_level >= 7`, `ORDER BY admin_level DESC`), with the usual name fallback chain
`name:<locale>` → `name:en` → `name`. Outside the provisioned zone, it returns `null` and the stage
keeps displaying its coordinates.

`ResolveStageLabelsHandler` consumes this method: no more Nominatim calls, hence no more
external dependency on the computation path and no 1 request/second cap. `App\Geo\ReverseGeocoder`
had no other caller left and was removed; the Nominatim client is still used by
`App\Geo\Geocoder` and `App\Controller\GeocodeController` for interactive search.

As a bonus, `findCountryAt()` / `findCountryCodeAt()` fall back on the `ISO3166-2` of a
covering département or region (`FR-59` → `FR`, localized via ICU) when no level-2
polygon could be built. Border-crossing detection and the
multi-country calendar therefore stop being silent on a regional extract.

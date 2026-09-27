# Audit — unnamed OSM accommodations, by category

Measurement spike requested by [#878](https://github.com/vincentchalamon/bike-trip-planner/issues/878)
(sprint 47). It arbitrates the completeness gate and the name resolver of sprint 49
([#884](https://github.com/vincentchalamon/bike-trip-planner/issues/884)).

**Question asked.** The "name resolved or entry excluded" decision rests on an unmeasured
premise: we do not know how many OSM accommodations have no name, in which categories, and
how many would be recovered by the fallback chain under consideration (`name:fr`, `official_name`,
`alt_name`, `operator`, `brand`).

**Short answer.** The fallback chain recovers almost nothing (4.3% of unnamed entries) and, on
`shelter`, mostly produces harmful labels ("JCDecaux"). `wilderness_hut`
is not affected: 6.3% unnamed, like the commercial categories. The problem is
entirely concentrated on `shelter`, where the name is a **poor discriminator**: excluding it
removes 63% of the shelters actually useful to a bikepacker while keeping 2,516 named
bus shelters. Recommendation: a completeness constraint on every category **except
`shelter`**, and filtering shelters on `shelter_type` rather than on the name.

## Measured dataset

| | |
|---|---|
| Provisioned regions | `nord-pas-de-calais` + `rhone-alpes` |
| Import | 2026-08-04 (`osm.metadata.refreshed_at` = `2026-08-04 13:09:44+00`) |
| `osm.accommodations` | 16,886 rows |
| `tourism.accommodations` | 125,966 rows (DataTourisme import of 22/07, untouched) |

Rhône-Alpes was added to the selection for this measurement: Nord-Pas-de-Calais alone
contains **no** `wilderness_hut` and a single `alpine_hut`, precisely the categories
the question is about.

Two caveats to keep in mind before generalizing:

- **Two regions, not France.** The proportions are measured on 16,886 rows. The
  orders of magnitude are clear (63% versus 6%), but an exact figure for the whole of France
  would require a full provisioning.
- **Provisioner image of 22/06.** It still maps `tourism=apartment` to the
  `apartment` category; the current code maps it to `rental` ([#906](https://github.com/vincentchalamon/bike-trip-planner/pull/906)).
  Read `apartment` as `rental` in the tables: the correspondence is one to one. The diff
  between the image's `tier1.lua` and the one on `main` is limited to this rename and to
  widening the `website` tag; neither the row selection nor the `name` /
  `tags` columns change, so the measurement remains faithful.

The DataTourisme step was disabled during this provisioning (`DATATOURISME_FLUX_ID` and
`DATATOURISME_APP_KEY` emptied) so as not to touch the 125,966 rows already in place. Along
the way, the premise cited by the issue is confirmed on the current dataset: **0 empty names out of
125,966** DataTourisme rows. The problem is indeed exclusively OSM.

## 1. Volume and proportion, by category

The issue's query, run as-is:

| category | total | unnamed | % unnamed | recoverable |
|---|---:|---:|---:|---:|
| `shelter` | 8,062 | 5,123 | 63.5% | 210 |
| `hotel` | 2,706 | 70 | 2.6% | 0 |
| `chalet` | 1,423 | 253 | 17.8% | 26 |
| `camp_site` | 1,405 | 64 | 4.6% | 2 |
| `guest_house` | 1,386 | 90 | 6.5% | 3 |
| `apartment` (→ `rental`) | 1,069 | 114 | 10.7% | 7 |
| `wilderness_hut` | 316 | 20 | 6.3% | 0 |
| `alpine_hut` | 290 | 8 | 2.8% | 0 |
| `hostel` | 218 | 10 | 4.6% | 0 |
| `motel` | 11 | 2 | 18.2% | 0 |
| **Total** | **16,886** | **5,754** | **34.1%** | **248** |

Excluding `shelter`: 8,824 rows, 631 unnamed (**7.2%**), 38 recoverable.

The one-third of unnamed entries is therefore an aggregation artifact: **89% of unnamed entries
are `shelter`**. The bookable categories range between 2.6% and 17.8%.

Breakdown by region, for the sensitive categories:

| region | category | total | unnamed |
|---|---|---:|---:|
| nord-pas-de-calais | `shelter` | 896 | 862 (96.2%) |
| nord-pas-de-calais | `chalet` | 194 | 103 |
| nord-pas-de-calais | `camp_site` | 326 | 10 |
| nord-pas-de-calais | `alpine_hut` | 1 | 1 |
| rhone-alpes | `shelter` | 7,166 | 4,261 (59.5%) |
| rhone-alpes | `chalet` | 1,229 | 150 |
| rhone-alpes | `camp_site` | 1,079 | 54 |
| rhone-alpes | `wilderness_hut` | 316 | 20 |
| rhone-alpes | `alpine_hut` | 289 | 7 |

The 96% of unnamed shelters in Nord-Pas-de-Calais foreshadow what follows: in a region without
relief, `amenity=shelter` almost exclusively denotes street furniture.

## 2. What the fallback chain would actually recover

Keys available on the 5,754 unnamed entries:

| category | unnamed | `name:fr` | `official_name` | `alt_name` | `operator` | `brand` |
|---|---:|---:|---:|---:|---:|---:|
| `shelter` | 5,123 | 0 | 0 | 11 | 199 | 0 |
| `chalet` | 253 | 0 | 0 | 0 | 25 | 1 |
| `apartment` (→ `rental`) | 114 | 0 | 0 | 0 | 7 | 0 |
| `guest_house` | 90 | 0 | 0 | 0 | 3 | 0 |
| `hotel` | 70 | 0 | 0 | 0 | 0 | 0 |
| `camp_site` | 64 | 0 | 0 | 0 | 2 | 0 |
| `wilderness_hut` | 20 | 0 | 0 | 0 | 0 | 0 |
| `hostel` | 10 | 0 | 0 | 0 | 0 | 0 |
| `alpine_hut` | 8 | 0 | 0 | 0 | 0 | 0 |
| `motel` | 2 | 0 | 0 | 0 | 0 | 0 |

Three of the chain's five keys are empty or anecdotal: `name:fr` **0 occurrences**,
`official_name` **0**, `alt_name` **11** — and those 11 all carry the same value,
"Salle hors-sac" (a day-use room for hikers), which is a category, not a name. `brand` has **1** occurrence
("Gîtes de France"). All the recovery therefore rests on `operator`: 236 rows, i.e.
**4.1% of unnamed entries**.

### Actual usefulness of the `operator` values

Sample of the most frequent values on unnamed entries (the 40 most
frequent values, counts on the right):

| category | operator | n | useful label? |
|---|---|---:|---|
| `shelter` | JCDecaux | 87 | no — billboard company, the shelter is a bus shelter |
| `shelter` | STAS | 33 | no — Saint-Étienne bus network |
| `shelter` | Transdev | 31 | no — transport operator |
| `shelter` | Transdev Saint-Étienne | 12 | no — transport operator |
| `shelter` | S.N.C.F. / SNCF | 13 | no — transport operator |
| `shelter` | Keolis / TCL / Sytral / TAC / Stas | 8 | no — transport operators |
| `shelter` | Région Auvergne-Rhône-Alpes | 3 | marginal — names the owner, not the place |
| `shelter` | Commune de Jongieux, commune de Maisoncelle | 2 | yes — "Abri communal, Jongieux" is actionable |
| `shelter` | Département de l'Isère, CD62, Grenoble Alpes Métropole | 3 | marginal — same remark |
| `shelter` | Privé | 1 | no — "private" is not a name |
| `shelter` | Sogedo, Ondea, Arc Vezerontin, Institution Sainte-Marie | 5 | no — unrelated to accommodation |
| `chalet` | Huttopia | 10 | yes — identifiable brand |
| `chalet` | Camping la Digue | 6 | yes — names the place |
| `chalet` | Claire et Gilles Belanger, Martine et Gaby Jay | 4 | yes — common practice for a gîte |
| `chalet` | Commune de Sonthonnax-la-Montagne | 1 | yes |
| `chalet` | OVO Network, Immo Select, À Petits Pas, Wam Park | 4 | yes — brands |
| `apartment` (→ `rental`) | Pierre et Vacances, Goélia, Dormio Resort, Gite de France | 6 | yes — brands |
| `guest_house` | CléVacances, Gite les chamois, Paclaz | 3 | yes |
| `camp_site` | Camping du Lac du Sautet | 2 | yes |

The verdict reads by category, not globally:

- **On `shelter`, `operator` is harmful.** **184 of the 199 values** are transport operators
  or billboard companies. Offering "JCDecaux" as accommodation to a bikepacker is worse than
  offering nothing: it is a bus shelter presented as a bivouac shelter. The remaining 15
  values can be enumerated: "Région Auvergne-Rhône-Alpes" (3), "Institution
  Sainte-Marie" (2), then one occurrence each of "Arc Vezerontin", "CD62",
  "Commune de Jongieux", "Département de l'Isère", "Grenoble Alpes Métropole",
  "Ondea", "Privé", "Sogedo", "commune de Maisoncelle", "École primaire privée
  Saint-Joseph". **Two** produce a usable label (the two municipalities); five
  name an owning public authority rather than the place; the rest (water utility, school,
  "Privé") has nothing to do with a shelter.

    > The first count gave 175: the pattern used
    > (`~* 'jcdecaux|transdev|keolis|sncf|stas|tcl|sytral|tac|cars|bus|mobilit'`) misses the
    > dotted spelling `S.N.C.F.`, present 9 times. A punctuation-insensitive pattern
    > (`regexp_replace(operator, '[^a-zA-Z]', '', 'g')`) gives 184, which matches the
    > sample above exactly. The direction of the conclusion does not change; it is reinforced.

- **Outside `shelter`, `operator` is useful but negligible in volume.** The 38 values
  concerned are almost all real labels (brands, campsites, owners'
  names). But 38 rows out of 8,824 do not justify a five-key fallback chain
  three of whose keys are empty.

## 3. `shelter`: the name is a poor discriminator

This is the decisive result. `amenity=shelter` mixes unrelated objects. Classifying
the 8,062 shelters by `shelter_type`:

| class | `shelter_type` | named | unnamed |
|---|---|---:|---:|
| **noise** | `public_transport`, `carport`, `gazebo`, `sun_shelter`, `umbrella`, `pergola`, `shopping_cart`, `changing_rooms`, `animal_shelter`, `fuel_station`, `market`, `wildlife_hide`, … | 2,523 | 3,606 |
| **relevant** | `weather_shelter`, `lean_to`, `picnic_shelter`, `basic_hut`, `field_shelter`, `rock_shelter`, `roof`, `basic` | 250 | 429 |
| **undetermined** | tag absent | 166 | 1,088 |

On its own, `shelter_type=public_transport` accounts for 6,010 rows, i.e. 75% of the category.

A gate on the name therefore sorts exactly the opposite way from the intent:

- it **removes 63%** of the relevant shelters (429 out of 679);
- it **keeps 2,516 named bus shelters** (`shelter_type=public_transport` carrying a stop
  name), which continue to pollute the layer.

The 1,088 shelters with neither a name nor a `shelter_type` are not a hidden reserve: 630 carry
a `building` tag or a cadastral `source` (`cadastre-dgi-fr`), i.e. building footprints
imported in bulk and tagged `amenity=shelter` with no other information. Only 7
carry a public-transport hint, 69 a `bench`. Of the 1,517 shelters in the
relevant + undetermined residue, only 24 carry a `description` and 24 a bivouac attribute
(`fireplace`, `mattress`, `capacity`), 14 a closed `access`.

## 4. `wilderness_hut`: hypothesis not confirmed

The issue suspected a concentration on `shelter` **and** `wilderness_hut`. The measurement
only confirms the first:

- `wilderness_hut`: 20 unnamed out of 316, i.e. **6.3%** — the same order as `guest_house`
  (6.5%) or `camp_site` (4.6%);
- `alpine_hut`: 8 out of 290, **2.8%**.

The detail of the 20 unnamed `wilderness_hut` confirms there is nothing to save in volume:
5 carry `access=private`, 8 boil down to `tourism=wilderness_hut` alone or with
a cadastral footprint, none has an `operator`, `brand`, `name:fr` or
`official_name`. Only two would deserve to be kept for their `description`
("Des bat-flancs pour 4 personnes…", "Salle hors sac").

An exemption for `wilderness_hut` would therefore cost an exception in the schema
for at best 15 useful rows out of 316.

## 5. What the code already does

Two existing behaviors frame the decision:

- `api/src/AccommodationSource/OsmAccommodationSource.php:39-45` **already drops** every
  unnamed entry, at read time. The layer of unnamed shelters is therefore **already
  empty in production**: the sprint 49 gate would merely move to import time an
  exclusion that happens at read time. The cost measured here is a cost already paid, not a cost
  to come.
- `api/src/InRide/InRideAssistant.php:147-163` takes the opposite position for in-ride:
  unnamed food and bike-repair places are dropped, **water and shelters are kept** with a
  generic label ("Point d'eau", "Abri"), on the grounds that the coordinates are enough to
  act on. The two paths currently diverge on the same data.

## Recommendation

**1. Completeness constraint on `name`, for every accommodation category except
`shelter`.** Measured cost: 631 rows out of 8,824 (7.2%), part of which are cadastral
footprints with no information. `wilderness_hut` and `alpine_hut` fall under the constraint
without exemption: 6.3% and 2.8%, no recovery possible, and 5 of the 20 huts concerned are
`access=private` on top of that.

**2. Exempt `shelter` alone, with a generic label on the read side.** On this category
the name does not discriminate quality: the constraint would remove 1,517 shelters (429 of them
explicitly relevant) and leave 2,516 named bus shelters. The right sort key is
`shelter_type`, not `name`. Consequently:

- filter shelters at import against an allowlist of `shelter_type`
  (`weather_shelter`, `lean_to`, `picnic_shelter`, `basic_hut`, `field_shelter`,
  `rock_shelter`, `roof`, `basic`, plus tag absent) and drop the urban noise
  (`public_transport` first): 6,129 fewer noise rows, 2,523 of which are currently
  served because they have a name;
- serve unnamed shelters with the generic label "Abri", as
  `InRideAssistant` already does, instead of dropping them in `OsmAccommodationSource`.

**3. Abandon the five-key fallback chain.** Measurement: 248 recoveries out of 5,754 unnamed
entries (4.3%), 184 of which are transport operator names on bus shelters. `name:fr` and
`official_name` have **no** occurrence, `alt_name` 11 (all "Salle hors-sac"),
`brand` 1. If a fallback is kept, reduce it to `operator` then `brand` **outside `shelter`**,
for a gain of 38 rows: to be decided in light of the resolver's cost, not its
supposed yield.

**4. Do not reopen the "separate *spots* category" option.** It is justified neither for
`wilderness_hut` (6.3% unnamed, no significant residue), nor for `shelter`, whose
residue is handled by the exemption above. Creating a category outside
`TripRequest::ALL_ACCOMMODATION_TYPES` would impose a new vocabulary on the front-end filter and
the DTOs for zero gain compared with exempting a single category.

## Reproducing the measurement

```bash
# Region selection, then provisioning (the DataTourisme step is neutralized)
printf '{"slugs":["nord-pas-de-calais","rhone-alpes"]}' > .docker/osm/data/regions.json
docker compose --profile provisioning run --rm -T \
  -e DATATOURISME_FLUX_ID= -e DATATOURISME_APP_KEY= provisioner --no-interaction

# Count by category
docker compose exec -T database psql -U app -d bike_trip_planner -c "
SELECT category, count(*) AS total,
       count(*) FILTER (WHERE name IS NULL OR btrim(name) = '') AS unnamed,
       count(*) FILTER (WHERE (name IS NULL OR btrim(name) = '')
                          AND (tags ? 'operator' OR tags ? 'brand' OR tags ? 'official_name'
                            OR tags ? 'alt_name' OR tags ? 'name:fr')) AS recoverable
FROM osm.accommodations GROUP BY 1 ORDER BY 2 DESC;"

# Shelters classified by shelter_type
docker compose exec -T database psql -U app -d bike_trip_planner -c "
SELECT coalesce(tags->>'shelter_type', '(absent)') AS shelter_type, count(*) AS total,
       count(*) FILTER (WHERE name IS NULL OR btrim(name) = '') AS unnamed
FROM osm.accommodations WHERE category = 'shelter' GROUP BY 1 ORDER BY 2 DESC;"
```

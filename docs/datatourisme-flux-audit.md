# DataTourisme flux field audit

Read-only measurement of the DataTourisme national flux we already download, run for
[issue #879](https://github.com/vincentchalamon/bike-trip-planner/issues/879). It answers three questions with
numbers rather than assumptions:

1. Does the flux carry the **Accueil Vélo** label?
2. Does it carry a **minimum stay** or booking granularity, the reserve that keeps the `rental` accommodation
   type opt-in?
3. Is the **flux itself under-configured**, dropping data upstream of our mapper?

Short answers: **yes**, **no**, **no**. The detail below also decides two sprint-50 source questions and
uncovers four mapping defects in [`DataTourismeMapper`](../provisioner/src/DataTourismeMapper.php).

## Corpus and method

| Property | Value |
|---|---|
| Flux | `#26308` (`flux-26308-*.zip`, from the `Content-Disposition` of the webservice URL) |
| Snapshot audited | objects dated 2026-07-18, downloaded 2026-07-22 |
| Newer generation available | 2026-08-03 20:53 (same URL, so a refresh is a download away) |
| Archive | 1.85 GiB zipped, 10.8 GB uncompressed |
| Objects | 375,748 JSON-LD files under `objects/`, plus `index.json` (375,748 entries) and `context.jsonld` |
| Vocabulary | `context.jsonld` declares 218 terms — the exhaustive list of predicates this flux can express |

Every figure below comes from a full pass over the 375,748 objects (no sampling), with the object → table
classification replicated from `DataTourismeMapper::classify()` so the buckets match what we actually import.
Percentages are shares of objects in the bucket unless the row says "occurrences" (a predicate can repeat
inside one object).

## 1. Key inventory and fill rates

### What the flux splits into

| Bucket (our mapping) | Objects | Share |
|---|---:|---:|
| `accommodations` | 124,036 | 33.0% |
| `events` | 87,861 | 23.4% |
| `food_pois` | 61,534 | 16.4% |
| `cultural_pois` | 55,550 | 14.8% |
| dropped — `Store` without a food subtype | 39,026 | 10.4% |
| dropped — `Tour` / `Product` / `Practice` and other non-place objects | 7,537 | 2.0% |
| dropped — `Accommodation` with an unmapped subtype | 204 | 0.1% |
| **imported** | **328,981** | **87.6%** |
| **dropped** | **46,767** | **12.4%** |

Accommodations by app category: `rental` 82,392 · `hotel` 13,176 · `guest_house` 12,591 · `camp_site` 9,844 ·
`hostel` 5,827 · `chalet` 123 · `wilderness_hut` 83.

### Accommodations — all 38 top-level predicates

| Predicate | Objects | Fill | Mapped today |
|---|---:|---:|---|
| `dc:identifier` | 124,036 | 100.0% | no |
| `rdfs:label` | 124,036 | 100.0% | yes (`name`) |
| `hasBeenCreatedBy` | 124,036 | 100.0% | no |
| `hasBeenPublishedBy` | 124,036 | 100.0% | no |
| `isLocatedAt` | 124,036 | 100.0% | yes (geometry, address, hours) |
| `lastUpdate` | 124,036 | 100.0% | no |
| `lastUpdateDatatourisme` | 124,036 | 100.0% | no |
| `hasContact` | 123,895 | 99.9% | yes (website, phone, email) |
| `hasDescription` | 108,836 | 87.7% | yes (`shortDescription`) |
| `offers` | 96,910 | 78.1% | partially (`price` only) |
| `hasFeature` | 82,148 | 66.2% | **no — see defect 2** |
| `creationDate` | 76,797 | 61.9% | no |
| `allowedPersons` | 75,588 | 60.9% | yes (`capacity`) |
| `hasReview` | 72,556 | 58.5% | **no — carries Accueil Vélo** |
| `rdfs:comment` | 70,835 | 57.1% | yes (description fallback) |
| `hasBookingContact` | 66,404 | 53.5% | yes (`booking_url`) |
| `hasTranslatedProperty` | 64,980 | 52.4% | no |
| `availableLanguage` | 60,865 | 49.1% | no |
| `hasRepresentation` | 54,582 | 44.0% | no |
| `hasMainRepresentation` | 50,732 | 40.9% | yes (`image_url`) |
| `isOwnedBy` | 50,164 | 40.4% | no |
| `hasTheme` | 27,722 | 22.3% | yes (`labels`) |
| `hasNeighborhood` | 22,726 | 18.3% | no |
| `hasExternalReference` | 17,116 | 13.8% | no |
| `hasAudience` | 3,602 | 2.9% | no |
| `hasClientTarget` | 2,262 | 1.8% | no |
| `reducedMobilityAccess` | 2,139 | 1.7% | no |
| `hasManagementContact` | 1,407 | 1.1% | no |
| `hasCommunicationContact` | 1,382 | 1.1% | no |
| `hasGeographicReach` | 1,340 | 1.1% | no |
| `providesCuisineOfType` | 870 | 0.7% | no |
| `takeAway` | 173 | 0.1% | no |
| `COVID19SpecialMeasures` | 48 | 0.0% | no |
| `hasAdministrativeContact` | 47 | 0.0% | no |
| `owl:sameAs` | 13 | 0.0% | yes (`wikidata`) |
| `hasFloorSize` | 13 | 0.0% | no |
| `schema:legalName` | 1 | 0.0% | no |
| `hasArchitecturalStyle` | 1 | 0.0% | no |

Nested paths worth naming (occurrences, not objects): `hasContact.foaf:homepage` 106,140 ·
`hasBookingContact.foaf:homepage` 41,524 · `hasFeature.features` 456,811 ·
`isLocatedAt.schema:openingHoursSpecification` 55,845 · `hasReview.hasReviewValue` 107,526 ·
`isLocatedAt.schema:address.schema:streetAddress` 121,772.

### The other buckets, top predicates

| Predicate | `cultural` (55,550) | `food` (61,534) | `event` (87,861) | dropped `Store` (39,026) |
|---|---:|---:|---:|---:|
| `rdfs:label`, `isLocatedAt`, `hasBeenCreatedBy`, `lastUpdate` | 100.0% | 100.0% | 100.0% | 100.0% |
| `hasContact` | 92.5% | 100.0% | 100.0% | 99.5% |
| `hasDescription` | 94.1% | 90.6% | 98.8% | 90.6% |
| `takesPlaceAt` | 0.0% | 0.0% | 100.0% | 0.0% |
| `schema:startDate` / `schema:endDate` | 0.0% | 0.0% | 100.0% | 0.0% |
| `offers` | 28.3% | 57.2% | 62.1% | 18.4% |
| `hasTheme` | 50.6% | 54.3% | 29.1% | 32.5% |
| `hasFeature` | 20.0% | 36.1% | 13.1% | 7.1% |
| `hasReview` | 11.2% | 7.2% | 0.5% | 3.7% |
| `hasBookingContact` | 28.4% | 31.5% | 36.1% | 17.0% |
| `hasMainRepresentation` | 33.3% | 23.4% | 47.6% | 18.0% |
| `owl:sameAs` | 6.9% | 0.0% | 0.0% | 0.0% |

Two structural facts hold across every bucket: **100% of objects are geolocated** and **100% carry a
`rdfs:label`**. Neither is a filter we asked for — it is how the national base is published.

## 2. Accueil Vélo: present, and currently thrown away

The label is a **review concept**, not a classification:

```json
"hasReview": [{
  "@type": ["Review"],
  "hasReviewValue": {
    "@id": "kb:LabelRating_AccueilVelo",
    "@type": ["LabelRating"],
    "rdfs:label": {"fr": ["Accueil Vélo"], "en": ["Accueil Vélo"]}
  }
}]
```

`kb:LabelRating_AccueilVelo` appears on **8,010 objects**, every one of them at
`hasReview[].hasReviewValue.@id`:

| Bucket | Objects with the label | Share of bucket |
|---|---:|---:|
| accommodations | 6,006 | 4.8% |
| dropped `Store` | 764 | 2.0% |
| `food_pois` | 667 | 1.1% |
| `cultural_pois` | 551 | 1.0% |
| other / unmapped | 22 | — |

Per accommodation category:

| Category | Labelled | Share |
|---|---:|---:|
| `hotel` | 1,706 | 12.9% |
| `camp_site` | 1,023 | 10.4% |
| `wilderness_hut` | 8 | 9.6% |
| `hostel` | 552 | 9.5% |
| `guest_house` | 890 | 7.1% |
| `rental` | 1,827 | 2.2% |
| `chalet` | 0 | 0.0% |

**Coverage is essentially complete.** The published size of the national network is roughly 7,100–8,500
labelled providers — [Ille-et-Vilaine Tourisme](https://www.ille-et-vilaine-tourisme.bzh/acteurs/nos-actualites/accueil-velo-un-nouveau-referentiel-pour-repondre-au-mieux-aux-besoins-des-cyclotouristes/)
cites more than 7,100, the [DGE](https://www.entreprises.gouv.fr/secteurs-dactivite/tourisme/le-tourisme-velo)
more than 8,000 — around two thirds of them accommodations. The flux carries 8,010 labelled objects including
6,006 accommodations. There is no measurable gap left for a dedicated source to fill, so **scraping or
licensing francevelotourisme.com is not justified**.

Only **14 objects** mention "accueil vélo" in prose without the structured concept, so a text heuristic adds
nothing.

The label is not the only bike signal, and the signals are largely **disjoint** — combining them recalls far
more than either alone:

| Signal (accommodations) | Objects | Share |
|---|---:|---:|
| Accueil Vélo label | 6,006 | 4.8% |
| Locked bike room or garage (`kb:BikeRoom`, `kb:BikeGarage`) | 5,601 | 4.5% |
| Any bike amenity (room, garage, parking, hire, loan, repair, e-bike charging) | 10,012 | 8.1% |
| Cyclist audience (`kb:Cyclists`, `kb:CycleTourist`, `kb:MountainBikers`) | 2,172 | 1.8% |
| Bike theme (`kb:Bike`, `kb:BicycleTouring`, …) | 1,738 | 1.4% |
| **Any of the above** | **15,718** | **12.7%** |

Overlap: 771 objects have both the label and a locked bike room, 5,235 have the label without one, and 4,830
have a locked bike room without the label.

The label vocabulary is rich beyond cycling — 82 distinct `kb:LabelRating_*` concepts, all in the same place
and all discarded today. The largest: `GitesDeFrance` 17,066 · `AccueilVelo` 8,010 ·
`VignoblesDecouvertes` 3,285 · `MonumentHistorique` 2,639 · `QualiteTourisme` 2,441 · `ClefVerte` 1,355 ·
`CleVacances` 1,102 · the four `TourismeHandicap*` pictograms 888–1,081 each · `AccueilMotards` 227 ·
`AccueilCompostelle` 142. Star ratings (`kb:ScaleRating_*`) sit in the same `hasReview` block.

## 3. Minimum stay: absent from the flux, and from the ontology it exposes

This is settled at the vocabulary level, not by sampling: the flux's `context.jsonld` declares **218 terms and
none of them expresses a stay length or a booking granularity**. The closest terms are `duration` /
`durationDays` (used by itineraries, never by accommodations), `appliesOnPeriod` (a validity date range for a
price), `arrivedAt`, `weekOfMonth`, `requiredMinPersonCount` and `occupancy` (68 occurrences, all `null`). A
key-name scan across all 124,036 accommodations, to depth 5, matching
`minim|stay|night|nuit|semaine|week|rental|granular|period|…`, returned only unrelated unit fields
(`hasFloorSize.schema:unitText` = "m²", `ebucore:heightUnit` = "px", `schema:dayOfWeek`).

The only exploitable proxy is `offers.schema:priceSpecification.hasPricingMode`, a controlled vocabulary
(162,389 occurrences). Rental pricing modes, in order: `kb:PerWeek` 18,151 · `kb:Overnight` 6,269 ·
`kb:Weekend` 6,184 · `kb:MidWeek` 1,659 · `kb:For3Weeks` 1,082 · `kb:Weekend2Nights` 710 ·
`kb:SubscriptionPackage` 603 · `kb:PerWeek7Nights` 373 · `kb:PerWeek6Nights` 325 · `kb:Fortnightly` 275 ·
`kb:For2Days` 221 · `kb:PerMonth` 107 · `kb:PerDay` 8.

Classifying each accommodation by the modes it publishes — night-granular (`Overnight`, `PerDay`, `Weekend*`,
`For2Days`, `HalfDayPackage`), week-or-longer only (`PerWeek*`, `Fortnightly`, `PerMonth`, `MidWeek`,
`SubscriptionPackage`), a price with no time unit at all, or no price:

| Category | Night | Night + week | Week only | Price, no unit | No price |
|---|---:|---:|---:|---:|---:|
| `rental` (82,392) | 3,221 (3.9%) | 8,277 (10.0%) | 10,651 (12.9%) | 12,941 (15.7%) | 47,302 (57.4%) |
| `hotel` (13,176) | 1,235 (9.4%) | 0 | 1 (0.0%) | 5,396 (41.0%) | 6,544 (49.7%) |
| `guest_house` (12,591) | 2,290 (18.2%) | 98 (0.8%) | 36 (0.3%) | 3,066 (24.4%) | 7,101 (56.4%) |
| `camp_site` (9,844) | 438 (4.4%) | 599 (6.1%) | 332 (3.4%) | 3,277 (33.3%) | 5,198 (52.8%) |
| `hostel` (5,827) | 706 (12.1%) | 154 (2.6%) | 317 (5.4%) | 1,673 (28.7%) | 2,977 (51.1%) |
| `wilderness_hut` (83) | 27 (32.5%) | 21 (25.3%) | 2 (2.4%) | 3 (3.6%) | 30 (36.1%) |

**Verdict for the `rental` reserve** ([#865](https://github.com/vincentchalamon/bike-trip-planner/issues/865)):
the flux does **not** let us flip the default. It supports three narrower moves:

- **Exclude** 10,651 rentals (12.9%) that price by the week or longer and never by the night — the
  Saturday-to-Saturday market the reserve was about.
- **Promote** 11,498 rentals (14.0%) that publish a night, day or weekend rate: those are demonstrably
  bookable for one stop.
- **Leave the remaining 73.1% undetermined** (15.7% price without a unit, 57.4% publish no price at all). No
  measurement in this flux can classify them.

Among the 6,006 Accueil Vélo accommodations the same blind spot holds: 2,441 publish no price and 2,409 a
price with no unit, so the label cannot stand in for night-bookability either. Only 382 rentals are both
night-priced and labelled.

## 4. Flux configuration: not the bottleneck

Measured on the archive itself:

- **All four root branches are present** — `PlaceOfInterest` 284,496, `EntertainmentAndEvent` 87,861,
  `Product` 27,595, `Tour` 7,719, `Practice` 3,315. A category-restricted flux would be missing whole
  branches; none is.
- **National geographic coverage** — 17 distinct regions and 100 distinct departments appear in the postal
  addresses. No geographic filter is in play.
- **The complete data model is served**, not a simplified one: `hasReview`, `hasFeature.features`,
  `offers.schema:priceSpecification.hasPricingMode`, `hasTranslatedProperty`, seven-language label maps and
  the full media blocks are all there. Nothing is truncated field-side.
- Terms the ontology defines but the producers never populate: `nationalAddressId` (**0 occurrences** — no BAN
  address id anywhere in the flux), `hasCompletenessScore` (**0**), `isEquippedWith` (**0**).
- Terms populated but ignored by us: `textOpeningHoursSpecification` (free-text hours: 7,240 accommodations,
  5,294 food, 2,467 events, 1,530 cultural) and `hasExternalIdentifier` (17,075 accommodations, 17,698 food).

**Recommendation: leave the flux configuration alone.** There is nothing to widen upstream for the data we
consume; every loss measured here happens in our own mapper. Two operational notes: the local snapshot is
three weeks stale (a 2026-08-03 generation is already published), and `data.gouv.fr` offers the same base as a
daily bulk export (RDF N-Triples plus simplified CSV per branch: `PLACE`, `FMA`, `PRODUCT`, `TOUR`) with no
account, which is a viable fallback if the flux ever breaks.

### What our own mapper drops, and what widening it would buy

| Dropped today | Objects | Worth importing? |
|---|---:|---|
| `Market` + `CoveredMarket` (as `Store` without a food subtype) | 8,850 | Yes — a weekly market is a resupply option, but it needs the day-of-week from `openingHours` to be useful. |
| `CyclingTour` (inside the 7,537 non-place objects) | 7,525 | Only as metadata. Measured on 400 sampled tours: `tourDistance` 60%, `positiveCumulDifference` 25%, `duration` 22%, `olo:slot` 16% — and **no track geometry**, just a single start point. They are not routes. |
| `BoutiqueOrLocalShop`, `CraftsmanShop`, `EquipmentRentalShop`, … | ~30,000 | No — no resupply or lodging value. |
| `Accommodation` with an unmapped subtype | 204 | Marginal; already counted and reported by the provisioner. |

`Pharmacy` (237) and `Fountain` (475) exist in the flux but are far better covered by OSM; they are not a
reason to widen anything.

## 5. Mapping defects found while measuring

All four live in [`DataTourismeMapper`](../provisioner/src/DataTourismeMapper.php) and are measurable, not
stylistic:

1. **`hasClassification` does not exist in this flux.** `labels()` reads it first; the term is absent from the
   218-term `context.jsonld` and has **0 occurrences** in 375,748 objects. Dead branch.
2. **`hasFeature` labels are one level deeper than the code looks.** The predicate points at a
   `FeatureSpecification` wrapper that carries no `rdfs:label`; the labels live on the nested `features[]`
   concepts (253 distinct, e.g. `kb:BikeRoom`, `kb:Wifi`). So the `labels` tag currently equals `hasTheme`
   alone — 27,722 accommodations (22.3%) — and **never contains a quality label**, contrary to the docblock
   claim that "quality labels such as Accueil Vélo are published as classifications". Reaching the nested
   concepts plus `hasReview.hasReviewValue` would raise `labels` coverage from 22.3% to 82.6% on
   accommodations (and 60.4% cultural, 66.6% food).
3. **Top-level `foaf:homepage` is never populated** (0 occurrences in every bucket). The website always comes
   from `hasContact` / `hasBookingContact`, which the code does reach — the first lookup is simply dead.
4. **`owl:sameAs` is effectively absent on accommodations**: 13 objects out of 124,036 (0.01%), versus 3,834
   cultural POIs (6.9%). The `accommodations.wikidata` column added in #872 will stay empty for DataTourisme
   rows, so any pairing logic that relies on a shared Q-ID between an OSM and a DataTourisme lodging cannot
   work in practice.

## 6. Recommendations, in order

1. **Import the label block.** Read `hasReview[].hasReviewValue` and store the `kb:` concept ids (not the
   translated labels) alongside the existing `labels` tag. That is one predicate for 8,010 Accueil Vélo
   objects plus 82 other labels and the star ratings. Fix defects 1–2 in the same change.
2. **Make Accueil Vélo a first-order ranking signal** in `App\Accommodation\CandidateRanker`, combined with
   the bike amenities (`kb:BikeRoom`, `kb:BikeGarage`, …): together they mark 15,718 accommodations (12.7%),
   and the two signals overlap on only 771.
3. **Do not add a bike-label source.** Coverage in the flux matches the national network size; a dedicated
   source would add licensing and freshness risk for no measurable recall.
4. **Keep `rental` opt-in**, but use `hasPricingMode` to exclude the 10,651 week-only rentals and to rank up
   the 11,498 night-priced ones. Say explicitly in the UI that the rest is unknown.
5. **Drop the BAN-linkage assumption for DataTourisme**: `nationalAddressId` is empty, so a BAN join has to be
   built from the postal address, not from an identifier the flux is supposed to carry.
6. **Refresh the snapshot** and consider importing markets (8,850) as resupply POIs.

## Appendix — reproducing the measurement

The audit is a streaming pass over the archive; nothing is written and no database is touched.

```bash
# Which flux, which generation, without downloading 1.85 GiB
curl -s -o /dev/null -D - -r 0-0 \
  "https://diffuseur.datatourisme.fr/webservice/${DATATOURISME_FLUX_ID}/${DATATOURISME_APP_KEY}" | grep -i content-disposition

# The vocabulary the flux can express (218 terms) — where the minimum-stay question is settled
python3 -c "import zipfile,json; print(sorted(json.loads(zipfile.ZipFile('.docker/osm/data/datatourisme/datatourisme-flux.zip').read('context.jsonld'))))"

# Accueil Vélo occurrences, straight off the archive
python3 - <<'PY'
import zipfile, json
z = zipfile.ZipFile('.docker/osm/data/datatourisme/datatourisme-flux.zip')
hits = sum(b'kb:LabelRating_AccueilVelo' in z.read(n)
           for n in z.namelist() if n.startswith('objects/') and n.endswith('.json'))
print(hits)
PY
```

The per-category and pricing-granularity tables come from the same loop with
`DataTourismeMapper::classify()` reimplemented in Python and the `kb:` concept ids collected per predicate
path; on 12 cores a full pass over the 375,748 objects takes about ten minutes.

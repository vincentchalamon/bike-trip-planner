# Accommodation types

Reference for the accommodation types the planner looks for around each stage end point,
the OpenStreetMap tags they map to, and the pricing heuristic applied to each. Candidates
come from the local OpenStreetMap index and, in France, from DataTourisme; see
[external data sources](external-data-sources.md).

## Types

| Logical type | OSM query | Pricing heuristic |
|---|---|---|
| `hotel` | `tourism=hotel` | €50–€120 |
| `guest_house` | `tourism=guest_house` | €40–€80 |
| `chalet` | `tourism=chalet` | €30–€70 |
| `hostel` | `tourism=hostel` | €20–€35 |
| `alpine_hut` | `tourism=alpine_hut` | €25–€45 |
| `camp_site` | `tourism=camp_site` | €8–€25 (€8–€15 if `backpack=yes` or `tents=yes`) |
| `wilderness_hut` | `tourism=wilderness_hut` | free / donation (€0–€10) |

Every type is enabled on a new trip; the rider can switch types off in the trip settings
("Accommodation types").

## Search

- The search runs around each stage end point, within **5 km** by default. The radius can
  be widened in 2 km steps, up to **15 km**.
- Candidates with the same name within 200 m of each other are deduplicated.
- At most **5 candidates** are kept per stage, ranked by **completeness** (website,
  description, opening hours, Wikidata Q-ID, stars, capacity, OSM tag richness) with the
  price as tiebreaker. A per-family cap reserves at least one slot for the outdoor family
  (campsite, wilderness hut) when it has candidates, so a stage never returns hotels only.
- A rider can also add an accommodation by hand (name, address, total price, URL).

## Pricing

The bracket in the table is the *unrated* estimate. It is adjusted as follows:

- a `charge` tag or a numeric `fee` replaces it with an exact price;
- `fee=no` prices the entry as free;
- a known `stars` rating lifts the bracket floor by 25% of its span per star above 2,
  capped at 75% (a 4-star hotel is estimated €85–€120, not €50–€120).

## Names and completeness at import

A bookable accommodation that arrives without a name is **not imported** (since #884),
rather than filtered out when read. The provisioner first tries to complete it, in order:

1. a geometric match against the DataTourisme flux (same category, within 50 m);
2. the Wikidata label, when the row carries a Q-ID;
3. `operator`, then `brand`, qualified by the commune resolved offline from the imported
   boundaries ("Camping municipal — Sarlat").

A per-category `CHECK` constraint refuses what is left. Two DataTourisme candidates in
range produce a **rejection**, never a pick: attributing the wrong name is worse than
attributing none. Each accepted match records its source record and distance, and
`/api/health` reports the match and ambiguity counts per run. Categories a rider can act
on from coordinates alone (water points, fords, ferries), `shelter` and generic POIs carry
no name constraint. See [ADR-049](adr/adr-049-zone-opening-and-import-time-completeness.md).

## Removed types

Three types were removed in #927:

- `shelter` (`amenity=shelter`): [the measurement](https://github.com/vincentchalamon/bike-trip-planner/blob/main/docs/audit/878-hebergements-osm-sans-nom.md)
  found 76% of it to be street furniture, bus shelters above all. It now feeds the
  in-ride "Find shelter" question only, never lodging.
- `motel`: `tourism=motel` is a North American concept, empty in the covered area.
- `rental` (holiday lets): let by the week, and neither source carries a minimum-stay field.

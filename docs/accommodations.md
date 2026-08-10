# Supported OSM accommodation tags

| Logical type | OSM query | Pricing heuristic |
|---|---|---|
| `hotel` | `tourism=hotel` | €50–€120 |
| `guest_house` | `tourism=guest_house` | €40–€80 |
| `chalet` | `tourism=chalet` | €30–€70 |
| `hostel` | `tourism=hostel` | €20–€35 |
| `alpine_hut` | `tourism=alpine_hut` | €25–€45 |
| `camp_site` | `tourism=camp_site` | €8–€25 (€8–€15 if `backpack=yes` or `tents=yes`) |
| `wilderness_hut` | `tourism=wilderness_hut` | free / donation (€0–€10) |

Every type above is enabled on a new trip. Three types were removed in #927:
`shelter` (`amenity=shelter`) because [the measurement](audit/878-hebergements-osm-sans-nom.md)
found 76% of it to be street furniture — bus shelters above all — so it now feeds
the in-ride "where can I take cover" intent only, and never lodging; `motel`
because `tourism=motel` is a north-American concept, empty in France; and `rental`
(meublé de tourisme) because that market is let by the week and neither source
carries a minimum-stay field.

Since #884, a bookable accommodation that arrives without a name is **not imported**
rather than filtered out when read. The provisioner first tries to complete it — the
a geometric match against the curated DataTourisme flux (same category, within 50 m), then
the Wikidata label when the row carries a Q-ID, then `operator`, then `brand`, the tag-based
ones qualified by the commune resolved offline from the imported boundaries ("Camping
municipal — Sarlat") — and a per-category `CHECK` refuses what is left.

The geometric match is what the runtime deduplicator structurally cannot do: it pairs
places **by name**, so the one thing it needs is the one thing missing. At import there is
no such constraint, and DataTourisme names every one of its 124 240 accommodations. Two
curated candidates in range produce a **rejection**, never a pick — attributing the wrong
name is worse than attributing none — and each accepted match records the record it came
from and its distance, for audit. The 50 m radius is a starting point; `/api/health` reports
the match and ambiguity counts per run, which is what will confirm or move it. The one exemption is `shelter`, whose
useful sorting key is `shelter_type`, not the name. Categories a rider can act on from
coordinates alone (water points, fords, ferries) and generic POIs carry no such
constraint. See [ADR-049](adr/adr-049-zone-opening-and-import-time-completeness.md).

The bracket above is the *unrated* estimate. A `charge` tag or a numeric `fee` overrides it with an exact price, `fee=no` prices the entry as free, and a known `stars` rating lifts the bracket floor by 25% of its span per star above 2, capped at 75% (a 4-star hotel is estimated €85–€120, not €50–€120).

Only a few candidates are kept per stage: they are ranked by **completeness** (website, description, opening hours, Wikidata Q-ID, stars, capacity, tag richness) with the price as tiebreaker, and a per-family cap reserves one slot for the outdoor family (camping, wilderness hut) so a stage never returns hotels only.

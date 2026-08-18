import {
  FILTERABLE_ACCOMMODATION_TYPES,
  type FilterableAccommodationType,
} from "@btp/core/constants";

// The filterable list is shared, framework-free, from @btp/core (ADR-055, #1046);
// re-exported here so the public `@/lib/accommodation-types` path stays stable.
export {
  FILTERABLE_ACCOMMODATION_TYPES,
  type FilterableAccommodationType,
};

/**
 * All supported accommodation types for filtering.
 * Mirrors the OSM tourism tags imported by the Tier-1 provisioner
 * (provisioner/osm2pgsql/tier1.lua, ACCOMMODATION_TOURISM).
 * Keep in sync with the PHP source (TripRequest::ALL_ACCOMMODATION_TYPES).
 *
 * "shelter", "motel" and "rental" were removed in #927: amenity=shelter is mostly
 * street furniture and now serves the in-ride shelter intent only, tourism=motel is
 * empty in France, and the meublé market is let by the week.
 */
export const ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "alpine_hut",
  "wilderness_hut",
  "other",
] as const;

export type AccommodationType = (typeof ACCOMMODATION_TYPES)[number];

export function isAccommodationType(value: string): value is AccommodationType {
  return (ACCOMMODATION_TYPES as readonly string[]).includes(value);
}

/**
 * Translation key (namespace `accommodation`) for a type coming from the API.
 * Derived from the contract instead of a hand-maintained map, so a new type can
 * never silently render as "Autre": adding it to ACCOMMODATION_TYPES without
 * its catalog entry fails accommodation-types.test.ts.
 */
export function accommodationTypeLabelKey(
  type: string,
): `type_${AccommodationType}` {
  return isAccommodationType(type) ? `type_${type}` : "type_other";
}

// Compile-time guard preserved from the pre-extraction `satisfies` clause: the
// shared filterable list (now in @btp/core) must stay a subset of the full
// contract type list. A stray value would make this type resolve to `never`.
type _AssertFilterableSubset =
  FilterableAccommodationType extends AccommodationType ? true : never;
const _assertFilterableSubset: _AssertFilterableSubset = true;
void _assertFilterableSubset;

/**
 * Types enabled on a new trip. Mirrors TripRequest::ALL_ACCOMMODATION_TYPES:
 * every filterable type is on by default, there is no opt-in type any more.
 */
export const DEFAULT_ACCOMMODATION_TYPES =
  FILTERABLE_ACCOMMODATION_TYPES satisfies ReadonlyArray<FilterableAccommodationType>;

/**
 * All supported accommodation types for filtering.
 * Mirrors the OSM tourism tags imported by the Tier-1 provisioner
 * (provisioner/osm2pgsql/tier1.lua, ACCOMMODATION_TOURISM).
 * Keep in sync with the PHP source (TripRequest::ALL_ACCOMMODATION_TYPES).
 */
export const ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "motel",
  "alpine_hut",
  "wilderness_hut",
  "shelter",
  "rental",
  "other",
] as const;

export type AccommodationType = (typeof ACCOMMODATION_TYPES)[number];

/**
 * The accommodation types that can be used for backend filtering.
 * "other" is excluded as it is reserved for manually-added accommodations.
 */
export const FILTERABLE_ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "motel",
  "alpine_hut",
  "wilderness_hut",
  "shelter",
  "rental",
] as const satisfies ReadonlyArray<AccommodationType>;

export type FilterableAccommodationType =
  (typeof FILTERABLE_ACCOMMODATION_TYPES)[number];

/**
 * Types enabled on a new trip. Mirrors TripRequest::DEFAULT_ACCOMMODATION_TYPES:
 * "rental" (gîte / meublé) is filterable but opt-in, because a large share of
 * that market is let by the week and cannot be booked for a single night.
 */
export const DEFAULT_ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "motel",
  "alpine_hut",
  "wilderness_hut",
  "shelter",
] as const satisfies ReadonlyArray<FilterableAccommodationType>;

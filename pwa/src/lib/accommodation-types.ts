/**
 * All supported accommodation types (including "other" for manually-added entries).
 * Mirrors the OSM/backend type values used in AccommodationData.
 */
export const ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "motel",
  "alpine_hut",
  "other",
] as const;

export type AccommodationType = (typeof ACCOMMODATION_TYPES)[number];

/**
 * The 7 OSM tourism types that can be used for backend Overpass filtering.
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
] as const satisfies ReadonlyArray<AccommodationType>;

export type FilterableAccommodationType =
  (typeof FILTERABLE_ACCOMMODATION_TYPES)[number];

/**
 * All supported accommodation types for filtering.
 * Mirrors OSM tourism tags used in App\Scanner\OsmOverpassQueryBuilder on the backend.
 * Keep in sync with the PHP source.
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

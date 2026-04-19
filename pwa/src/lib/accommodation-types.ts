/**
 * All supported accommodation types for filtering.
 * Mirrors OSM tags used in App\Scanner\OsmOverpassQueryBuilder on the backend.
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
  "other",
] as const;

export type AccommodationType = (typeof ACCOMMODATION_TYPES)[number];

/**
 * The 9 accommodation types that can be used for backend Overpass filtering.
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
] as const satisfies ReadonlyArray<AccommodationType>;

export type FilterableAccommodationType =
  (typeof FILTERABLE_ACCOMMODATION_TYPES)[number];

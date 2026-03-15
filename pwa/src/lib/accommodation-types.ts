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

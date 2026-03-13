/**
 * All supported accommodation types for filtering.
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

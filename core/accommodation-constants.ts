export const MAX_ACCOMMODATION_RADIUS_KM = 15;
export const ACCOMMODATION_RADIUS_STEP_KM = 2;
export const DEFAULT_ACCOMMODATION_RADIUS_KM = 5;

/**
 * Accommodation types that can be used for backend filtering, framework-free so
 * both `pwa` and `mobile` share one source of truth (ADR-055, #1046). Mirrors
 * the backend `TripRequest::ALL_ACCOMMODATION_TYPES`; "other" is excluded
 * (reserved for manually-added accommodations). The subset-of-the-full-contract
 * invariant is enforced by pwa/src/lib/accommodation-types.test.ts.
 */
export const FILTERABLE_ACCOMMODATION_TYPES = [
  "hotel",
  "hostel",
  "camp_site",
  "chalet",
  "guest_house",
  "alpine_hut",
  "wilderness_hut",
] as const;

export type FilterableAccommodationType =
  (typeof FILTERABLE_ACCOMMODATION_TYPES)[number];

import type { TFunction } from 'i18next';

// Localized label for a resupply POI category (free-form OSM string on
// PoiData.category, e.g. `bakery`, `supermarket`, `drinking_water`). Known
// food/water categories have i18n keys under `trip.poiCategory.*`; anything else
// falls back to a humanized form ("fast_food" -> "Fast food") so the raw enum is
// never shown (#1196).
export function poiCategoryLabel(t: TFunction, category: string): string {
  const humanized = category.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
  return t(`trip.poiCategory.${category}` as never, { defaultValue: humanized });
}

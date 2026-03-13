import type { AccommodationData } from "@/lib/validation/schemas";

/**
 * Formats a distance in kilometers with one decimal place.
 * Returns null when the distance is zero (unknown / not provided).
 */
export function formatDistanceKm(km: number): string | null {
  if (km <= 0) return null;
  return `${km.toFixed(1)} km`;
}

export function formatPrice(acc: AccommodationData): string | null {
  const min = Number(acc.estimatedPriceMin);
  const max = Number(acc.estimatedPriceMax);

  if (isNaN(min) || isNaN(max) || (min === 0 && max === 0)) return null;

  const fmt = new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: "EUR",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });

  if (acc.isExactPrice || min === max) {
    return fmt.format(max);
  }
  return `${fmt.format(min)} – ${fmt.format(max)}`;
}

import type { AccommodationData } from "@/lib/validation/schemas";

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

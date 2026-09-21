import type { Theme } from '../theme';
import type { TripListItem } from '../api/trips';

// Pure trips-list helpers, extracted from the screen so the status/badge logic is
// unit-testable (the codebase's convention — see trip-actions.ts, use-trips.ts).

// Taken from the generated schema rather than restated: a hand-written copy silently
// survived the server gaining `failed` (ADR-072), which is the drift the type contract
// exists to catch.
export type TripStatus = NonNullable<TripListItem['status']>;

export function statusOf(item: Pick<TripListItem, 'status'>): TripStatus {
  return (item.status ?? 'draft') as TripStatus;
}

export interface BadgeColors {
  bg: string;
  fg: string;
  border: string;
}

/** Theme tokens for the status badge on a trip card: brand-amber while analysing,
 * green once analysed, red when every computation failed, neutral for a draft. */
export function badgeColors(theme: Theme, status: TripStatus): BadgeColors {
  if (status === 'failed') {
    return {
      bg: theme.colors.dangerSoft,
      fg: theme.colors.dangerInk,
      border: theme.colors.dangerBorder,
    };
  }
  if (status === 'analyzing') {
    return {
      bg: theme.colors.accentSoft,
      fg: theme.colors.accentInk,
      border: theme.colors.accentBrand,
    };
  }
  if (status === 'analyzed') {
    return {
      bg: theme.colors.successSoft,
      fg: theme.colors.successInk,
      border: theme.colors.successBorder,
    };
  }
  return {
    bg: theme.colors.muted,
    fg: theme.colors.mutedForeground,
    border: theme.colors.border,
  };
}

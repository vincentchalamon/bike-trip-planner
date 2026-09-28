import type { ThemeColors } from '../../theme';

// Pure date/state helpers for the roadbook. The day arithmetic itself lives in
// @btp/core (stageDate / todayUtc, shared with the web); like it, everything
// here works on `YYYY-MM-DD` strings in UTC so the result is timezone-stable:
// CI runs in Europe/Paris, a dev container in UTC, and a device in the rider's
// local zone must all agree. The reference "today" is
// injected so the functions stay deterministic and testable.

export type TripLifecycle = 'upcoming' | 'ongoing' | 'past';

// The trip lifecycle state from its dates vs today (all `YYYY-MM-DD`, UTC).
// Null when either bound is missing — the caller shows the "set your dates"
// banner then. String comparison is exact for fixed-width ISO dates.
export function tripStateFromDates(
  startDate: string | null,
  endDate: string | null,
  today: string,
): TripLifecycle | null {
  if (!startDate || !endDate) return null;
  if (startDate > today) return 'upcoming';
  if (endDate < today) return 'past';
  return 'ongoing';
}

// Whether a stage date is today — only meaningful for the "Aujourd'hui" pastille
// on an ongoing trip.
export function isStageToday(stageDate: string | null, today: string): boolean {
  return stageDate !== null && stageDate === today;
}

// Theme colour key for the roadbook summary header, per lifecycle state:
// ongoing is highlighted (brand), past is faded (muted), upcoming/unknown use
// the default ink. Returning a key keeps the mapping pure and testable without
// a Theme instance.
export function summaryColorKey(state: TripLifecycle | null): keyof ThemeColors {
  switch (state) {
    case 'ongoing':
      return 'accentBrand';
    case 'past':
      return 'mutedForeground';
    default:
      return 'foreground';
  }
}

// Localised short date for a `YYYY-MM-DD` string, formatted in UTC so the
// displayed day never drifts by a timezone offset. e.g. "mer. 13 août".
export function formatStageDate(stageDate: string, locale: string): string {
  const d = new Date(stageDate + 'T00:00:00Z');
  if (Number.isNaN(d.getTime())) return stageDate;
  return new Intl.DateTimeFormat(locale, {
    timeZone: 'UTC',
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  }).format(d);
}

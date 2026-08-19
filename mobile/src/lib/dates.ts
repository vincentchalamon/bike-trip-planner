// Single source of truth for trip date-range formatting across the mobile app
// (trips-list card, roadbook summary card, share infographic). Parsing is always
// UTC so the shown day never drifts by a timezone offset, and the fallbacks are
// shared, so the three surfaces can't silently diverge. Visual variations
// (separator, month width, how the start reads) are opt-in via `opts`.

export type TripDateStartStyle = 'day' | 'dayMonth' | 'full';

export interface TripDateRangeOptions {
  /** Between start and end (default ' → '). */
  separator?: string;
  /** Month width (default 'long'). */
  month?: 'short' | 'long';
  /** How the start reads when a full end follows (default 'day', i.e. "15"). */
  startStyle?: TripDateStartStyle;
}

function parseUtc(iso: string): Date {
  return new Date(iso.includes('T') ? iso : `${iso}T00:00:00Z`);
}

/**
 * Localised trip date range, e.g. "15 → 20 août 2026". Returns '' when there is
 * no start (the caller decides the "dates to define" copy), the raw start string
 * when it can't be parsed, and a single full date when there is no (or an
 * unparseable) end.
 */
export function formatTripDateRange(
  start: string | null | undefined,
  end: string | null | undefined,
  locale?: string,
  opts: TripDateRangeOptions = {},
): string {
  const { separator = ' → ', month = 'long', startStyle = 'day' } = opts;
  const fmt = (d: Date, o: Intl.DateTimeFormatOptions): string =>
    new Intl.DateTimeFormat(locale, { timeZone: 'UTC', ...o }).format(d);
  const full = (d: Date): string => fmt(d, { day: 'numeric', month, year: 'numeric' });
  const startFmt = (d: Date): string => {
    if (startStyle === 'full') return full(d);
    if (startStyle === 'dayMonth') return fmt(d, { day: 'numeric', month });
    return fmt(d, { day: 'numeric' });
  };

  if (!start) {
    if (!end) return '';
    const e = parseUtc(end);
    return Number.isNaN(e.getTime()) ? end : full(e);
  }
  const s = parseUtc(start);
  if (Number.isNaN(s.getTime())) return start;
  if (!end) return full(s);
  const e = parseUtc(end);
  if (Number.isNaN(e.getTime())) return full(s);
  return `${startFmt(s)}${separator}${full(e)}`;
}

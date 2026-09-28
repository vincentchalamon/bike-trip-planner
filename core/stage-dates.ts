// Calendar-day arithmetic shared by web and mobile (ADR-055). Each stage spans
// one calendar day, rest days included (recette #649), so stage n falls on
// startDate + (n - 1) days. All math is done on `YYYY-MM-DD` strings in UTC so
// the result does not depend on the zone of the machine: CI runs in
// Europe/Paris, a dev container in UTC, a device in the rider's own zone.

/** The current UTC calendar day as `YYYY-MM-DD`. */
export function todayUtc(now: Date = new Date()): string {
  return now.toISOString().slice(0, 10);
}

/**
 * The calendar day of a stage, as `YYYY-MM-DD`. Accepts a bare date or an API
 * date-time, whose calendar day is its date part. Null without a start date or
 * on an unparseable one.
 */
export function stageDate(
  startDate: string | null,
  dayNumber: number,
): string | null {
  if (!startDate) return null;
  const d = new Date(`${startDate.slice(0, 10)}T00:00:00Z`);
  if (Number.isNaN(d.getTime())) return null;
  d.setUTCDate(d.getUTCDate() + Math.max(0, dayNumber - 1));
  return d.toISOString().slice(0, 10);
}

/** The last day of a trip of `stageCount` stages; null without a start date. */
export function endDateFor(
  startDate: string | null,
  stageCount: number,
): string | null {
  return stageDate(startDate, stageCount);
}

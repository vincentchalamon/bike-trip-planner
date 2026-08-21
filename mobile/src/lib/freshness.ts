// Human "synchronised X ago" label for the offline cache (ADR-059). Pure and
// fully injectable: it never reads the clock itself — the caller passes both the
// last-sync timestamp and the reference `now` (ms epoch) — so the buckets are
// deterministic under test. The wording is delegated to i18next: the helper only
// decides the bucket and the count, the `freshness.*` keys hold the copy.
import type { TFunction } from 'i18next';

const HOUR_MS = 3_600_000;
const DAY_MS = 86_400_000;

/**
 * Freshness label from a last-sync timestamp and an injected `now` (both ms
 * epoch): "à l'instant" under an hour, "il y a N h" within the day, "hier" the
 * day after, "il y a N j" beyond. A last-sync in the future (clock skew) reads as
 * "à l'instant".
 */
export function formatFreshness(t: TFunction, lastSyncMs: number, nowMs: number): string {
  const diff = nowMs - lastSyncMs;
  if (diff < HOUR_MS) return t('freshness.justNow');
  if (diff < DAY_MS) return t('freshness.hoursAgo', { count: Math.floor(diff / HOUR_MS) });
  if (diff < 2 * DAY_MS) return t('freshness.yesterday');
  return t('freshness.daysAgo', { count: Math.floor(diff / DAY_MS) });
}

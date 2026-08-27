import { useEffect, useState } from 'react';
import { fetchAllTrips, type TripListItem } from '../api/trips';

/**
 * Every trip across all pages, for the local-notification scheduler (which must
 * see trips beyond the paginated list's first page). Re-fetched whenever
 * `refreshKey` changes — pass a signal that flips only when the trip SET changes
 * (create/delete/duplicate/reload), NOT on filtering or scrolling, so this does
 * not cascade a full sequential all-pages refetch on every keystroke or scroll
 * page. Pass `null` to pause the refetch entirely (e.g. while a filter is active)
 * and keep the last known list. Failures fall back to that last list too: a
 * missed reminder is best-effort, never a crash. The paginated UI list is
 * untouched.
 */
export function useAllTrips(refreshKey: number | null): TripListItem[] {
  const [all, setAll] = useState<TripListItem[]>([]);

  useEffect(() => {
    if (refreshKey === null) return;
    let cancelled = false;
    void fetchAllTrips()
      .then((trips) => {
        // Drop a stale response that lands after refreshKey changed / unmount, so
        // it can't clobber a newer list.
        if (!cancelled) setAll(trips);
      })
      .catch(() => {
        // Best-effort: keep the last known list rather than surfacing an error.
      });
    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  return all;
}

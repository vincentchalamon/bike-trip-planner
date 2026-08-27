import { useEffect, useState } from 'react';
import { fetchAllTrips, type TripListItem } from '../api/trips';

/**
 * Every trip across all pages, for the local-notification scheduler (which must
 * see trips beyond the paginated list's first page). Re-fetched whenever
 * `refreshKey` changes — pass the visible (page 1) list so a create/delete that
 * refreshes it also refreshes this. Failures fall back to an empty list: a
 * missed reminder is best-effort, never a crash. The paginated UI list is left
 * untouched.
 */
export function useAllTrips(refreshKey: unknown): TripListItem[] {
  const [all, setAll] = useState<TripListItem[]>([]);

  useEffect(() => {
    let cancelled = false;
    void fetchAllTrips()
      .then((trips) => {
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

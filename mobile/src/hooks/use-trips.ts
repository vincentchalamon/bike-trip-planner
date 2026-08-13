import { useCallback, useEffect, useState } from 'react';
import { fetchTrips, type TripListItem } from '../api/trips';

export interface TripsState {
  trips: TripListItem[];
  error: string | null;
}

// Extracted so the load/error branch is unit-testable without a React renderer
// (mirrors runTripLive, #1014). Never throws: a backend failure resolves to an
// empty list + an error message the caller surfaces.
export async function runLoadTrips(): Promise<TripsState> {
  try {
    return { trips: await fetchTrips(), error: null };
  } catch {
    return { trips: [], error: 'Impossible de charger les voyages.' };
  }
}

// Load the authenticated user's trip list. `reload` re-runs the fetch (e.g. on
// pull-to-refresh). Late results are dropped after unmount.
export function useTrips(): {
  trips: TripListItem[];
  loading: boolean;
  error: string | null;
  reload: () => void;
} {
  const [state, setState] = useState<TripsState>({ trips: [], error: null });
  const [loading, setLoading] = useState(true);
  const [nonce, setNonce] = useState(0);

  const reload = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    void runLoadTrips().then((next) => {
      if (cancelled) return;
      setState(next);
      setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [nonce]);

  return { trips: state.trips, loading, error: state.error, reload };
}

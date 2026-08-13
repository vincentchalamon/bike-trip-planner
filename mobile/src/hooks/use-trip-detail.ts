import { useCallback, useEffect, useState } from 'react';
import { fetchTripDetail, type TripDetail } from '../api/trips';

export interface TripDetailState {
  detail: TripDetail | null;
  error: string | null;
}

// Extracted for unit-testing the async/error branches (mirrors runTripLive).
// One-shot fetch (no SSE): use it for a read-only preview; the live roadbook
// hydration + Mercure subscription lives in useTripLive.
export async function runLoadTripDetail(id: string): Promise<TripDetailState> {
  try {
    const detail = await fetchTripDetail(id);
    if (!detail) return { detail: null, error: 'Voyage introuvable.' };
    return { detail, error: null };
  } catch {
    return { detail: null, error: 'Impossible de charger le voyage.' };
  }
}

// Fetch a single trip's persisted /detail payload once. `reload` re-runs it.
export function useTripDetail(id: string): {
  detail: TripDetail | null;
  loading: boolean;
  error: string | null;
  reload: () => void;
} {
  const [state, setState] = useState<TripDetailState>({
    detail: null,
    error: null,
  });
  const [loading, setLoading] = useState(true);
  const [nonce, setNonce] = useState(0);

  const reload = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    void runLoadTripDetail(id).then((next) => {
      if (cancelled) return;
      setState(next);
      setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [id, nonce]);

  return { detail: state.detail, loading, error: state.error, reload };
}

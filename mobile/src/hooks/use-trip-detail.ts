import { useCallback, useEffect, useState } from 'react';
import { fetchTripDetail, type TripDetail } from '../api/trips';
import { useOfflineStore } from '../store/offline-store';
import { cacheTripDetail, readTripCache } from '../store/trip-cache';

export interface TripDetailState {
  detail: TripDetail | null;
  error: string | null;
}

// Extracted for unit-testing the async/error branches (mirrors runTripLive).
// One-shot fetch (no SSE): use it for a read-only preview; the live roadbook
// hydration + Mercure subscription lives in useTripLive.
//
// Offline-aware (#1147): when the device is offline we serve the persisted cache
// straight away, and a network failure falls back to it too. A successful online
// load refreshes the cache (upcoming/ongoing trips only — see cacheTripDetail).
export async function runLoadTripDetail(id: string): Promise<TripDetailState> {
  if (!useOfflineStore.getState().isOnline) {
    const cached = await readTripCache(id);
    if (cached) return { detail: cached.detail, error: null };
  }
  try {
    const detail = await fetchTripDetail(id);
    if (!detail) return { detail: null, error: 'Voyage introuvable.' };
    void cacheTripDetail(id, detail);
    return { detail, error: null };
  } catch {
    const cached = await readTripCache(id);
    if (cached) return { detail: cached.detail, error: null };
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

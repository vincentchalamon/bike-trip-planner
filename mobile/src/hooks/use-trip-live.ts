import { useEffect } from 'react';
import { enrichedPayloadToStageData } from '@btp/core/reconciliation';
import { fetchTripDetail } from '../api/trips';
import { fetchMercureToken, subscribeToTrip, type TripSubscription } from '../api/mercure';
import { useTripStore } from '../store/trip-store';

// Loads a trip into the shared store and keeps it live via Mercure SSE. The
// store composes the core reducers, so trip_ready / stage_updated events are
// reconciled with the exact same logic as the web (#1013/#1014).
export function useTripLive(id: string): void {
  const hydrate = useTripStore((s) => s.hydrate);
  const applyTripReady = useTripStore((s) => s.applyTripReady);
  const applyStageUpdate = useTripStore((s) => s.applyStageUpdate);
  const setStatus = useTripStore((s) => s.setStatus);
  const reset = useTripStore((s) => s.reset);

  useEffect(() => {
    let sub: TripSubscription | undefined;
    let cancelled = false;
    setStatus({ loading: true, error: null });

    void (async () => {
      try {
        const detail = await fetchTripDetail(id);
        if (cancelled) return;
        if (!detail) {
          setStatus({ loading: false, error: 'Voyage introuvable.' });
          return;
        }
        hydrate(id, detail);
        try {
          const token = await fetchMercureToken(id);
          if (cancelled) return;
          sub = subscribeToTrip(id, token, (event) => {
            if (event.type === 'trip_ready') {
              applyTripReady(event.data.stages.map(enrichedPayloadToStageData));
            } else if (event.type === 'stage_updated') {
              applyStageUpdate(
                event.data.stageIndex,
                enrichedPayloadToStageData(event.data.stage),
              );
            }
          });
        } catch {
          // Live updates unavailable (e.g. token fetch failed); the hydrated
          // roadbook still renders, just without SSE reconciliation.
        }
      } catch {
        if (!cancelled) {
          setStatus({ loading: false, error: 'Impossible de charger le roadbook.' });
        }
      }
    })();

    return () => {
      cancelled = true;
      sub?.close();
      reset();
    };
  }, [id, hydrate, applyTripReady, applyStageUpdate, setStatus, reset]);
}

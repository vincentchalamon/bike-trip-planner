import { useEffect, useReducer } from 'react';
import type { MercureEvent } from '@btp/core/mercure';
import {
  fetchMercureToken,
  subscribeToTrip,
  type TripSubscription,
} from '../api/mercure';

// Live state of the computation pipeline for a freshly created / re-analyzed
// trip, driven by the Mercure SSE stream. `computing` gates the progress badge;
// `done` flips on the terminal trip_ready / trip_complete; `failed` on a
// non-retryable computation_error. `completed`/`total` feed a coarse progress
// readout ("étape 3 / 7") without pulling in the whole roadbook store.
export interface AnalysisFollowState {
  computing: boolean;
  done: boolean;
  failed: boolean;
  completed: number;
  total: number;
}

export const INITIAL_FOLLOW_STATE: AnalysisFollowState = {
  computing: true,
  done: false,
  failed: false,
  completed: 0,
  total: 0,
};

// Pure SSE-event reducer, mirroring the lifecycle handling in use-trip-live but
// scoped to progress (no stage reconciliation). Extracted so the state machine
// is unit-testable without a subscription or a React renderer.
export function reduceAnalysisEvent(
  state: AnalysisFollowState,
  event: MercureEvent,
): AnalysisFollowState {
  switch (event.type) {
    case 'computation_step_completed':
      return {
        ...state,
        computing: true,
        done: false,
        completed: event.data.completed,
        total: event.data.total,
      };
    case 'trip_ready':
    case 'trip_complete':
      return { ...state, computing: false, done: true };
    case 'computation_error':
      // A retryable error means the run is still going; only a terminal one
      // clears the badge and surfaces the failure.
      return event.data.retryable
        ? state
        : { ...state, computing: false, failed: true };
    default:
      return state;
  }
}

// Open a Mercure SSE subscription for `tripId` and fold each event into the
// progress state. Re-subscribes when `tripId` changes (a new create) or when
// `nonce` bumps (an explicit re-launch of the analysis, to reset progress).
// Returns undefined-safe state; when `tripId` is null nothing is subscribed.
export function useAnalysisFollow(
  tripId: string | null,
  nonce = 0,
): AnalysisFollowState {
  const [state, dispatch] = useReducer(
    (s: AnalysisFollowState, e: MercureEvent | 'reset') =>
      e === 'reset' ? INITIAL_FOLLOW_STATE : reduceAnalysisEvent(s, e),
    INITIAL_FOLLOW_STATE,
  );

  useEffect(() => {
    if (!tripId) return;
    let sub: TripSubscription | undefined;
    let cancelled = false;
    dispatch('reset');

    void fetchMercureToken(tripId)
      .then((token) => {
        if (cancelled) return;
        sub = subscribeToTrip(tripId, token, (event) => dispatch(event));
      })
      .catch(() => {
        // Token fetch failed: leave the badge as-is; the roadbook screen still
        // re-hydrates from /detail when the rider opens it.
      });

    return () => {
      cancelled = true;
      sub?.close();
    };
  }, [tripId, nonce]);

  return state;
}

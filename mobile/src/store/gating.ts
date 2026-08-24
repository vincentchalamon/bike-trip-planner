// Transversal mutation gating + error normalization shared by every editing
// mutation (#1031). One source of truth so the store, the mutation runners and
// (Sprint 55/56) the screens all refuse and classify failures the same way.

/**
 * Normalized outcome of a blocked or failed mutation. The gate reasons
 * (`locked` / `out_of_zone` / `offline`) are pre-flight refusals; the rest are
 * classifications of a backend/network failure (see {@link normalizeStatus}).
 */
export type MutationFailure =
  | 'locked'
  | 'out_of_zone'
  | 'offline'
  | 'api_unavailable'
  | 'validation'
  | 'not_found'
  | 'conflict'
  | 'network'
  | 'error';

/** The four transversal conditions every mutation is gated on. */
export interface GateState {
  /** Trip started (startDate <= today): the backend rejects edits with 423. */
  isLocked: boolean;
  /** Route outside the provisioned coverage area: no Valhalla rerouting. */
  outOfZone: boolean;
  /** Device connectivity — mutations are disabled while offline. */
  isOnline: boolean;
  /** API health — false after a network error / 5xx; disables writes even online. */
  apiReachable: boolean;
}

/**
 * Connectivity-only refusal, shared by {@link evaluateGate} and the
 * duplicate/delete runners (which allow a locked / out-of-zone trip — a clone or
 * delete is not an edit — so they can't use the full gate but must still refuse
 * offline / API-down). Offline wins over api_unavailable.
 */
export function connectivityRefusal(
  state: Pick<GateState, 'isOnline' | 'apiReachable'>,
): 'offline' | 'api_unavailable' | null {
  if (!state.isOnline) return 'offline';
  if (!state.apiReachable) return 'api_unavailable';
  return null;
}

/**
 * Pre-flight gate shared by all mutations. Connectivity blocks everything
 * (offline, then API-down); a started trip (423) is fully read-only; an
 * out-of-zone trip blocks only mutations that need Valhalla rerouting
 * (`requiresRouting`). Returns the blocking reason, or `null` when the mutation
 * may proceed. Precedence: offline > api_unavailable > locked > out_of_zone, so
 * the most fundamental obstacle is reported first.
 */
export function evaluateGate(
  state: GateState,
  requiresRouting: boolean,
): 'offline' | 'api_unavailable' | 'locked' | 'out_of_zone' | null {
  const connectivity = connectivityRefusal(state);
  if (connectivity) return connectivity;
  if (state.isLocked) return 'locked';
  if (requiresRouting && state.outOfZone) return 'out_of_zone';
  return null;
}

/**
 * Classify an HTTP status into a {@link MutationFailure}. Per the API error
 * contract (CLAUDE.md): object-level authorization denials are masked as 404
 * (never 403), and an unknown backed-enum value fails denormalization as 422
 * (not 400). 423 = trip locked, 409 = stale accommodation list. `status === 0`
 * marks a request that never reached the backend.
 */
export function normalizeStatus(status: number): MutationFailure {
  switch (status) {
    case 422:
      return 'validation';
    case 404:
      return 'not_found';
    case 423:
      return 'locked';
    case 409:
      return 'conflict';
    case 0:
      return 'network';
    default:
      return 'error';
  }
}

import { createTrip } from '../api/trips';
import { normalizeStatus, type MutationFailure } from './gating';
import { useOfflineStore } from './offline-store';

/**
 * Supported source URL patterns, mirroring the backend `RouteFetcherRegistry`
 * strategies (CLAUDE.md, Security Constraints). The backend stays the source of
 * truth; these give fast client-side feedback before the POST is sent. Kept in
 * lockstep with the web `SUPPORTED_SOURCE_PATTERNS`.
 */
export const SUPPORTED_SOURCE_PATTERNS: readonly RegExp[] = [
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?tour\/\d+/,
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?collection\/\d+/,
  /^https:\/\/www\.strava\.com\/routes\/\d+/,
  /^https:\/\/ridewithgps\.com\/routes\/\d+/,
] as const;

/** Whether a URL matches one of the supported route source patterns. */
export function isSupportedSourceUrl(value: string): boolean {
  const trimmed = value.trim();
  if (!trimmed) return false;
  return SUPPORTED_SOURCE_PATTERNS.some((pattern) => pattern.test(trimmed));
}

/**
 * Create a trip from a pasted / shared link. Blocked while offline (like every
 * other mutation, see gating.ts); a backend rejection (invalid or unsupported
 * URL) is normalized to a {@link MutationFailure}. Returns the new trip id, or
 * null with the failure reported. Client-side URL validation is the screen's
 * job — this runner still forwards whatever the backend answers.
 */
export async function runCreateTrip(
  sourceUrl: string,
  onFailure: (reason: MutationFailure) => void,
): Promise<string | null> {
  if (!useOfflineStore.getState().isOnline) {
    onFailure('offline');
    return null;
  }
  try {
    const { id, status } = await createTrip(sourceUrl.trim());
    if (!id) {
      onFailure(normalizeStatus(status));
      return null;
    }
    return id;
  } catch {
    onFailure('network');
    return null;
  }
}

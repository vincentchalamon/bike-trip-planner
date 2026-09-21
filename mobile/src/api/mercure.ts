import EventSource from 'react-native-sse';
import type { MercureEnvelope, MercureEvent } from '@btp/core/mercure';
import { setTripVersion } from './trips';
import { api } from './client';
import { API_BASE_URL } from './config';

const ld = { Accept: 'application/ld+json' };

// Fetch the per-trip Mercure subscriber JWT from the readable-body endpoint
// (#1019) so a non-browser client can present it as an Authorization header
// (the hub was confirmed header-auth capable in #1011; the browser uses a cookie
// React Native cannot read).
export async function fetchMercureToken(tripId: string): Promise<string> {
  const { data, error } = await api.GET('/trips/{id}/mercure-token', {
    params: { path: { id: tripId } },
    headers: ld,
  });
  if (error || !data?.token) {
    throw new Error('Failed to fetch Mercure token');
  }
  return data.token;
}

export interface TripSubscription {
  close: () => void;
}

// Subscribe to a trip's SSE topic with header auth, forwarding each parsed
// MercureEvent. react-native-sse supports custom headers (unlike the browser's
// cookie-only EventSource), which is the whole point of the #1011 spike.
export function subscribeToTrip(
  tripId: string,
  token: string,
  onEvent: (event: MercureEvent) => void,
): TripSubscription {
  const url = new URL(`${API_BASE_URL}/.well-known/mercure`);
  // Mercure 1.0: `topic` became `match` (exact) / `match_urlpattern`.
  url.searchParams.set('match', `/trips/${tripId}`);

  const es = new EventSource(url.toString(), {
    headers: { Authorization: `Bearer ${token}` },
    // Mercure/Caddy streams SSE with `\n` line endings; pin it so react-native-sse
    // parses deterministically instead of warning it cannot auto-detect them.
    lineEndingCharacter: '\n',
  });

  es.addEventListener('message', (event) => {
    if (event.type !== 'message' || event.data === null) {
      return;
    }
    try {
      const envelope = JSON.parse(event.data) as MercureEnvelope;
      // Record the version the envelope carries before reducing: a regeneration performed
      // by a worker moves it with no HTTP response to carry a fresh ETag, and the next edit
      // pins whatever is recorded here.
      if (envelope.version !== undefined) setTripVersion(tripId, envelope.version);
      onEvent(envelope);
    } catch {
      // Ignore keep-alive frames and malformed payloads.
    }
  });

  return {
    close: () => {
      es.removeAllEventListeners();
      es.close();
    },
  };
}

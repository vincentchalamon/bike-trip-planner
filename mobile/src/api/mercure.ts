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
  // Drop the current connection and open a fresh one with a fresh token (app back
  // in the foreground, network regained).
  reconnect: () => void;
}

export interface SubscribeOptions {
  // Called on every open; `reopened` is false only for the first one. Nothing
  // replays what was published while the stream was down, so on a reopen the
  // caller re-reads the authoritative state (/detail).
  onOpen?: (reopened: boolean) => void;
}

// Renew this long before the token expires, so the connection is swapped while
// the old token still opens the hub.
const RENEW_MARGIN_SECONDS = 60;
// Floor on the renewal delay: claims that make the token look already expired
// (odd TTL, missing iat on a skewed clock) must not turn renewal into a hot loop.
const MIN_RENEW_DELAY_MS = 30_000;
export const INITIAL_RETRY_DELAY_MS = 1_000;
export const MAX_RETRY_DELAY_MS = 30_000;

// Milliseconds until the token should be renewed, or null when its claims do not
// say. Measured from `iat` when present so a skewed device clock does not matter:
// the TTL is the server's, counted from the moment the token was received.
export function renewDelayMs(token: string): number | null {
  const payload = token.split('.')[1];
  if (!payload) return null;
  let claims: { exp?: unknown; iat?: unknown };
  try {
    claims = JSON.parse(decodeBase64Url(payload));
  } catch {
    return null;
  }
  if (claims === null || typeof claims !== 'object' || typeof claims.exp !== 'number') {
    return null;
  }
  const issuedAt = typeof claims.iat === 'number' ? claims.iat : Date.now() / 1000;
  const ttl = claims.exp - issuedAt - RENEW_MARGIN_SECONDS;
  return Math.max(ttl * 1000, MIN_RENEW_DELAY_MS);
}

const BASE64URL_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

// Decoded by hand rather than with the global `atob`: nothing guarantees it on
// every JS engine the app runs on, and a missing one would be swallowed by the
// caller's catch, silently disabling renewal on device while Jest (Node) passes.
// The claims are ASCII, so bytes map to characters one to one.
export function decodeBase64Url(segment: string): string {
  let output = '';
  let buffer = 0;
  let bits = 0;
  for (const char of segment) {
    if (char === '=') break;
    const value = BASE64URL_ALPHABET.indexOf(char);
    if (value === -1) throw new SyntaxError('Invalid base64url');
    buffer = ((buffer << 6) | value) & 0xffff;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      output += String.fromCharCode((buffer >> bits) & 0xff);
    }
  }
  return output;
}

// Subscribe to a trip's SSE topic with header auth, forwarding each parsed
// MercureEvent. react-native-sse supports custom headers (unlike the browser's
// cookie-only EventSource), which is the whole point of the #1011 spike.
//
// The subscriber JWT lives one hour (MercureTokenIssuer) and react-native-sse
// replays the headers it was built with on every reconnect: once the token has
// expired it polls the hub with it forever (a 401 every `pollingInterval`), and
// after a network error (`xhr.onerror`) it does not reconnect at all. So the
// library's own reconnect is never relied on: any error closes the EventSource
// and a new one is opened with a freshly fetched token, under a capped
// exponential backoff, and the token is renewed before it expires.
export function subscribeToTrip(
  tripId: string,
  onEvent: (event: MercureEvent) => void,
  options: SubscribeOptions = {},
): TripSubscription {
  const url = new URL(`${API_BASE_URL}/.well-known/mercure`);
  // Mercure 1.0: `topic` became `match` (exact) / `match_urlpattern`.
  url.searchParams.set('match', `/trips/${tripId}`);

  let es: EventSource | null = null;
  let closed = false;
  let opened = false;
  // Bumped by every connect attempt: a token fetch that resolves after a newer
  // attempt (or after close) is dropped instead of opening a second connection.
  let generation = 0;
  let retryDelay = INITIAL_RETRY_DELAY_MS;
  let retryTimer: ReturnType<typeof setTimeout> | null = null;
  let renewTimer: ReturnType<typeof setTimeout> | null = null;

  const clearTimers = () => {
    if (retryTimer !== null) clearTimeout(retryTimer);
    if (renewTimer !== null) clearTimeout(renewTimer);
    retryTimer = null;
    renewTimer = null;
  };

  const dropSource = () => {
    if (!es) return;
    es.removeAllEventListeners();
    es.close();
    es = null;
  };

  const scheduleRetry = () => {
    if (closed || retryTimer !== null) return;
    const delay = retryDelay;
    retryDelay = Math.min(retryDelay * 2, MAX_RETRY_DELAY_MS);
    retryTimer = setTimeout(() => {
      retryTimer = null;
      connect();
    }, delay);
  };

  const open = (token: string) => {
    const source = new EventSource(url.toString(), {
      headers: { Authorization: `Bearer ${token}` },
      // Mercure/Caddy streams SSE with `\n` line endings; pin it so react-native-sse
      // parses deterministically instead of warning it cannot auto-detect them.
      lineEndingCharacter: '\n',
    });
    es = source;

    source.addEventListener('open', () => {
      retryDelay = INITIAL_RETRY_DELAY_MS;
      const reopened = opened;
      opened = true;
      options.onOpen?.(reopened);
    });

    // A 401 (expired or revoked token), any other HTTP error, a network error or a
    // timeout are all answered the same way: a new token on a new connection.
    source.addEventListener('error', () => {
      if (renewTimer !== null) clearTimeout(renewTimer);
      renewTimer = null;
      dropSource();
      scheduleRetry();
    });

    source.addEventListener('message', (event) => {
      if (event.type !== 'message' || event.data === null) {
        return;
      }
      let envelope: MercureEnvelope;
      try {
        envelope = JSON.parse(event.data) as MercureEnvelope;
      } catch {
        // Ignore keep-alive frames and malformed payloads. Only the parse is guarded: an
        // exception from the reducer or the store below is a bug and must surface.
        return;
      }
      if (envelope === null || typeof envelope !== 'object') return;
      // Record the version the envelope carries before reducing: a regeneration performed
      // by a worker moves it with no HTTP response to carry a fresh ETag, and the next edit
      // pins whatever is recorded here.
      if (envelope.version !== undefined) setTripVersion(tripId, envelope.version);
      onEvent(envelope);
    });

    const delay = renewDelayMs(token);
    if (delay !== null) {
      renewTimer = setTimeout(() => {
        renewTimer = null;
        connect();
      }, delay);
    }
  };

  function connect(): void {
    if (closed) return;
    const attempt = ++generation;
    fetchMercureToken(tripId).then(
      (token) => {
        if (closed || attempt !== generation) return;
        // Swap only once the new token is in hand: a failed fetch keeps the
        // current connection, which may well still be alive.
        clearTimers();
        dropSource();
        open(token);
      },
      () => {
        if (closed || attempt !== generation) return;
        scheduleRetry();
      },
    );
  }

  connect();

  return {
    close: () => {
      closed = true;
      clearTimers();
      dropSource();
    },
    reconnect: () => {
      if (closed) return;
      retryDelay = INITIAL_RETRY_DELAY_MS;
      if (retryTimer !== null) clearTimeout(retryTimer);
      retryTimer = null;
      connect();
    },
  };
}

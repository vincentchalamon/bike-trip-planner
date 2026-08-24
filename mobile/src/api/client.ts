import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from '@btp/core/schema';
import { API_BASE_URL } from './config';
import { getJwt } from '../auth/tokens';
import { refreshTokens } from '../auth/authApi';
import { useOfflineStore } from '../store/offline-store';
import i18n from '../i18n';

const RETRY_HEADER = 'X-Native-Retry';

// Clone of each in-flight request, taken before dispatch. Once fetch() sends a
// body-bearing request its stream is disturbed and clone() throws, so a 401 retry
// cannot clone in onResponse; the mutating calls in #1014/#1015 would break there.
const pendingRetries = new WeakMap<Request, Request>();

export const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const jwt = getJwt();
    if (jwt) {
      request.headers.set('Authorization', `Bearer ${jwt}`);
    }
    // Identify the platform to the edge (Caddy) so it serves this client an
    // uncompressed body. RN's okhttp advertises `zstd, br, gzip` but only
    // transparently decodes gzip it added itself, so a zstd/br-compressed body is
    // read as Latin-1 and accented titles mojibake (UTF-8 `é` C3A9 -> "Ã©").
    // `Accept-Encoding` is a forbidden header okhttp overrides, so it cannot carry
    // the opt-out; this custom header survives the native stack and Caddy rewrites
    // the request's Accept-Encoding to `identity` when it sees it, disabling
    // compression for mobile only (web/PWA clients keep it). See .docker/php/Caddyfile.
    request.headers.set('X-Client-Platform', 'mobile');
    // Without this, the server falls back to its own default (en) for every
    // backend-rendered string (alert messages/actions) regardless of the app's
    // own French UI (#1169) — RN's fetch does not send Accept-Language itself.
    request.headers.set('Accept-Language', i18n.language);
    pendingRetries.set(request, request.clone());
    return request;
  },
  async onResponse({ request, response }) {
    // Track API health for the degraded-mode gate (#1166): a response that reached
    // the backend (even 4xx) means it's up; a 5xx means it's failing.
    useOfflineStore.getState().setApiReachable(response.status < 500);
    // RN's fetch returns a Response from a different realm than openapi-fetch's
    // global `Response`, so `response instanceof Response` is false on device.
    // Return `undefined` to keep the response unchanged (never return the original
    // response object, or openapi-fetch throws "must return new Response()").
    if (response.status !== 401 || request.headers.get(RETRY_HEADER) === '1') {
      return undefined;
    }
    // Never try to refresh an auth call (avoids loops).
    if (new URL(request.url).pathname.startsWith('/auth/')) {
      return undefined;
    }
    const refreshed = await refreshTokens();
    if (!refreshed) {
      return undefined;
    }
    const retry = pendingRetries.get(request) ?? request;
    retry.headers.set(RETRY_HEADER, '1');
    const jwt = getJwt();
    if (jwt) {
      retry.headers.set('Authorization', `Bearer ${jwt}`);
    }
    // Rebuild the retried response with the global `Response` constructor so it
    // passes openapi-fetch's `instanceof Response` check (same realm as the check).
    // Read via arrayBuffer(), not text(): a text round-trip re-encodes the body as
    // UTF-8, corrupting any binary payload (e.g. a #1047 FIT export retried after
    // a mid-download token refresh). arrayBuffer preserves the raw bytes for both
    // binary and text content.
    const retried = await fetch(retry);
    const body = await retried.arrayBuffer();
    return new Response(body, {
      status: retried.status,
      statusText: retried.statusText,
      headers: Object.fromEntries(retried.headers.entries()),
    });
  },
  onError() {
    // A network error (connection refused / DNS / timeout) means the API is
    // unreachable — mark it down so the app degrades to read-only (#1166). Return
    // undefined so openapi-fetch still surfaces the error to the caller.
    useOfflineStore.getState().setApiReachable(false);
    return undefined;
  },
};

export const api = createClient<paths>({ baseUrl: API_BASE_URL });
api.use(authMiddleware);

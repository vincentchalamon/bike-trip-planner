import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from '@btp/core/schema';
import { API_BASE_URL } from './config';
import { getJwt } from '../auth/tokens';
import { refreshTokens } from '../auth/authApi';

const RETRY_HEADER = 'X-Native-Retry';

// Clone of each in-flight request, taken before dispatch. Once fetch() sends a
// body-bearing request its stream is disturbed and clone() throws, so a 401 retry
// cannot clone in onResponse; the mutating calls in #1014/#1015 would break there.
const pendingRetries = new WeakMap<Request, Request>();

const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const jwt = getJwt();
    if (jwt) {
      request.headers.set('Authorization', `Bearer ${jwt}`);
    }
    pendingRetries.set(request, request.clone());
    return request;
  },
  async onResponse({ request, response }) {
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
    const retried = await fetch(retry);
    const body = await retried.text();
    return new Response(body, {
      status: retried.status,
      statusText: retried.statusText,
      headers: Object.fromEntries(retried.headers.entries()),
    });
  },
};

export const api = createClient<paths>({ baseUrl: API_BASE_URL });
api.use(authMiddleware);

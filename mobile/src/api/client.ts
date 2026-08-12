import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';
import { API_BASE_URL } from './config';
import { getJwt } from '../auth/tokens';
import { refreshTokens } from '../auth/authApi';

const RETRY_HEADER = 'X-Native-Retry';

const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const jwt = getJwt();
    if (jwt) {
      request.headers.set('Authorization', `Bearer ${jwt}`);
    }
    return request;
  },
  async onResponse({ request, response }) {
    if (response.status !== 401 || request.headers.get(RETRY_HEADER) === '1') {
      return response;
    }
    // Never try to refresh an auth call (avoids loops).
    if (new URL(request.url).pathname.startsWith('/auth/')) {
      return response;
    }
    const refreshed = await refreshTokens();
    if (!refreshed) {
      return response;
    }
    const retry = request.clone();
    retry.headers.set(RETRY_HEADER, '1');
    const jwt = getJwt();
    if (jwt) {
      retry.headers.set('Authorization', `Bearer ${jwt}`);
    }
    return fetch(retry);
  },
};

export const api = createClient<paths>({ baseUrl: API_BASE_URL });
api.use(authMiddleware);

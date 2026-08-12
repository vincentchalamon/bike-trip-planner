import { API_BASE_URL, CLIENT_TYPE_HEADER, CLIENT_TYPE_VALUE } from '../api/config';
import { clearTokens, getRefresh, setTokens } from './tokens';

// verify / refresh talk to endpoints whose real (native) contract differs from
// the exported OpenAPI (verify is documented 204 but returns { token }; refresh
// is documented input:false but here we post the refresh token). They use plain
// fetch rather than the typed client. See README "Backend native contract".

const jsonHeaders = {
  'Content-Type': 'application/json',
  Accept: 'application/json',
  [CLIENT_TYPE_HEADER]: CLIENT_TYPE_VALUE,
};

type TokenPair = { token?: string; refresh_token?: string };

// Exchange a magic-link token for a JWT + refresh token, then persist both.
export async function verifyMagicToken(token: string): Promise<boolean> {
  const res = await fetch(`${API_BASE_URL}/auth/verify`, {
    method: 'POST',
    headers: jsonHeaders,
    body: JSON.stringify({ token }),
  });
  if (!res.ok) {
    return false;
  }
  const data = (await res.json().catch(() => ({}))) as TokenPair;
  if (!data.token) {
    return false;
  }
  await setTokens(data.token, data.refresh_token ?? null);
  return true;
}

// Deduplicated refresh: concurrent 401s share a single in-flight request.
let inflight: Promise<boolean> | null = null;

export function refreshTokens(): Promise<boolean> {
  if (!inflight) {
    inflight = doRefresh().finally(() => {
      inflight = null;
    });
  }
  return inflight;
}

async function doRefresh(): Promise<boolean> {
  const refresh = getRefresh();
  if (!refresh) {
    return false;
  }
  const res = await fetch(`${API_BASE_URL}/auth/refresh`, {
    method: 'POST',
    headers: jsonHeaders,
    body: JSON.stringify({ refresh_token: refresh }),
  });
  if (!res.ok) {
    await clearTokens();
    return false;
  }
  const data = (await res.json().catch(() => ({}))) as TokenPair;
  if (!data.token) {
    await clearTokens();
    return false;
  }
  await setTokens(data.token, data.refresh_token ?? refresh);
  return true;
}

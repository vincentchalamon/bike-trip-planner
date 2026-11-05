import { API_BASE_URL, LD_JSON } from '../api/config';
import { unregisterDeviceToken } from '../notifications/push';
import { notifySessionInvalidated } from './session';
import { clearTokens, getJwt, getRefresh, setTokens } from './tokens';
import { clearLocalAccountData } from '../store/local-account-data';

// verify / refresh use plain fetch rather than the typed client: both return the
// token pair ({ token, refresh_token }) in the body, and refresh posts the refresh
// token in the body. The API negotiates on JSON-LD only, so both headers are set.

const ldJsonHeaders = {
  'Content-Type': LD_JSON,
  Accept: LD_JSON,
};

type TokenPair = { token?: string; refresh_token?: string };

export type VerifyResult =
  | { kind: 'tokens'; jwt: string; refresh: string | null }
  // The link was not requested from this device, or this device is signed in:
  // nothing was consumed, and the user has to see the account before going on.
  | { kind: 'confirm'; email: string }
  | { kind: 'invalid' };

// Exchange a magic-link token for a JWT + refresh token. Persists nothing: the
// caller decides, since a switch has to end the current session first (SEC-15).
export async function verifyMagicToken(
  token: string,
  { nonce, confirm }: { nonce: string | null; confirm: boolean },
): Promise<VerifyResult> {
  const res = await fetch(`${API_BASE_URL}/auth/verify`, {
    method: 'POST',
    headers: ldJsonHeaders,
    body: JSON.stringify({ token, confirm, ...(nonce ? { nonce } : {}) }),
  });
  if (res.status === 409) {
    const data = (await res.json().catch(() => ({}))) as { email?: string };
    return data.email ? { kind: 'confirm', email: data.email } : { kind: 'invalid' };
  }
  if (!res.ok) {
    return { kind: 'invalid' };
  }
  const data = (await res.json().catch(() => ({}))) as TokenPair;
  if (!data.token) {
    return { kind: 'invalid' };
  }
  return { kind: 'tokens', jwt: data.token, refresh: data.refresh_token ?? null };
}

// Revoke the account's refresh tokens server-side on logout, while the JWT is
// still valid. Best-effort: the local session ends regardless.
export async function revokeSession(): Promise<void> {
  const jwt = getJwt();
  if (!jwt) return;
  await fetch(`${API_BASE_URL}/auth/logout`, {
    method: 'POST',
    headers: { ...ldJsonHeaders, Authorization: `Bearer ${jwt}` },
  }).catch(() => undefined);
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

// Statuses by which the server rejects the refresh token itself. Anything else
// (5xx during a deploy, 429, a proxy error page) says nothing about the session,
// and wiping on it would destroy the offline roadbook of a rider mid-trip.
const REJECTED_REFRESH_STATUSES = new Set([400, 401, 422]);

async function doRefresh(): Promise<boolean> {
  const refresh = getRefresh();
  if (refresh) {
    let res: Response;
    try {
      res = await fetch(`${API_BASE_URL}/auth/refresh`, {
        method: 'POST',
        headers: ldJsonHeaders,
        body: JSON.stringify({ refresh_token: refresh }),
      });
    } catch {
      return false;
    }
    if (res.ok) {
      const data = (await res.json().catch(() => ({}))) as TokenPair;
      if (data.token) {
        await setTokens(data.token, data.refresh_token ?? refresh);
        return true;
      }
    } else if (!REJECTED_REFRESH_STATUSES.has(res.status)) {
      return false;
    }
  }
  // Definitive failure (no refresh token, rejected, or malformed body): unregister
  // the push token while the JWT is still valid (the unregister call needs Authorization),
  // then wipe the dead session and signal AuthProvider so the UI redirects to
  // /login. Order matters: clearTokens() first would strip the Bearer and the
  // unregister call would 401, leaving the token alive server-side (#1125).
  await unregisterDeviceToken().catch(() => undefined);
  await clearTokens();
  await clearLocalAccountData();
  notifySessionInvalidated();
  return false;
}

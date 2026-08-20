import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { api } from '../api/client';
import { LD_JSON } from '../api/config';
import { registerDeviceToken, subscribeTokenRotation, unregisterDeviceToken } from '../notifications/push';
import { verifyMagicToken } from './authApi';
import { onSessionInvalidated } from './session';
import { clearTokens, loadTokens } from './tokens';

type AuthContextValue = {
  ready: boolean;
  authenticated: boolean;
  email: string | null;
  requestLink: (email: string) => Promise<boolean>;
  verify: (token: string) => Promise<boolean>;
  refreshEmail: () => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

// Best-effort push-token unregister for explicit logout, while the JWT is still
// valid. Out-of-band session invalidation unregisters in doRefresh (before it
// clears the tokens), not here — by the time onSessionInvalidated fires the JWT
// is already gone, so a DELETE here would 401 (#1125).
function dropPushToken(): Promise<void> {
  return unregisterDeviceToken().catch(() => undefined);
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [authenticated, setAuthenticated] = useState(false);
  const [email, setEmail] = useState<string | null>(null);
  // Bumped on every session teardown (logout / invalidation). An in-flight
  // GET /users/me captures the generation at call time and only writes back if it
  // still matches — so a response landing after logout can't resurrect `email`.
  const sessionGen = useRef(0);
  const endSession = useCallback(() => {
    sessionGen.current += 1;
    setAuthenticated(false);
  }, []);

  useEffect(() => {
    void (async () => {
      const { jwt } = await loadTokens();
      setAuthenticated(Boolean(jwt));
      setReady(true);
    })();
  }, []);

  // Resolve the account email once authenticated (the mobile app never sees it
  // otherwise — tokens carry no email). Uses GET /users/me: JWT Bearer, unlike
  // GET /auth/session which is cookie-only (web transport). Cleared on logout;
  // the cancel flag drops a response that lands after the effect re-ran (e.g. a
  // logout mid-flight), so a stale email can never overwrite the cleared state.
  useEffect(() => {
    if (!authenticated) {
      setEmail(null);
      return;
    }
    let cancelled = false;
    void (async () => {
      const { data } = await api.GET('/users/me', { headers: { Accept: LD_JSON } });
      if (!cancelled) {
        setEmail(data?.email ?? null);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [authenticated]);

  // Register this device's push token once authenticated (fresh login or a
  // restored session), and re-register on OS token rotation (#1125). Silent no-op
  // when push permission is absent.
  useEffect(() => {
    if (!authenticated) return;
    void registerDeviceToken();
    const rotation = subscribeTokenRotation();
    return () => rotation.remove();
  }, [authenticated]);

  // A refresh that definitively fails clears the tokens outside React (after
  // unregistering the push token, in doRefresh). Flip the state so the (tabs)
  // guard redirects to /login instead of 401'ing forever.
  useEffect(() => onSessionInvalidated(endSession), [endSession]);

  const requestLink = useCallback(async (email: string): Promise<boolean> => {
    const { response } = await api.POST('/auth/request-link', {
      body: { email },
      headers: { 'Content-Type': LD_JSON, Accept: LD_JSON },
    });
    return response.ok;
  }, []);

  const verify = useCallback(async (token: string): Promise<boolean> => {
    const ok = await verifyMagicToken(token);
    setAuthenticated(ok);
    return ok;
  }, []);

  // Re-read the account email from GET /users/me (JWT Bearer) — called after an
  // email change is committed so the account screen reflects the new address
  // without a re-login. (Not /auth/session, which is cookie-only web transport.)
  const refreshEmail = useCallback(async (): Promise<void> => {
    const gen = sessionGen.current;
    const { data } = await api.GET('/users/me', { headers: { Accept: LD_JSON } });
    // Drop a response that outlived its session (a logout raced this fetch).
    if (sessionGen.current === gen) {
      setEmail(data?.email ?? null);
    }
  }, []);

  const logout = useCallback(async (): Promise<void> => {
    // Unregister the push token while the JWT is still valid, so a shared device
    // stops receiving this account's pushes (#1125).
    await dropPushToken();
    await clearTokens();
    endSession();
  }, [endSession]);

  const value = useMemo<AuthContextValue>(
    () => ({ ready, authenticated, email, requestLink, verify, refreshEmail, logout }),
    [ready, authenticated, email, requestLink, verify, refreshEmail, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}

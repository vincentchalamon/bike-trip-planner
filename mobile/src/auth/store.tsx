import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api } from '../api/client';
import { LD_JSON } from '../api/config';
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

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [authenticated, setAuthenticated] = useState(false);
  const [email, setEmail] = useState<string | null>(null);

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

  // A refresh that definitively fails clears the tokens outside React; flip the
  // state so the (tabs) guard redirects to /login instead of 401'ing forever.
  useEffect(() => onSessionInvalidated(() => setAuthenticated(false)), []);

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

  // Re-read the account email from the session — called after an email change is
  // committed so the account screen reflects the new address without a re-login.
  const refreshEmail = useCallback(async (): Promise<void> => {
    const { data } = await api.GET('/auth/session', { headers: { Accept: LD_JSON } });
    setEmail(data?.email ?? null);
  }, []);

  const logout = useCallback(async (): Promise<void> => {
    await clearTokens();
    setAuthenticated(false);
  }, []);

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

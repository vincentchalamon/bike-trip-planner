import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api } from '../api/client';
import { LD_JSON } from '../api/config';
import { verifyMagicToken } from './authApi';
import { clearTokens, loadTokens } from './tokens';

type AuthContextValue = {
  ready: boolean;
  authenticated: boolean;
  requestLink: (email: string) => Promise<boolean>;
  verify: (token: string) => Promise<boolean>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [authenticated, setAuthenticated] = useState(false);

  useEffect(() => {
    void (async () => {
      const { jwt } = await loadTokens();
      setAuthenticated(Boolean(jwt));
      setReady(true);
    })();
  }, []);

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

  const logout = useCallback(async (): Promise<void> => {
    await clearTokens();
    setAuthenticated(false);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ ready, authenticated, requestLink, verify, logout }),
    [ready, authenticated, requestLink, verify, logout],
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

import * as SecureStore from 'expo-secure-store';

// SecureStore keys (alnum/._- only).
const JWT_KEY = 'btp_jwt';
const REFRESH_KEY = 'btp_refresh';

// In-memory mirror so the request middleware can read the JWT synchronously.
let jwtCache: string | null = null;
let refreshCache: string | null = null;

export async function loadTokens(): Promise<{ jwt: string | null; refresh: string | null }> {
  jwtCache = await SecureStore.getItemAsync(JWT_KEY);
  refreshCache = await SecureStore.getItemAsync(REFRESH_KEY);
  return { jwt: jwtCache, refresh: refreshCache };
}

export function getJwt(): string | null {
  return jwtCache;
}

export function getRefresh(): string | null {
  return refreshCache;
}

export async function setTokens(jwt: string, refresh: string | null): Promise<void> {
  jwtCache = jwt;
  await SecureStore.setItemAsync(JWT_KEY, jwt);
  if (refresh) {
    refreshCache = refresh;
    await SecureStore.setItemAsync(REFRESH_KEY, refresh);
  }
}

export async function clearTokens(): Promise<void> {
  jwtCache = null;
  refreshCache = null;
  await SecureStore.deleteItemAsync(JWT_KEY);
  await SecureStore.deleteItemAsync(REFRESH_KEY);
}

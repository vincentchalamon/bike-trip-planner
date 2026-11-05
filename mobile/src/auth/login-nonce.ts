import * as SecureStore from 'expo-secure-store';

// The secret that binds a magic link to this device (SEC-15): sent with the
// request-link, presented again at verify. A link opened without it (requested
// on another device, or forwarded by someone else) asks for a confirmation.
const LOGIN_NONCE_KEY = 'btp_login_nonce';

// Kept across requests: the backend issues no second link while one is pending,
// so a fresh secret on every request would orphan that link.
export async function ensureLoginNonce(): Promise<string> {
  const existing = await SecureStore.getItemAsync(LOGIN_NONCE_KEY);
  if (existing) return existing;
  const nonce = (globalThis.crypto.randomUUID() + globalThis.crypto.randomUUID()).replace(/-/g, '');
  await SecureStore.setItemAsync(LOGIN_NONCE_KEY, nonce);
  return nonce;
}

export function readLoginNonce(): Promise<string | null> {
  return SecureStore.getItemAsync(LOGIN_NONCE_KEY);
}

export function clearLoginNonce(): Promise<void> {
  return SecureStore.deleteItemAsync(LOGIN_NONCE_KEY);
}

/// <reference types="jest" />
const mockStore = new Map<string, string>();
jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async (key: string) => mockStore.get(key) ?? null),
  setItemAsync: jest.fn(async (key: string, value: string) => void mockStore.set(key, value)),
  deleteItemAsync: jest.fn(async (key: string) => void mockStore.delete(key)),
}));

import { clearLoginNonce, ensureLoginNonce, readLoginNonce } from './login-nonce';

beforeEach(() => mockStore.clear());

describe('login nonce (SEC-15)', () => {
  it('mints a 64-hex secret once and keeps it for later requests', async () => {
    const first = await ensureLoginNonce();

    expect(first).toMatch(/^[0-9a-f]{64}$/);
    expect(await ensureLoginNonce()).toBe(first);
    expect(await readLoginNonce()).toBe(first);
  });

  it('mints a new secret once the previous one is cleared', async () => {
    const first = await ensureLoginNonce();
    await clearLoginNonce();

    expect(await readLoginNonce()).toBeNull();
    expect(await ensureLoginNonce()).not.toBe(first);
  });
});

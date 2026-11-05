/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement, type ReactNode } from 'react';

jest.mock('../api/client', () => ({ api: { GET: jest.fn(), POST: jest.fn() } }));
jest.mock('./tokens', () => ({
  loadTokens: jest.fn(),
  clearTokens: jest.fn().mockResolvedValue(undefined),
  setTokens: jest.fn().mockResolvedValue(undefined),
  getJwt: jest.fn(),
}));
jest.mock('./authApi', () => ({
  verifyMagicToken: jest.fn(),
  revokeSession: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('./login-nonce', () => ({
  ensureLoginNonce: jest.fn().mockResolvedValue('device-nonce'),
  readLoginNonce: jest.fn().mockResolvedValue('device-nonce'),
  clearLoginNonce: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../notifications/native', () => ({
  cancelAllLocalNotifications: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('./session', () => ({ onSessionInvalidated: jest.fn(() => () => {}) }));
jest.mock('../store/trip-cache', () => ({
  clearAllTripCache: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../store/trips-list-cache', () => ({
  clearCachedTripList: jest.fn().mockResolvedValue(undefined),
}));
// The provider registers / unregisters the push token as a side effect (#1125);
// stub the push module so this stale-response-guard test never touches the native
// notifications layer (its own behaviour is covered by store.push.test.tsx).
jest.mock('../notifications/push', () => ({
  registerDeviceToken: jest.fn().mockResolvedValue(undefined),
  unregisterDeviceToken: jest.fn().mockResolvedValue(undefined),
  subscribeTokenRotation: jest.fn(() => ({ remove: jest.fn() })),
}));

import { api } from '../api/client';
import { clearTokens, loadTokens, setTokens } from './tokens';
import { revokeSession, verifyMagicToken } from './authApi';
import { clearLoginNonce } from './login-nonce';
import { unregisterDeviceToken } from '../notifications/push';
import { cancelAllLocalNotifications } from '../notifications/native';
import { clearAllTripCache } from '../store/trip-cache';
import { clearCachedTripList } from '../store/trips-list-cache';
import { AuthProvider, useAuth } from './store';

const mockClearAllTripCache = clearAllTripCache as jest.MockedFunction<typeof clearAllTripCache>;
const mockClearCachedTripList = clearCachedTripList as jest.MockedFunction<
  typeof clearCachedTripList
>;

type AuthContextValue = ReturnType<typeof useAuth>;

const mockGet = api.GET as jest.MockedFunction<typeof api.GET>;
const mockLoadTokens = loadTokens as jest.MockedFunction<typeof loadTokens>;

beforeEach(() => jest.clearAllMocks());

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

describe('AuthProvider stale-response guard (#1117)', () => {
  let captured: AuthContextValue;
  let renderer: ReturnType<typeof TestRenderer.create>;

  function Consumer() {
    captured = useAuth();
    return null;
  }

  function Wrapper({ children }: { children: ReactNode }) {
    return createElement(AuthProvider, null, children);
  }

  afterEach(() => act(() => renderer?.unmount()));

  it('drops a GET /users/me response that lands after logout(): email stays null', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    const pending = deferred<{ data?: { email?: string } }>();
    mockGet.mockReturnValue(pending.promise as never);

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });

    // Authenticated → the email effect fired GET /users/me (still in flight).
    expect(captured.authenticated).toBe(true);
    expect(mockGet).toHaveBeenCalledWith('/users/me', {
      headers: { Accept: 'application/ld+json' },
    });
    expect(captured.email).toBeNull();

    // Log out before the profile response comes back.
    await act(async () => {
      await captured.logout();
    });
    expect(captured.authenticated).toBe(false);

    // The stale profile response now resolves — the cancel guard must ignore it.
    await act(async () => {
      pending.resolve({ data: { email: 'stale@example.com' } });
      await pending.promise;
    });

    expect(captured.email).toBeNull();
  });

  it('purges the offline trip cache on logout (#1174)', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    mockGet.mockResolvedValue({ data: { email: 'me@example.com' } } as never);

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });

    expect(mockClearAllTripCache).not.toHaveBeenCalled();
    await act(async () => {
      await captured.logout();
    });
    expect(mockClearAllTripCache).toHaveBeenCalledTimes(1);
    // The trips-list snapshot (trip titles) is purged too (#1167 PII parity).
    expect(mockClearCachedTripList).toHaveBeenCalledTimes(1);
  });

  it('sets the email from GET /users/me while authenticated', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    mockGet.mockResolvedValue({ data: { email: 'me@example.com' } } as never);

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });

    expect(captured.email).toBe('me@example.com');
  });

  it('does not reject and leaves email null when the boot GET /users/me fails (offline cold start #1167)', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    mockGet.mockRejectedValue(new Error('fetch failed'));

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });

    // No 'Uncaught (in promise) fetch failed'; the effect swallows it.
    expect(captured.email).toBeNull();
  });

  it('does not reject and keeps the email when refreshEmail() fails offline (#1167)', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    mockGet.mockResolvedValueOnce({ data: { email: 'me@example.com' } } as never);
    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });
    expect(captured.email).toBe('me@example.com');

    mockGet.mockRejectedValueOnce(new Error('fetch failed'));
    await act(async () => {
      await expect(captured.refreshEmail()).resolves.toBeUndefined();
    });
    expect(captured.email).toBe('me@example.com');
  });

  it('drops a refreshEmail() response that lands after logout(): email stays null', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    // Mount effect resolves immediately with the current email.
    mockGet.mockResolvedValueOnce({ data: { email: 'me@example.com' } } as never);

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
    });
    expect(captured.email).toBe('me@example.com');

    // A manual refreshEmail() leaves its GET /users/me in flight.
    const pending = deferred<{ data?: { email?: string } }>();
    mockGet.mockReturnValueOnce(pending.promise as never);
    let refreshPromise!: Promise<void>;
    await act(async () => {
      refreshPromise = captured.refreshEmail();
    });

    // Log out before the refresh response comes back.
    await act(async () => {
      await captured.logout();
    });
    expect(captured.authenticated).toBe(false);

    // The stale refresh response resolves — the generation guard must ignore it,
    // so the address is not resurrected after the session ended.
    await act(async () => {
      pending.resolve({ data: { email: 'changed@example.com' } });
      await refreshPromise;
    });
    expect(captured.email).toBeNull();
  });
});

describe('AuthProvider magic-link binding and account switch (SEC-15)', () => {
  let captured: AuthContextValue;
  let renderer: ReturnType<typeof TestRenderer.create>;
  const mockVerify = verifyMagicToken as jest.MockedFunction<typeof verifyMagicToken>;
  const mockPost = api.POST as jest.Mock;

  function Consumer() {
    captured = useAuth();
    return null;
  }

  async function mount(jwt: string | null) {
    mockLoadTokens.mockResolvedValue({ jwt, refresh: jwt ? 'old-refresh' : null });
    mockGet.mockResolvedValue({ data: { email: 'current@example.com' } } as never);
    await act(async () => {
      renderer = TestRenderer.create(createElement(AuthProvider, null, createElement(Consumer)));
    });
  }

  afterEach(() => act(() => renderer?.unmount()));

  it('sends the device nonce with the link request', async () => {
    await mount(null);
    mockPost.mockResolvedValue({ response: { ok: true } });

    await act(async () => {
      await captured.requestLink('me@example.com');
    });

    expect(mockPost).toHaveBeenCalledWith(
      '/auth/request-link',
      expect.objectContaining({
        body: { email: 'me@example.com', nonce: 'device-nonce' },
      }),
    );
  });

  it('signs in with the device nonce when no session is open', async () => {
    await mount(null);
    mockVerify.mockResolvedValue({ kind: 'tokens', jwt: 'new-jwt', refresh: 'new-refresh' });

    let outcome: unknown;
    await act(async () => {
      outcome = await captured.verify('magic');
    });

    expect(outcome).toEqual({ kind: 'ok' });
    expect(mockVerify).toHaveBeenCalledWith('magic', { nonce: 'device-nonce', confirm: false });
    expect(setTokens).toHaveBeenCalledWith('new-jwt', 'new-refresh');
    expect(clearLoginNonce).toHaveBeenCalled();
    expect(captured.authenticated).toBe(true);
    // Nothing to tear down: there was no session.
    expect(revokeSession).not.toHaveBeenCalled();
  });

  it('never presents the nonce while signed in, and touches nothing on a confirmation', async () => {
    await mount('old-jwt');
    mockVerify.mockResolvedValue({ kind: 'confirm', email: 'other@example.com' });

    let outcome: unknown;
    await act(async () => {
      outcome = await captured.verify('magic');
    });

    expect(outcome).toEqual({ kind: 'confirm', email: 'other@example.com' });
    expect(mockVerify).toHaveBeenCalledWith('magic', { nonce: null, confirm: false });
    expect(setTokens).not.toHaveBeenCalled();
    expect(clearTokens).not.toHaveBeenCalled();
    expect(captured.authenticated).toBe(true);
  });

  it('ends the current session in full before storing the tokens of an accepted switch', async () => {
    await mount('old-jwt');
    mockVerify.mockResolvedValue({ kind: 'tokens', jwt: 'new-jwt', refresh: 'new-refresh' });

    await act(async () => {
      await captured.verify('magic', true);
    });

    expect(mockVerify).toHaveBeenCalledWith('magic', { nonce: null, confirm: true });
    const order = (fn: unknown) => (fn as jest.Mock).mock.invocationCallOrder[0] ?? Infinity;
    const stored = order(setTokens);
    for (const teardown of [
      unregisterDeviceToken,
      revokeSession,
      clearTokens,
      mockClearAllTripCache,
      cancelAllLocalNotifications,
    ]) {
      expect(order(teardown)).toBeLessThan(stored);
    }
    expect(setTokens).toHaveBeenCalledWith('new-jwt', 'new-refresh');
    expect(captured.authenticated).toBe(true);
  });

  it('keeps the current session when the switch is refused by the server', async () => {
    await mount('old-jwt');
    mockVerify.mockResolvedValue({ kind: 'invalid' });

    await act(async () => {
      await captured.verify('magic', true);
    });

    expect(clearTokens).not.toHaveBeenCalled();
    expect(captured.authenticated).toBe(true);
  });

  it('revokes the refresh tokens and cancels local reminders on logout (SEC-26)', async () => {
    await mount('old-jwt');

    await act(async () => {
      await captured.logout();
    });

    expect(revokeSession).toHaveBeenCalledTimes(1);
    expect((revokeSession as jest.Mock).mock.invocationCallOrder[0]).toBeLessThan(
      (clearTokens as jest.Mock).mock.invocationCallOrder[0] ?? 0,
    );
    expect(cancelAllLocalNotifications).toHaveBeenCalledTimes(1);
  });
});

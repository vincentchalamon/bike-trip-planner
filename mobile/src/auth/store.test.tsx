/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement, type ReactNode } from 'react';

jest.mock('../api/client', () => ({ api: { GET: jest.fn(), POST: jest.fn() } }));
jest.mock('./tokens', () => ({
  loadTokens: jest.fn(),
  clearTokens: jest.fn().mockResolvedValue(undefined),
  getJwt: jest.fn(),
}));
jest.mock('./authApi', () => ({ verifyMagicToken: jest.fn() }));
jest.mock('./session', () => ({ onSessionInvalidated: jest.fn(() => () => {}) }));
jest.mock('../store/trip-cache', () => ({ clearAllTripCache: jest.fn().mockResolvedValue(undefined) }));
// The provider registers / unregisters the push token as a side effect (#1125);
// stub the push module so this stale-response-guard test never touches the native
// notifications layer (its own behaviour is covered by store.push.test.tsx).
jest.mock('../notifications/push', () => ({
  registerDeviceToken: jest.fn().mockResolvedValue(undefined),
  unregisterDeviceToken: jest.fn().mockResolvedValue(undefined),
  subscribeTokenRotation: jest.fn(() => ({ remove: jest.fn() })),
}));

import { api } from '../api/client';
import { loadTokens } from './tokens';
import { clearAllTripCache } from '../store/trip-cache';
import { AuthProvider, useAuth } from './store';

const mockClearAllTripCache = clearAllTripCache as jest.MockedFunction<typeof clearAllTripCache>;

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
    expect(mockGet).toHaveBeenCalledWith('/users/me', { headers: { Accept: 'application/ld+json' } });
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
  });

  it('sets the email from GET /users/me while authenticated', async () => {
    mockLoadTokens.mockResolvedValue({ jwt: 'jwt', refresh: 'refresh' });
    mockGet.mockResolvedValue({ data: { email: 'me@example.com' } } as never);

    await act(async () => {
      renderer = TestRenderer.create(createElement(Wrapper, null, createElement(Consumer)));
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

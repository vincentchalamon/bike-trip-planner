/// <reference types="jest" />
jest.mock('../notifications/push', () => ({ unregisterDeviceToken: jest.fn() }));
jest.mock('./session', () => ({ notifySessionInvalidated: jest.fn() }));
jest.mock('./tokens', () => ({
  getJwt: jest.fn(() => 'jwt'),
  getRefresh: jest.fn(),
  setTokens: jest.fn(),
  clearTokens: jest.fn(),
}));
jest.mock('../store/local-account-data', () => ({ clearLocalAccountData: jest.fn() }));

import { revokeSession, verifyMagicToken } from './authApi';
import { getJwt, setTokens } from './tokens';

function respondWith(status: number, body: unknown): jest.Mock {
  const fetchMock = jest
    .fn()
    .mockResolvedValue({ ok: status < 400, status, json: async () => body });
  globalThis.fetch = fetchMock;
  return fetchMock;
}

function sentBody(fetchMock: jest.Mock): unknown {
  return JSON.parse((fetchMock.mock.calls[0]?.[1] as RequestInit).body as string);
}

beforeEach(() => jest.clearAllMocks());

describe('verifyMagicToken (SEC-15)', () => {
  it('returns the token pair without persisting it, and sends the nonce', async () => {
    const fetchMock = respondWith(200, { token: 'jwt', refresh_token: 'refresh' });

    const result = await verifyMagicToken('magic', { nonce: 'n', confirm: false });

    expect(result).toEqual({ kind: 'tokens', jwt: 'jwt', refresh: 'refresh' });
    expect(sentBody(fetchMock)).toEqual({ token: 'magic', confirm: false, nonce: 'n' });
    expect(setTokens).not.toHaveBeenCalled();
  });

  it('surfaces the confirmation the server asks for, with the account email', async () => {
    const fetchMock = respondWith(409, { email: 'owner@example.com' });

    const result = await verifyMagicToken('magic', { nonce: null, confirm: false });

    expect(result).toEqual({ kind: 'confirm', email: 'owner@example.com' });
    expect(sentBody(fetchMock)).toEqual({ token: 'magic', confirm: false });
  });

  it('treats a rejected link as invalid', async () => {
    respondWith(401, {});

    expect(await verifyMagicToken('magic', { nonce: null, confirm: true })).toEqual({
      kind: 'invalid',
    });
  });
});

describe('revokeSession', () => {
  it('posts /auth/logout with the current JWT', async () => {
    const fetchMock = respondWith(204, {});

    await revokeSession();

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/logout$/);
    expect((init as RequestInit).headers).toMatchObject({ Authorization: 'Bearer jwt' });
  });

  it('is a no-op without a JWT and never rejects offline', async () => {
    (getJwt as jest.Mock).mockReturnValueOnce(null);
    const fetchMock = respondWith(204, {});
    await revokeSession();
    expect(fetchMock).not.toHaveBeenCalled();

    globalThis.fetch = jest.fn().mockRejectedValue(new Error('offline'));
    await expect(revokeSession()).resolves.toBeUndefined();
  });
});

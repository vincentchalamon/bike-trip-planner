/// <reference types="jest" />

// Regression (#1125): on a definitively failed refresh, doRefresh must unregister
// the push token BEFORE clearing the tokens — the DELETE /users/me/device-tokens
// needs a valid JWT (Authorization), so clearing first would 401 and leave the
// token alive server-side. The order is asserted via a shared call-order array.
const order: string[] = [];

jest.mock('../notifications/push', () => ({
  unregisterDeviceToken: jest.fn(async () => {
    order.push('unregister');
  }),
}));
jest.mock('./session', () => ({ notifySessionInvalidated: jest.fn() }));
jest.mock('./tokens', () => ({
  getRefresh: jest.fn(() => 'refresh-token'),
  setTokens: jest.fn(async () => undefined),
  clearTokens: jest.fn(async () => {
    order.push('clear');
  }),
}));
jest.mock('../store/trip-cache', () => ({
  clearAllTripCache: jest.fn(async () => {
    order.push('purge-cache');
  }),
}));

import { refreshTokens } from './authApi';
import { unregisterDeviceToken } from '../notifications/push';
import { clearTokens } from './tokens';
import { clearAllTripCache } from '../store/trip-cache';

const unregister = unregisterDeviceToken as jest.Mock;
const clear = clearTokens as jest.Mock;
const purge = clearAllTripCache as jest.Mock;

beforeEach(() => {
  order.length = 0;
  jest.clearAllMocks();
  // A rejected refresh drives doRefresh down the definitive-failure branch.
  globalThis.fetch = jest.fn().mockResolvedValue({ ok: false, json: async () => ({}) });
});

describe('doRefresh definitive failure (#1125)', () => {
  it('unregisters the push token before clearing the tokens', async () => {
    const ok = await refreshTokens();

    expect(ok).toBe(false);
    expect(unregister).toHaveBeenCalledTimes(1);
    expect(clear).toHaveBeenCalledTimes(1);
    expect(order).toEqual(['unregister', 'clear', 'purge-cache']);
  });

  it('purges the offline trip cache on session invalidation (#1174)', async () => {
    await refreshTokens();
    expect(purge).toHaveBeenCalledTimes(1);
  });
});

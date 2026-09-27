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
jest.mock('../store/trips-list-cache', () => ({
  clearCachedTripList: jest.fn(async () => {
    order.push('purge-list');
  }),
}));

import { refreshTokens } from './authApi';
import { unregisterDeviceToken } from '../notifications/push';
import { notifySessionInvalidated } from './session';
import { clearTokens } from './tokens';
import { clearAllTripCache } from '../store/trip-cache';
import { clearCachedTripList } from '../store/trips-list-cache';

const unregister = unregisterDeviceToken as jest.Mock;
const clear = clearTokens as jest.Mock;
const purge = clearAllTripCache as jest.Mock;
const purgeList = clearCachedTripList as jest.Mock;
const invalidated = notifySessionInvalidated as jest.Mock;

function respondWith(status: number): void {
  globalThis.fetch = jest.fn().mockResolvedValue({ ok: status < 400, status, json: async () => ({}) });
}

beforeEach(() => {
  order.length = 0;
  jest.clearAllMocks();
  // A rejected refresh drives doRefresh down the definitive-failure branch.
  respondWith(401);
});

describe('doRefresh definitive failure (#1125)', () => {
  it('unregisters the push token before clearing the tokens', async () => {
    const ok = await refreshTokens();

    expect(ok).toBe(false);
    expect(unregister).toHaveBeenCalledTimes(1);
    expect(clear).toHaveBeenCalledTimes(1);
    expect(order).toEqual(['unregister', 'clear', 'purge-cache', 'purge-list']);
  });

  it('purges the offline trip cache and the cached trip list on session invalidation (#1174)', async () => {
    await refreshTokens();
    expect(purge).toHaveBeenCalledTimes(1);
    expect(purgeList).toHaveBeenCalledTimes(1);
  });

  it('treats a 400 as a rejected refresh token', async () => {
    respondWith(400);
    await refreshTokens();
    expect(invalidated).toHaveBeenCalledTimes(1);
  });

  it('treats a 200 without a token as a malformed answer', async () => {
    respondWith(200);
    await refreshTokens();
    expect(invalidated).toHaveBeenCalledTimes(1);
  });
});

// A 5xx (a deploy, a proxy down) says nothing about the session: wiping on it would
// throw away the offline roadbook of a rider mid-trip.
describe('doRefresh transient failure', () => {
  it.each([500, 502, 503, 429])('keeps the session and the offline data on a %i', async (status) => {
    respondWith(status);

    const ok = await refreshTokens();

    expect(ok).toBe(false);
    expect(order).toEqual([]);
    expect(invalidated).not.toHaveBeenCalled();
  });

  it('keeps the session and the offline data when the refresh request cannot be sent', async () => {
    globalThis.fetch = jest.fn().mockRejectedValue(new TypeError('Network request failed'));

    const ok = await refreshTokens();

    expect(ok).toBe(false);
    expect(order).toEqual([]);
    expect(invalidated).not.toHaveBeenCalled();
  });
});

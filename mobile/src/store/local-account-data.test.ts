/// <reference types="jest" />
jest.mock('./trip-cache', () => ({ clearAllTripCache: jest.fn().mockResolvedValue(undefined) }));
jest.mock('./trips-list-cache', () => ({
  clearCachedTripList: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../notifications/native', () => ({ cancelAllLocalNotifications: jest.fn() }));

import { clearLocalAccountData } from './local-account-data';
import { clearAllTripCache } from './trip-cache';
import { clearCachedTripList } from './trips-list-cache';
import { cancelAllLocalNotifications } from '../notifications/native';

const cancelAll = cancelAllLocalNotifications as jest.Mock;

beforeEach(() => jest.clearAllMocks());

describe('clearLocalAccountData', () => {
  it('cancels the reminders the ended session scheduled (SEC-26)', async () => {
    cancelAll.mockResolvedValue(undefined);

    await clearLocalAccountData();

    expect(clearAllTripCache).toHaveBeenCalled();
    expect(clearCachedTripList).toHaveBeenCalled();
    expect(cancelAll).toHaveBeenCalledTimes(1);
  });

  it('still completes when the notification layer fails', async () => {
    cancelAll.mockRejectedValue(new Error('native'));

    await expect(clearLocalAccountData()).resolves.toBeUndefined();
  });
});

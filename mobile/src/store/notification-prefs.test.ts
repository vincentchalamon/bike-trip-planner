/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import {
  NOTIFICATION_DEFAULTS,
  selectActiveCount,
  useNotificationPrefs,
} from './notification-prefs';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
}));

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

beforeEach(() => {
  getItem.mockReset();
  setItem.mockReset();
  useNotificationPrefs.setState({ enabled: { ...NOTIFICATION_DEFAULTS }, hydrated: false });
});

describe('useNotificationPrefs', () => {
  it('ships the exact category defaults', () => {
    expect(useNotificationPrefs.getState().enabled).toEqual({
      weatherSafety: true,
      analysisDone: true,
      offlineNotReady: true,
      tripNoDate: true,
      zoneOpening: false,
    });
  });

  it('counts four active categories by default (zoneOpening off)', () => {
    expect(selectActiveCount(useNotificationPrefs.getState())).toBe(4);
  });

  it('persists the whole record on setEnabled', () => {
    useNotificationPrefs.getState().setEnabled('zoneOpening', true);
    expect(useNotificationPrefs.getState().enabled.zoneOpening).toBe(true);
    expect(selectActiveCount(useNotificationPrefs.getState())).toBe(5);
    expect(setItem).toHaveBeenCalledWith(
      'btp_notification_prefs',
      JSON.stringify({
        weatherSafety: true,
        analysisDone: true,
        offlineNotReady: true,
        tripNoDate: true,
        zoneOpening: true,
      }),
    );
  });

  it('toggle flips a single category', () => {
    useNotificationPrefs.getState().toggle('weatherSafety');
    expect(useNotificationPrefs.getState().enabled.weatherSafety).toBe(false);
    expect(selectActiveCount(useNotificationPrefs.getState())).toBe(3);
  });

  it('load merges the stored payload over defaults once', async () => {
    getItem.mockResolvedValue(JSON.stringify({ weatherSafety: false, zoneOpening: true }));
    await useNotificationPrefs.getState().load();
    expect(useNotificationPrefs.getState()).toMatchObject({
      hydrated: true,
      enabled: {
        weatherSafety: false,
        analysisDone: true,
        offlineNotReady: true,
        tripNoDate: true,
        zoneOpening: true,
      },
    });

    getItem.mockResolvedValue(JSON.stringify({ analysisDone: false }));
    await useNotificationPrefs.getState().load();
    // Already hydrated: second load is a no-op, storage is not re-read.
    expect(getItem).toHaveBeenCalledTimes(1);
    expect(useNotificationPrefs.getState().enabled.analysisDone).toBe(true);
  });

  it('load falls back to defaults for a malformed payload', async () => {
    getItem.mockResolvedValue('not json');
    await useNotificationPrefs.getState().load();
    expect(useNotificationPrefs.getState().enabled).toEqual(NOTIFICATION_DEFAULTS);
  });
});

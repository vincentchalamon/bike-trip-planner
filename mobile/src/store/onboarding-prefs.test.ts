/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import { useOnboarding } from './onboarding-prefs';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
}));

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

beforeEach(() => {
  getItem.mockReset();
  setItem.mockReset();
  useOnboarding.setState({ seen: false, hydrated: false });
});

describe('useOnboarding', () => {
  it('defaults to not-seen before hydration', () => {
    expect(useOnboarding.getState()).toMatchObject({ seen: false, hydrated: false });
  });

  it('load hydrates the stored "seen" flag once', async () => {
    getItem.mockResolvedValue('true');
    await useOnboarding.getState().load();
    expect(useOnboarding.getState()).toMatchObject({ seen: true, hydrated: true });

    getItem.mockResolvedValue(null);
    await useOnboarding.getState().load();
    // Already hydrated: the second load is a no-op, the stored value is not re-read.
    expect(getItem).toHaveBeenCalledTimes(1);
    expect(useOnboarding.getState().seen).toBe(true);
  });

  it('load treats an unset flag as not-seen (fresh install shows the tour)', async () => {
    getItem.mockResolvedValue(null);
    await useOnboarding.getState().load();
    expect(useOnboarding.getState()).toMatchObject({ seen: false, hydrated: true });
  });

  it('load survives a storage read failure — shows the tour, never throws', async () => {
    getItem.mockRejectedValue(new Error('SecureStore unavailable'));
    await expect(useOnboarding.getState().load()).resolves.toBeUndefined();
    expect(useOnboarding.getState()).toMatchObject({ seen: false, hydrated: true });
  });

  it('markSeen persists the flag', () => {
    useOnboarding.getState().markSeen();
    expect(useOnboarding.getState().seen).toBe(true);
    expect(setItem).toHaveBeenCalledWith('btp_onboarding_seen', 'true');
  });
});

/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import { useMapPrefs } from './map-prefs';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
}));

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

beforeEach(() => {
  getItem.mockReset();
  setItem.mockReset();
  useMapPrefs.setState({ base: 'map', hydrated: false });
});

describe('useMapPrefs', () => {
  it('defaults to the map base', () => {
    expect(useMapPrefs.getState().base).toBe('map');
  });

  it('persists the choice on setBase', () => {
    useMapPrefs.getState().setBase('satellite');
    expect(useMapPrefs.getState().base).toBe('satellite');
    expect(setItem).toHaveBeenCalledWith('btp_map_base', 'satellite');
  });

  it('load hydrates the stored satellite choice once', async () => {
    getItem.mockResolvedValue('satellite');
    await useMapPrefs.getState().load();
    expect(useMapPrefs.getState()).toMatchObject({ base: 'satellite', hydrated: true });

    getItem.mockResolvedValue('map');
    await useMapPrefs.getState().load();
    // Already hydrated: the second load is a no-op, the stored value is not re-read.
    expect(getItem).toHaveBeenCalledTimes(1);
    expect(useMapPrefs.getState().base).toBe('satellite');
  });

  it('load falls back to map for an unknown stored value', async () => {
    getItem.mockResolvedValue(null);
    await useMapPrefs.getState().load();
    expect(useMapPrefs.getState().base).toBe('map');
  });
});

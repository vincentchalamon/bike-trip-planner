/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import { useThemePrefs } from './theme-prefs';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
}));

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

beforeEach(() => {
  getItem.mockReset();
  setItem.mockReset();
  useThemePrefs.setState({ mode: 'system', hydrated: false });
});

describe('useThemePrefs', () => {
  it('defaults to the system mode', () => {
    expect(useThemePrefs.getState().mode).toBe('system');
  });

  it('persists the choice on setMode', () => {
    useThemePrefs.getState().setMode('dark');
    expect(useThemePrefs.getState().mode).toBe('dark');
    expect(setItem).toHaveBeenCalledWith('btp_theme_mode', 'dark');
  });

  it('load hydrates the stored choice once', async () => {
    getItem.mockResolvedValue('light');
    await useThemePrefs.getState().load();
    expect(useThemePrefs.getState()).toMatchObject({ mode: 'light', hydrated: true });

    getItem.mockResolvedValue('dark');
    await useThemePrefs.getState().load();
    // Already hydrated: the second load is a no-op, the stored value is not re-read.
    expect(getItem).toHaveBeenCalledTimes(1);
    expect(useThemePrefs.getState().mode).toBe('light');
  });

  it('load falls back to system for an unknown stored value', async () => {
    getItem.mockResolvedValue('neon');
    await useThemePrefs.getState().load();
    expect(useThemePrefs.getState().mode).toBe('system');
  });
});

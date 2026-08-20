import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';

// User theme override. `system` defers to the OS colour scheme (the historical
// behaviour); `light`/`dark` force a scheme regardless of the device setting.
export type ThemeMode = 'system' | 'light' | 'dark';

export const THEME_MODES: readonly ThemeMode[] = ['system', 'light', 'dark'];

// SecureStore key (alnum/._- only). Rides on SecureStore like the base-map choice
// (map-prefs) rather than pulling in AsyncStorage.
const KEY = 'btp_theme_mode';

interface ThemePrefsState {
  mode: ThemeMode;
  hydrated: boolean;
  setMode: (mode: ThemeMode) => void;
  load: () => Promise<void>;
}

// Persisted theme override. The write is fire-and-forget so `setMode` stays
// synchronous; `load()` hydrates once at ThemeProvider mount.
export const useThemePrefs = create<ThemePrefsState>((set, get) => ({
  mode: 'system',
  hydrated: false,
  setMode: (mode) => {
    set({ mode });
    void SecureStore.setItemAsync(KEY, mode);
  },
  load: async () => {
    if (get().hydrated) return;
    const stored = await SecureStore.getItemAsync(KEY);
    set({
      mode: THEME_MODES.includes(stored as ThemeMode) ? (stored as ThemeMode) : 'system',
      hydrated: true,
    });
  },
}));

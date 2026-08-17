import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';
import type { MapBase } from '../components/map/map-utils';

// SecureStore key (alnum/._- only). SecureStore is the only KV the mobile app
// ships (it also backs the auth tokens), so the base-map choice rides along
// there rather than pulling in AsyncStorage.
const KEY = 'btp_map_base';

interface MapPrefsState {
  base: MapBase;
  hydrated: boolean;
  setBase: (base: MapBase) => void;
  toggle: () => void;
  load: () => Promise<void>;
}

// Persisted base-map choice (Positron plan vs Esri satellite). The write is
// fire-and-forget so the toggle stays synchronous; `load()` hydrates once.
export const useMapPrefs = create<MapPrefsState>((set, get) => ({
  base: 'map',
  hydrated: false,
  setBase: (base) => {
    set({ base });
    void SecureStore.setItemAsync(KEY, base);
  },
  toggle: () => get().setBase(get().base === 'satellite' ? 'map' : 'satellite'),
  load: async () => {
    if (get().hydrated) return;
    const stored = await SecureStore.getItemAsync(KEY);
    set({ base: stored === 'satellite' ? 'satellite' : 'map', hydrated: true });
  },
}));

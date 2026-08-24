import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';

// SecureStore key (alnum/._- only). Rides on SecureStore like the theme/base-map
// choices rather than pulling in AsyncStorage — it's the only KV the app ships.
const KEY = 'btp_onboarding_seen';

interface OnboardingState {
  // Whether the guided tour has already been shown (persisted, once per install).
  seen: boolean;
  hydrated: boolean;
  markSeen: () => void;
  load: () => Promise<void>;
}

// Persisted "guided tour already shown" flag. `markSeen` is fire-and-forget so
// dismissing the tour stays synchronous; `load()` hydrates once at mount.
export const useOnboarding = create<OnboardingState>((set, get) => ({
  seen: false,
  hydrated: false,
  markSeen: () => {
    set({ seen: true });
    void SecureStore.setItemAsync(KEY, 'true');
  },
  load: async () => {
    if (get().hydrated) return;
    try {
      const stored = await SecureStore.getItemAsync(KEY);
      set({ seen: stored === 'true', hydrated: true });
    } catch {
      // Storage unavailable (fresh install, cleared store): show the tour rather
      // than crash — it's a one-time nicety, never critical state.
      set({ seen: false, hydrated: true });
    }
  },
}));

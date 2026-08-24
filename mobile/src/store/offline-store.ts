import { create } from 'zustand';

interface OfflineState {
  isOnline: boolean;
  setOnline: (value: boolean) => void;
  // Whether the API answered recently: false after a network error or a 5xx,
  // true again after any response that reached the backend (even a 4xx). Lets the
  // app degrade to read-only when the device has connectivity but the API is down
  // (#1166), distinct from `isOnline` (device connectivity via NetInfo).
  apiReachable: boolean;
  setApiReachable: (value: boolean) => void;
}

// Connectivity flag consulted by the mutation gate (see gating.ts). Mirrors the
// web `offline-store` but stays transport-agnostic: a later sprint wires it to
// React Native's NetInfo listener; until then it defaults online. Kept in its
// own store (not trip-store) so it survives trip switches and can be read from
// the mutation runners without a trip loaded.
export const useOfflineStore = create<OfflineState>((set) => ({
  isOnline: true,
  setOnline: (value) => set({ isOnline: value }),
  apiReachable: true,
  setApiReachable: (value) =>
    set((s) => (s.apiReachable === value ? s : { apiReachable: value })),
}));

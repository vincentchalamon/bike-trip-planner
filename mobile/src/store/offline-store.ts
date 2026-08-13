import { create } from 'zustand';

interface OfflineState {
  isOnline: boolean;
  setOnline: (value: boolean) => void;
}

// Connectivity flag consulted by the mutation gate (see gating.ts). Mirrors the
// web `offline-store` but stays transport-agnostic: a later sprint wires it to
// React Native's NetInfo listener; until then it defaults online. Kept in its
// own store (not trip-store) so it survives trip switches and can be read from
// the mutation runners without a trip loaded.
export const useOfflineStore = create<OfflineState>((set) => ({
  isOnline: true,
  setOnline: (value) => set({ isOnline: value }),
}));

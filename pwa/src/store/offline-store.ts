"use client";

import { create } from "zustand";

interface OfflineState {
  isOnline: boolean;
  setOnline: (value: boolean) => void;
}

/**
 * Zustand store for offline state management.
 *
 * Tracks the browser's online/offline status via `navigator.onLine` and
 * `online`/`offline` events (see {@link OfflineBanner}), used to guard
 * mutations (magic-link / GPX upload) while offline.
 *
 * Front-side persistence of completed trips ("Mes voyages sauvegardés") was
 * removed in #649: those snapshots never surfaced in the API-backed "Mes
 * voyages" list and only created confusion. Trips now live solely server-side.
 */
export const useOfflineStore = create<OfflineState>()((set) => ({
  isOnline: typeof navigator !== "undefined" ? navigator.onLine : true,
  setOnline: (value) => set({ isOnline: value }),
}));

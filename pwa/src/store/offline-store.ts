"use client";

import { create } from "zustand";
import { get as idbGet, set as idbSet } from "idb-keyval";
import type { StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";

/**
 * A snapshot of a completed trip persisted to IndexedDB for offline consultation.
 */
export interface SavedTrip {
  id: string;
  title: string;
  sourceUrl: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceType: string | null;
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  departureHour: number;
  enabledAccommodationTypes: AccommodationType[];
  stages: StageData[];
  savedAt: string;
}

const IDB_KEY = "offline_saved_trips";
const MAX_SAVED_TRIPS = 20;

interface OfflineState {
  isOnline: boolean;
  savedTrips: SavedTrip[];

  setOnline: (value: boolean) => void;
  /** Persist a completed trip to IndexedDB (upsert by trip id, capped at MAX_SAVED_TRIPS). */
  saveTrip: (trip: SavedTrip) => Promise<void>;
  /** Load all saved trips from IndexedDB into the in-memory state. */
  loadSavedTrips: () => Promise<void>;
}

/**
 * Zustand store for offline state management.
 *
 * Tracks the browser's online/offline status via `navigator.onLine` and
 * `online`/`offline` events (see {@link OfflineBanner}).
 *
 * Completed trips are persisted to IndexedDB via `idb-keyval` on
 * `trip_complete` Mercure events for future offline consultation (see #51).
 *
 * **Tile caching:** Map tile caching is not implemented in this version.
 * A future iteration could use a Service Worker with a cache-first strategy
 * for raster/vector tiles (see issue backlog).
 */
export const useOfflineStore = create<OfflineState>()((set) => ({
  isOnline: typeof navigator !== "undefined" ? navigator.onLine : true,
  savedTrips: [],

  setOnline: (value) => set({ isOnline: value }),

  saveTrip: async (trip) => {
    try {
      const existing = (await idbGet<SavedTrip[]>(IDB_KEY)) ?? [];
      const updated = [trip, ...existing.filter((t) => t.id !== trip.id)].slice(
        0,
        MAX_SAVED_TRIPS,
      );
      await idbSet(IDB_KEY, updated);
      set({ savedTrips: updated });
    } catch {
      // IndexedDB write failed — degrade gracefully
    }
  },

  loadSavedTrips: async () => {
    try {
      const trips = (await idbGet<SavedTrip[]>(IDB_KEY)) ?? [];
      set({ savedTrips: trips });
    } catch {
      // IndexedDB read failed — degrade gracefully
    }
  },
}));

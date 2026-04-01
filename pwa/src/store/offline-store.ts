"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import { get as idbGet, set as idbSet, del as idbDel } from "idb-keyval";
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

interface OfflineState {
  isOnline: boolean;
  savedTrips: SavedTrip[];

  setOnline: (value: boolean) => void;
  /** Load saved trips from IndexedDB into memory. Call once on app init. */
  loadSavedTrips: () => Promise<void>;
  /** Persist a completed trip to IndexedDB (upsert by trip id). */
  saveTrip: (trip: SavedTrip) => Promise<void>;
  /** Remove a saved trip from IndexedDB by id. */
  removeSavedTrip: (tripId: string) => Promise<void>;
}

/**
 * Zustand store for offline state management.
 *
 * Tracks the browser's online/offline status via `navigator.onLine` and
 * `online`/`offline` events (see {@link OfflineBanner}).
 *
 * Maintains a list of {@link SavedTrip} snapshots persisted in IndexedDB
 * via `idb-keyval`. Completed trips are saved on `trip_complete` Mercure
 * events for offline consultation.
 *
 * **Tile caching:** Map tile caching is not implemented in this version.
 * A future iteration could use a Service Worker with a cache-first strategy
 * for raster/vector tiles (see issue backlog).
 */
export const useOfflineStore = create<OfflineState>()(
  immer((set, get) => ({
    isOnline: typeof navigator !== "undefined" ? navigator.onLine : true,
    savedTrips: [],

    setOnline: (value) =>
      set((state) => {
        state.isOnline = value;
      }),

    loadSavedTrips: async () => {
      try {
        const trips = await idbGet<SavedTrip[]>(IDB_KEY);
        if (trips && Array.isArray(trips)) {
          set((state) => {
            state.savedTrips = trips;
          });
        }
      } catch {
        // IndexedDB unavailable — degrade gracefully
      }
    },

    saveTrip: async (trip) => {
      const existing = get().savedTrips;
      const updated = [trip, ...existing.filter((t) => t.id !== trip.id)];
      set((state) => {
        state.savedTrips = updated;
      });
      try {
        await idbSet(IDB_KEY, updated);
      } catch {
        // IndexedDB write failed — data remains in memory for the session
      }
    },

    removeSavedTrip: async (tripId) => {
      const updated = get().savedTrips.filter((t) => t.id !== tripId);
      set((state) => {
        state.savedTrips = updated;
      });
      try {
        if (updated.length === 0) {
          await idbDel(IDB_KEY);
        } else {
          await idbSet(IDB_KEY, updated);
        }
      } catch {
        // IndexedDB delete failed — in-memory state is already updated
      }
    },
  })),
);

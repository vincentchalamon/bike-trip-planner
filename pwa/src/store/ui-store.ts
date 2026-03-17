"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";

interface UiState {
  isProcessing: boolean;
  isAccommodationScanning: boolean;
  sseConnected: boolean;
  expandedCalendar: boolean;
  isConfigPanelOpen: boolean;
  error: { type: string; message: string } | null;
  activeDayNumber: number | null;
  /** Index (into active stages) of the stage currently focused on the map. null = global view. */
  focusedMapStageIndex: number | null;

  setProcessing: (value: boolean) => void;
  setAccommodationScanning: (value: boolean) => void;
  setSseConnected: (value: boolean) => void;
  setExpandedCalendar: (value: boolean) => void;
  setConfigPanelOpen: (value: boolean) => void;
  setError: (error: { type: string; message: string } | null) => void;
  setActiveDayNumber: (dayNumber: number | null) => void;
  setFocusedMapStageIndex: (index: number | null) => void;
}

/**
 * Zustand store for transient UI state (not tied to trip data).
 *
 * Tracks global UI concerns that are orthogonal to the trip model:
 * - `isProcessing` — whether an async backend computation is in flight
 * - `sseConnected` — whether the Mercure SSE connection is active
 * - `expandedCalendar` — whether the date picker panel is open
 * - `isConfigPanelOpen` — whether the configuration sidebar is open
 * - `error` — global error banner state (type + message), or `null`
 * - `activeDayNumber` — the day number currently highlighted across the UI
 *   (progress bar, map, elevation profile); `null` means no active day
 * - `focusedMapStageIndex` — which active-stage index is currently zoomed on
 *   the map; `null` means global view (all stages visible)
 *
 * This store is intentionally separate from {@link useTripStore} to avoid
 * unnecessary re-renders of trip-dependent components when only UI flags change.
 *
 * In test environments the store is exposed on `window.__zustand_ui_store` so
 * that Playwright tests can call `setState` directly without relying on UI
 * interactions.
 */
export const useUiStore = create<UiState>()(
  immer((set) => ({
    isProcessing: false,
    isAccommodationScanning: false,
    sseConnected: false,
    expandedCalendar: false,
    isConfigPanelOpen: false,
    error: null,
    activeDayNumber: null,
    focusedMapStageIndex: null,

    setProcessing: (value) =>
      set((state) => {
        state.isProcessing = value;
      }),

    setAccommodationScanning: (value) =>
      set((state) => {
        state.isAccommodationScanning = value;
      }),

    setSseConnected: (value) =>
      set((state) => {
        state.sseConnected = value;
      }),

    setExpandedCalendar: (value) =>
      set((state) => {
        state.expandedCalendar = value;
      }),

    setConfigPanelOpen: (value) =>
      set((state) => {
        state.isConfigPanelOpen = value;
      }),

    setError: (error) =>
      set((state) => {
        state.error = error;
      }),

    setActiveDayNumber: (dayNumber) =>
      set((state) => {
        state.activeDayNumber = dayNumber;
      }),

    setFocusedMapStageIndex: (index) =>
      set((state) => {
        state.focusedMapStageIndex = index;
      }),
  })),
);

// Expose the store for E2E tests so Playwright can manipulate UI state directly
// without relying on user interactions.
if (typeof window !== "undefined") {
  (
    window as Window & { __zustand_ui_store?: typeof useUiStore }
  ).__zustand_ui_store = useUiStore;
}

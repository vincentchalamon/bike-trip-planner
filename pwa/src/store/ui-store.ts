"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";

interface UiState {
  isProcessing: boolean;
  sseConnected: boolean;
  expandedCalendar: boolean;
  error: { type: string; message: string } | null;

  setProcessing: (value: boolean) => void;
  setSseConnected: (value: boolean) => void;
  setExpandedCalendar: (value: boolean) => void;
  setError: (error: { type: string; message: string } | null) => void;
}

/**
 * Zustand store for transient UI state (not tied to trip data).
 *
 * Tracks global UI concerns that are orthogonal to the trip model:
 * - `isProcessing` — whether an async backend computation is in flight
 * - `sseConnected` — whether the Mercure SSE connection is active
 * - `expandedCalendar` — whether the date picker panel is open
 * - `error` — global error banner state (type + message), or `null`
 *
 * This store is intentionally separate from {@link useTripStore} to avoid
 * unnecessary re-renders of trip-dependent components when only UI flags change.
 */
export const useUiStore = create<UiState>()(
  immer((set) => ({
    isProcessing: false,
    sseConnected: false,
    expandedCalendar: false,
    error: null,

    setProcessing: (value) =>
      set((state) => {
        state.isProcessing = value;
      }),

    setSseConnected: (value) =>
      set((state) => {
        state.sseConnected = value;
      }),

    setExpandedCalendar: (value) =>
      set((state) => {
        state.expandedCalendar = value;
      }),

    setError: (error) =>
      set((state) => {
        state.error = error;
      }),
  })),
);

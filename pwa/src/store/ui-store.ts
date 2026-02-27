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

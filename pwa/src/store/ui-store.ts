"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import { enableMapSet } from "immer";

// Required for Immer to allow mutating Set/Map drafts (used by completedSteps).
enableMapSet();

export type ViewMode = "timeline" | "map" | "split";

/**
 * The 4 sequential steps of the trip planning workflow.
 *
 * - `preparation` — user inputs a route URL or uploads a GPX file
 * - `preview`     — the route has been parsed and stages are displayed
 * - `analysis`    — backend async computation is running (system step, never clickable)
 * - `my_trip`     — computation complete; the user explores their trip
 */
export type StepId = "preparation" | "preview" | "analysis" | "my_trip";

export const STEPS: StepId[] = [
  "preparation",
  "preview",
  "analysis",
  "my_trip",
];

interface UiState {
  isProcessing: boolean;
  isAccommodationScanning: boolean;
  sseConnected: boolean;
  expandedCalendar: boolean;
  isConfigPanelOpen: boolean;
  /** Whether the keyboard shortcuts help modal is open. */
  isHelpModalOpen: boolean;
  error: { type: string; message: string } | null;
  activeDayNumber: number | null;
  /** Index (into active stages) of the stage currently focused on the map. null = global view. */
  focusedMapStageIndex: number | null;
  /** Currently hovered accommodation (from timeline or map marker). null = no hover. */
  hoveredAccommodation: { stageIndex: number; accIndex: number } | null;
  /**
   * Current view mode for the trip planner layout.
   * - "timeline" — timeline only (mobile default)
   * - "map" — map only
   * - "split" — timeline + map side by side (desktop default)
   */
  viewMode: ViewMode;
  /** Section to scroll to when ConfigPanel opens (e.g. from TripSummary chips). */
  configPanelFocusSection: "dates" | "pacing" | null;
  /**
   * Current step in the 4-step trip planning workflow.
   * Guards prevent navigating to "analysis" or backwards from "my_trip".
   */
  currentStep: StepId;
  /** Set of steps the user has already completed (enables backwards navigation). */
  completedSteps: Set<StepId>;
  /**
   * Whether the user has explicitly launched the Phase 2 analysis via
   * `POST /trips/{id}/analyze` (Acte 2). Until this is `true`, the UI stays
   * on the "preview" screen (Acte 1.5) where the user can inspect the raw
   * route and tweak parameters before committing to the full enrichment.
   */
  hasAnalysisStarted: boolean;

  setProcessing: (value: boolean) => void;
  setAccommodationScanning: (value: boolean) => void;
  setSseConnected: (value: boolean) => void;
  setExpandedCalendar: (value: boolean) => void;
  setConfigPanelOpen: (value: boolean) => void;
  setHelpModalOpen: (value: boolean) => void;
  setError: (error: { type: string; message: string } | null) => void;
  setActiveDayNumber: (dayNumber: number | null) => void;
  setFocusedMapStageIndex: (index: number | null) => void;
  setHoveredAccommodation: (
    value: { stageIndex: number; accIndex: number } | null,
  ) => void;
  setViewMode: (mode: ViewMode) => void;
  setConfigPanelFocusSection: (section: "dates" | "pacing" | null) => void;
  openConfigPanelAt: (section: "dates" | "pacing") => void;
  /**
   * Navigate to a specific step.
   * Guards:
   * - Forward navigation (including programmatic advance to "analysis") is always allowed.
   * - "analysis" cannot be navigated back to (system-only, forward-only).
   * - Backwards navigation from "my_trip" is blocked (Act 3 lock).
   * - Backwards navigation to other steps requires the step to be completed.
   */
  goToStep: (step: StepId) => void;
  /** Mark a step as completed (enabling backwards navigation to it). */
  completeStep: (step: StepId) => void;
  /** Reset the stepper to "preparation" and clear completed steps (called on `clearTrip`). */
  resetStepper: () => void;
  /** Flip {@link hasAnalysisStarted}. Called by the preview screen when the user
   * confirms they want to launch the full enrichment pipeline. */
  setAnalysisStarted: (value: boolean) => void;
}

/**
 * Zustand store for transient UI state (not tied to trip data).
 *
 * Tracks global UI concerns that are orthogonal to the trip model:
 * - `isProcessing` — whether an async backend computation is in flight
 * - `sseConnected` — whether the Mercure SSE connection is active
 * - `expandedCalendar` — whether the date picker panel is open
 * - `isConfigPanelOpen` — whether the configuration sidebar is open
 * - `isHelpModalOpen` — whether the keyboard shortcuts help modal is open
 * - `error` — global error banner state (type + message), or `null`
 * - `activeDayNumber` — the day number currently highlighted across the UI
 *   (progress bar, map, elevation profile); `null` means no active day
 * - `focusedMapStageIndex` — which active-stage index is currently zoomed on
 *   the map; `null` means global view (all stages visible)
 * - `viewMode` — current layout mode: "timeline", "map", or "split"
 * - `currentStep` — active step in the 4-step workflow (preparation → preview → analysis → my_trip)
 * - `completedSteps` — set of already-visited steps (enables backwards navigation)
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
    isHelpModalOpen: false,
    error: null,
    activeDayNumber: null,
    focusedMapStageIndex: null,
    hoveredAccommodation: null,
    // Default: "split". On mobile the ViewModeToggle component will override to "timeline"
    // on first render via a useEffect that detects the viewport width.
    viewMode: "split",
    configPanelFocusSection: null,
    currentStep: "preparation",
    completedSteps: new Set<StepId>(),
    hasAnalysisStarted: false,

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

    setHelpModalOpen: (value) =>
      set((state) => {
        state.isHelpModalOpen = value;
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

    setHoveredAccommodation: (value) =>
      set((state) => {
        state.hoveredAccommodation = value;
      }),

    setViewMode: (mode) =>
      set((state) => {
        state.viewMode = mode;
      }),

    setConfigPanelFocusSection: (section) =>
      set((state) => {
        state.configPanelFocusSection = section;
      }),

    openConfigPanelAt: (section) =>
      set((state) => {
        state.configPanelFocusSection = section;
        state.isConfigPanelOpen = true;
      }),

    goToStep: (step) =>
      set((state) => {
        // Once at "my_trip", no navigation is possible (Act 3 lock)
        if (state.currentStep === "my_trip") return;
        const currentIdx = STEPS.indexOf(state.currentStep);
        const targetIdx = STEPS.indexOf(step);
        // Forward navigation: always allowed (system can advance to "analysis")
        if (targetIdx > currentIdx) {
          state.currentStep = step;
          return;
        }
        // Backwards navigation: "analysis" is a system step and can never be
        // navigated back to; other completed steps allow backwards navigation.
        if (step !== "analysis" && state.completedSteps.has(step)) {
          state.currentStep = step;
        }
      }),

    completeStep: (step) =>
      set((state) => {
        state.completedSteps.add(step);
      }),

    resetStepper: () =>
      set((state) => {
        state.currentStep = "preparation";
        state.completedSteps = new Set<StepId>();
        state.hasAnalysisStarted = false;
      }),

    setAnalysisStarted: (value) =>
      set((state) => {
        state.hasAnalysisStarted = value;
      }),
  })),
);

// Expose the store for E2E tests so Playwright can manipulate UI state directly
// without relying on user interactions.
if (typeof window !== "undefined" && process.env.NODE_ENV !== "production") {
  (
    window as Window & { __zustand_ui_store?: typeof useUiStore }
  ).__zustand_ui_store = useUiStore;
}

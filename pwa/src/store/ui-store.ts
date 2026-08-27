"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";

export type ViewMode = "timeline" | "map" | "split";

/**
 * Per-block async enrichment status (ADR-043, PR4-front).
 *
 * Mirrors the backend `weatherStatus` field exposed on
 * `GET /trips/{id}/detail`. Structural computation now runs synchronously
 * (status `draft` → `ready`); weather is the only remaining asynchronous
 * block, rendered with its own spinner on top of the already-displayed trip
 * view.
 *
 * - `null`              — block not applicable (TTL expired, never started)
 * - `pending`/`running` — enrichment in flight → spinner / skeleton
 * - `done`              — enrichment landed → final render
 * - `failed`            — enrichment failed → error + retry affordance
 */
export type BlockStatus = "pending" | "running" | "done" | "failed" | null;

import type { InRidePoiCategory, PoiSuggestionDto } from "@/lib/api/client";

/**
 * POI shape carried by in-ride suggestions. Re-exported from the API client so
 * every consumer shares a single derivation of the POI wire shape and a schema
 * change surfaces in one place.
 */
export type PoiSuggestion = PoiSuggestionDto;

/**
 * One turn of the guided in-ride thread (#935).
 *
 * Stored in-memory only — the thread is intentionally not persisted across page
 * reloads. Carries no `content`: every visible string is derived from
 * `next-intl` at render time from the structured metadata below, so a reworded
 * message never touches the store.
 *
 * - `question` — a user turn: which category chip was tapped.
 * - `recap`    — an assistant turn: the search outcome (radius, counts, coverage
 *   flags) plus the {@link pois} to render as cards.
 */
export interface InRideMessage {
  role: "user" | "assistant";
  ts: number;
  kind: "question" | "recap";
  category?: InRidePoiCategory;
  radiusMeters?: number;
  totalFound?: number;
  capReached?: boolean;
  outOfCoverage?: boolean;
  pois?: PoiSuggestion[];
}

interface UiState {
  isProcessing: boolean;
  isAccommodationScanning: boolean;
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
   * Per-block async enrichment status (ADR-043, PR4-front).
   *
   * `weather` is the only remaining asynchronous block once structural
   * computation runs synchronously. It drives its own spinner / skeleton on
   * top of the already-displayed trip view, hydrated from
   * `GET /trips/{id}/detail` (`weatherStatus`) and kept live by the Mercure
   * dispatcher (`weather_fetched`, `trip_ready`, `computation_error`).
   */
  blockStatus: { weather: BlockStatus };
  /**
   * Whether the floating in-ride help panel is currently open.
   * Toggled by {@link toggleBubble} / {@link closeBubble}.
   */
  isBubbleOpen: boolean;
  /**
   * Guided in-ride thread, oldest first (#935). Cleared when the user starts a
   * new trip or invokes {@link clearHistory}. Not persisted across reloads.
   */
  chatHistory: InRideMessage[];
  /**
   * Whether the user has ever opened the in-ride bubble. Stored in
   * `localStorage` so the "Nouveau" badge only shows on the first visit.
   * Persisted by {@link toggleBubble} the first time the panel opens.
   */
  hasSeenBubble: boolean;

  setProcessing: (value: boolean) => void;
  setAccommodationScanning: (value: boolean) => void;
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
  /** Set the async status of the weather enrichment block. */
  setBlockStatus: (block: "weather", status: BlockStatus) => void;
  /** Flip {@link isBubbleOpen}. Also marks the bubble as seen on first open. */
  toggleBubble: () => void;
  /** Force the chat panel closed. */
  closeBubble: () => void;
  /** Append a turn to {@link chatHistory}. */
  appendMessage: (message: InRideMessage) => void;
  /** Reset the in-ride thread (wiped on trip switch). */
  clearHistory: () => void;
}

const BUBBLE_SEEN_STORAGE_KEY = "btp.in-ride-bubble.seen";

function readBubbleSeenFromStorage(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return window.localStorage.getItem(BUBBLE_SEEN_STORAGE_KEY) === "1";
  } catch {
    return false;
  }
}

function writeBubbleSeenToStorage(): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(BUBBLE_SEEN_STORAGE_KEY, "1");
  } catch {
    // localStorage may be unavailable (private mode, quota) — degrade silently.
  }
}

/**
 * Zustand store for transient UI state (not tied to trip data).
 *
 * Tracks global UI concerns that are orthogonal to the trip model:
 * - `isProcessing` — whether an async backend computation is in flight
 * - `expandedCalendar` — whether the date picker panel is open
 * - `isConfigPanelOpen` — whether the configuration sidebar is open
 * - `isHelpModalOpen` — whether the keyboard shortcuts help modal is open
 * - `error` — global error banner state (type + message), or `null`
 * - `activeDayNumber` — the day number currently highlighted across the UI
 *   (progress bar, map, elevation profile); `null` means no active day
 * - `focusedMapStageIndex` — which active-stage index is currently zoomed on
 *   the map; `null` means global view (all stages visible)
 * - `viewMode` — current layout mode: "timeline", "map", or "split"
 * - `blockStatus` — per-block async enrichment status (weather)
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
    blockStatus: { weather: null },
    isBubbleOpen: false,
    chatHistory: [],
    hasSeenBubble: readBubbleSeenFromStorage(),

    setProcessing: (value) =>
      set((state) => {
        state.isProcessing = value;
      }),

    setAccommodationScanning: (value) =>
      set((state) => {
        state.isAccommodationScanning = value;
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

    setBlockStatus: (block, status) =>
      set((state) => {
        state.blockStatus[block] = status;
      }),

    toggleBubble: () =>
      set((state) => {
        const next = !state.isBubbleOpen;
        state.isBubbleOpen = next;
        if (next && !state.hasSeenBubble) {
          state.hasSeenBubble = true;
          writeBubbleSeenToStorage();
        }
      }),

    closeBubble: () =>
      set((state) => {
        state.isBubbleOpen = false;
      }),

    appendMessage: (message) =>
      set((state) => {
        state.chatHistory.push(message);
      }),

    clearHistory: () =>
      set((state) => {
        state.chatHistory = [];
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

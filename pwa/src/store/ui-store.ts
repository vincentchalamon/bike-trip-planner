"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import { enableMapSet } from "immer";
import { AI_ENABLED } from "@/lib/constants";

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

import type { PoiSuggestionDto } from "@/lib/api/client";

/**
 * POI shape carried by in-ride assistant replies. Re-exported from the API
 * client so both the history loader and the chat store share a single
 * derivation of `Trip.TripChatResponse.jsonld.pois[number]` — a future schema
 * refactor (e.g. moving `pois` onto a dedicated InRideResponse) can't leave
 * one copy out of sync.
 */
export type PoiSuggestion = PoiSuggestionDto;

/**
 * One turn of the floating AI assistant conversation.
 *
 * Stored in-memory only — the dialogue history is intentionally not persisted
 * across page reloads to mirror the backend-side {@link ChatHistoryStore} which
 * uses a short-TTL Redis store per (trip, user) pair.
 *
 * When the assistant returned POI suggestions (in-ride mode), {@link pois}
 * carries the structured payload so the chat panel can render the POI cards
 * beneath the conversational text.
 */
export interface AiChatMessage {
  role: "user" | "assistant";
  content: string;
  ts: number;
  pois?: PoiSuggestion[];
}

/**
 * Conversational context propagated with every chat request so the dialogue
 * assistant can resolve referential phrases such as « cette étape » against
 * the stage the user is currently consulting.
 */
export interface AiChatContext {
  currentStage: number | null;
}

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
   * Stays `true` for the lifetime of the trip so Acte 3 inline edits don't
   * revert to the preview screen.
   */
  hasAnalysisStarted: boolean;
  /**
   * Whether the Acte 2 enrichment pipeline is currently running. `true` from
   * the moment the user clicks "Lancer l'analyse" until `trip_ready` (or
   * `trip_complete`) arrives. Distinct from {@link hasAnalysisStarted} which
   * stays `true` permanently — this flag gates the `ProcessingProgress` screen
   * so that Acte 3 inline-edit backend calls don't re-trigger it.
   */
  isAnalysisPhaseActive: boolean;
  /**
   * Latest snapshot from the `computation_step_completed` Mercure event.
   * Drives the progress bar during Phase 2. `null` when no analysis is in
   * flight (initial state, or after `trip_ready` lands).
   */
  analysisProgress: {
    step: string;
    category: string;
    completed: number;
    total: number;
  } | null;
  /**
   * Per-step progress state for Acte 2 (narrative progress screen).
   *
   * Keyed by the backend `ComputationName::value` emitted in
   * `computation_step_completed` events (e.g. "terrain", "water_points",
   * "bike_shops", "accommodations", …). Statuses:
   *   pending → in_progress → done | failed
   *
   * The narrative screen groups these steps into user-facing categories
   * (Terrain, Ravitaillement, Hébergements, Météo, Services, AI). See
   * `components/processing-progress.tsx` for the mapping.
   *
   * `in_progress` is a transient state: the backend only emits a single
   * event *when a step completes*, so we track "seen" steps as done and
   * use the latest `analysisProgress.step` to highlight the currently
   * running step.
   */
  analysisStepStates: Record<
    string,
    {
      status: "done" | "failed";
      error: string | null;
    }
  >;
  /**
   * Whether the floating AI assistant chat panel is currently open.
   * Toggled by {@link toggleBubble} / {@link closeBubble}.
   */
  isBubbleOpen: boolean;
  /**
   * Conversation history of the floating AI assistant, oldest first. Cleared
   * when the user starts a new trip or invokes {@link clearHistory}. Not
   * persisted across reloads — the backend keeps the canonical history.
   */
  chatHistory: AiChatMessage[];
  /**
   * Conversational context the chat panel sends along with every message.
   * Mirrors `TripChatContext` on the backend.
   */
  currentContext: AiChatContext;
  /**
   * `true` while a chat message is in flight — drives the typing indicator
   * inside the chat panel and disables the send button.
   */
  isChatSending: boolean;
  /**
   * Whether the user has ever opened the AI bubble. Stored in
   * `localStorage` so the "Nouveau" badge only shows on the first visit.
   * Persisted by {@link toggleBubble} the first time the panel opens.
   */
  hasSeenBubble: boolean;
  /**
   * AI tier availability driving the explicit gating (#304, ADR-042).
   * - `enabled`: build-time config (`AI_ENABLED`); when false, AI features are hidden.
   * - `available`: runtime reachability of the LLM tier, read from `/api/health`
   *   (`deps.ollama_chat`). Starts optimistic (`true`) to avoid a disabled→enabled
   *   flash; flipped to `false` only once an outage is confirmed. For the
   *   bring-your-own-token cloud providers (ADR-042) there is no self-hosted tier
   *   to probe, so this stays `true`.
   * - `configured`: whether the account has an AI provider + token set
   *   (`GET /users/me/ai-settings`). When false, AI surfaces are shown
   *   disabled-but-visible with a "Configurez une IA" CTA. Starts `false`
   *   (fail-closed) so the controls stay disabled until the settings confirm
   *   a provider.
   */
  aiCapability: { enabled: boolean; available: boolean; configured: boolean };

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
  /** Flip {@link isAnalysisPhaseActive}. Set `true` when Acte 2 starts, `false`
   * when `trip_ready` / `trip_complete` lands. */
  setAnalysisPhaseActive: (value: boolean) => void;
  /** Store a `computation_step_completed` snapshot (Mode 1 progress tick). */
  setAnalysisProgress: (
    progress: {
      step: string;
      category: string;
      completed: number;
      total: number;
    } | null,
  ) => void;
  /** Mark a step as completed (from a `computation_step_completed` event). */
  recordAnalysisStep: (step: string) => void;
  /** Mark a step as failed with a human-readable error message. */
  failAnalysisStep: (step: string, message: string) => void;
  /** Flip {@link isBubbleOpen}. Also marks the bubble as seen on first open. */
  toggleBubble: () => void;
  /** Force the chat panel closed. */
  closeBubble: () => void;
  /** Append a turn to {@link chatHistory}. */
  appendMessage: (message: AiChatMessage) => void;
  /** Update the conversational context broadcast to the chat endpoint. */
  setCurrentContext: (context: AiChatContext) => void;
  /** Reset the chat history (Acte 1.5 → Acte 3 transition, manual clear). */
  clearHistory: () => void;
  /** Flip the in-flight indicator that drives the typing dots. */
  setChatSending: (value: boolean) => void;
  /** Update the runtime AI availability (from the `/api/health` probe, #304). */
  setAiAvailable: (value: boolean) => void;
  /** Update whether the account has a configured AI provider (ADR-042). */
  setAiConfigured: (value: boolean) => void;
  /** Replace the whole AI capability — E2E override hook for the states. */
  setAiCapability: (capability: {
    enabled: boolean;
    available: boolean;
    configured: boolean;
  }) => void;
}

const BUBBLE_SEEN_STORAGE_KEY = "btp.ai-bubble.seen";

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
    isAnalysisPhaseActive: false,
    analysisProgress: null,
    analysisStepStates: {},
    isBubbleOpen: false,
    chatHistory: [],
    currentContext: { currentStage: null },
    isChatSending: false,
    hasSeenBubble: readBubbleSeenFromStorage(),
    aiCapability: { enabled: AI_ENABLED, available: true, configured: false },

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
        state.isAnalysisPhaseActive = false;
        state.analysisProgress = null;
        state.analysisStepStates = {};
      }),

    setAnalysisStarted: (value) =>
      set((state) => {
        state.hasAnalysisStarted = value;
      }),

    setAnalysisPhaseActive: (value) =>
      set((state) => {
        state.isAnalysisPhaseActive = value;
      }),

    setAnalysisProgress: (progress) =>
      set((state) => {
        state.analysisProgress = progress;
      }),

    recordAnalysisStep: (step) =>
      set((state) => {
        state.analysisStepStates[step] = { status: "done", error: null };
      }),

    failAnalysisStep: (step, message) =>
      set((state) => {
        state.analysisStepStates[step] = {
          status: "failed",
          error: message,
        };
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

    setCurrentContext: (context) =>
      set((state) => {
        state.currentContext = context;
      }),

    clearHistory: () =>
      set((state) => {
        state.chatHistory = [];
        state.isChatSending = false;
      }),

    setChatSending: (value) =>
      set((state) => {
        state.isChatSending = value;
      }),

    setAiAvailable: (value) =>
      set((state) => {
        state.aiCapability.available = value;
      }),

    setAiConfigured: (value) =>
      set((state) => {
        state.aiCapability.configured = value;
      }),

    setAiCapability: (capability) =>
      set((state) => {
        state.aiCapability = capability;
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

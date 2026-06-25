"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";

export type ViewMode = "timeline" | "map" | "split";

/**
 * Per-block async enrichment status (ADR-043, PR4-front).
 *
 * Mirrors the backend `weatherStatus` / `aiStatus` fields exposed on
 * `GET /trips/{id}/detail`. Structural computation now runs synchronously
 * (status `draft` → `ready`); weather and AI are the only remaining
 * asynchronous blocks, each rendered with its own spinner on top of the
 * already-displayed trip view.
 *
 * - `null`              — block not applicable (TTL expired, never started)
 * - `pending`/`running` — enrichment in flight → spinner / skeleton
 * - `done`              — enrichment landed → final render
 * - `failed`            — enrichment failed → error + retry affordance
 */
export type BlockStatus = "pending" | "running" | "done" | "failed" | null;

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
   * Per-block async enrichment status (ADR-043, PR4-front).
   *
   * `weather` and `ai` are the only remaining asynchronous blocks once
   * structural computation runs synchronously. Each drives its own spinner /
   * skeleton on top of the already-displayed trip view, hydrated from
   * `GET /trips/{id}/detail` (`weatherStatus` / `aiStatus`) and kept live by
   * the Mercure dispatcher (`weather_fetched`, `trip_ready`,
   * `computation_error`).
   */
  blockStatus: { weather: BlockStatus; ai: BlockStatus };
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
   * i18n key (under `aiBubble`) of the actionable provider-config error to show
   * as a settings-CTA banner in the chat panel, or `null` when hidden. Set on a
   * 422 from the chat endpoint (invalid token / exhausted quota, #761); cleared
   * on the next successful turn.
   */
  chatConfigErrorKey: "errorInvalidToken" | "errorQuotaExceeded" | null;
  /**
   * Whether the user has ever opened the AI bubble. Stored in
   * `localStorage` so the "Nouveau" badge only shows on the first visit.
   * Persisted by {@link toggleBubble} the first time the panel opens.
   */
  hasSeenBubble: boolean;
  /**
   * AI tier availability driving the explicit gating (#304, ADR-042).
   * - `available`: runtime reachability of the LLM tier. For the
   *   bring-your-own-token cloud providers (ADR-042) there is no self-hosted tier
   *   to probe, so this stays `true` (see `fetchAiAvailability`); a provider
   *   outage surfaces reactively via the 503 the chat endpoint returns.
   * - `configured`: whether the account has an AI provider + token set
   *   (`GET /users/me/ai-settings`). When false, AI surfaces are shown
   *   disabled-but-visible with a "Configurez une IA" CTA. Starts `false`
   *   (fail-closed) so the controls stay disabled until the settings confirm
   *   a provider.
   */
  aiCapability: { available: boolean; configured: boolean };

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
  /** Set the async status of a single enrichment block (weather / ai). */
  setBlockStatus: (block: "weather" | "ai", status: BlockStatus) => void;
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
  /** Show / hide the actionable provider-config error banner (#761). */
  setChatConfigError: (
    key: "errorInvalidToken" | "errorQuotaExceeded" | null,
  ) => void;
  /** Update the runtime AI availability (from the `/api/health` probe, #304). */
  setAiAvailable: (value: boolean) => void;
  /** Update whether the account has a configured AI provider (ADR-042). */
  setAiConfigured: (value: boolean) => void;
  /** Replace the whole AI capability — E2E override hook for the states. */
  setAiCapability: (capability: {
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
 * - `blockStatus` — per-block async enrichment status (weather / ai)
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
    blockStatus: { weather: null, ai: null },
    isBubbleOpen: false,
    chatHistory: [],
    currentContext: { currentStage: null },
    isChatSending: false,
    chatConfigErrorKey: null,
    hasSeenBubble: readBubbleSeenFromStorage(),
    aiCapability: { available: true, configured: false },

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

    setCurrentContext: (context) =>
      set((state) => {
        state.currentContext = context;
      }),

    clearHistory: () =>
      set((state) => {
        state.chatHistory = [];
        state.isChatSending = false;
        state.chatConfigErrorKey = null;
      }),

    setChatSending: (value) =>
      set((state) => {
        state.isChatSending = value;
      }),

    setChatConfigError: (key) =>
      set((state) => {
        state.chatConfigErrorKey = key;
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

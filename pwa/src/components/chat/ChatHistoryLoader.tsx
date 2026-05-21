"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import {
  fetchTripChatHistory,
  type TripChatMessageHistoryEntry,
} from "@/lib/api/client";
import { useUiStore, type AiChatMessage } from "@/store/ui-store";

interface ChatHistoryLoaderProps {
  tripId: string;
  /**
   * Optional render slot for the body. Lets the chat panel display a richer
   * empty state if the history is empty. By default the loader renders
   * nothing once hydration is done.
   */
  children?: React.ReactNode;
}

/**
 * Maps a persisted chat-history entry returned by the backend into the
 * frontend's in-memory {@link AiChatMessage} shape. Assistant turns that
 * carried a JSON envelope only surface the conversational `response` field
 * inside the panel — the raw envelope stays hidden to avoid cluttering the UI.
 *
 * For the assistant role we attempt to parse the JSON envelope (the backend
 * persists the raw LLM output for traceability). If parsing fails we fall
 * back to the raw content so the user still sees the message body.
 */
function toAiChatMessage(entry: TripChatMessageHistoryEntry): AiChatMessage {
  const ts = new Date(entry.createdAt).getTime();
  if (entry.role === "user") {
    return {
      role: "user",
      content: entry.content,
      ts: Number.isFinite(ts) ? ts : Date.now(),
    };
  }

  let content = entry.content;
  try {
    const parsed = JSON.parse(entry.content) as { response?: unknown };
    if (typeof parsed.response === "string") {
      content = parsed.response;
    }
  } catch {
    // The persisted content is not always a JSON envelope (older messages,
    // in-ride narratives, etc.). Use it as-is in that case.
  }

  return {
    role: "assistant",
    content,
    ts: Number.isFinite(ts) ? ts : Date.now(),
    ...(entry.pois && entry.pois.length > 0 ? { pois: entry.pois } : {}),
  };
}

/**
 * Module-level set of trip IDs whose persisted history has already been pulled
 * during this PWA session. The chat panel unmounts (and remounts) the loader
 * every time the rider closes the drawer, so without this guard each reopen
 * would refetch and wipe any in-memory messages added since the last fetch —
 * notably in-ride POI turns that are persisted asynchronously.
 */
const hydratedTrips = new Set<string>();

/**
 * Rehydrates the floating chat panel with the persisted history for a given
 * trip.
 *
 * On mount the loader calls `GET /trips/{id}/chat-history`, maps each entry
 * to the in-memory store shape, and pushes them via `appendMessage` so the
 * panel reflects the conversation across refreshes. A lightweight skeleton
 * appears while the request is in flight; failures are logged silently — the
 * panel simply starts empty.
 */
export function ChatHistoryLoader({
  tripId,
  children,
}: ChatHistoryLoaderProps) {
  const t = useTranslations("chat.history");
  const alreadyHydrated = hydratedTrips.has(tripId);
  const [isLoading, setIsLoading] = useState(!alreadyHydrated);
  const [isHydrated, setIsHydrated] = useState(alreadyHydrated);

  useEffect(() => {
    if (hydratedTrips.has(tripId)) {
      setIsLoading(false);
      setIsHydrated(true);
      return;
    }

    let cancelled = false;
    const controller = new AbortController();

    async function load() {
      setIsLoading(true);
      try {
        const entries = await fetchTripChatHistory(tripId, {
          limit: 50,
          signal: controller.signal,
        });
        if (cancelled) return;

        const ui = useUiStore.getState();
        // Race guard: if the rider sent a message while the fetch was in
        // flight the in-memory store is no longer empty. Wiping it via
        // clearHistory() would discard their freshly-typed turn (already on
        // its way to the backend), so we skip seeding entirely and let the
        // next reload pick up the merged history from PostgreSQL.
        if (entries.length > 0 && ui.chatHistory.length === 0) {
          ui.clearHistory();
          for (const entry of entries) {
            ui.appendMessage(toAiChatMessage(entry));
          }
        }
        hydratedTrips.add(tripId);
        setIsHydrated(true);
      } catch {
        // Silent fail — the rider can still chat without prior history.
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    void load();

    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [tripId]);

  if (isLoading) {
    return (
      <div
        data-testid="chat-history-loader-skeleton"
        role="status"
        aria-live="polite"
        className="flex flex-col gap-2 px-4 py-3"
      >
        <span className="sr-only">{t("loading")}</span>
        <span className="h-3 w-32 animate-pulse rounded bg-muted-foreground/20" />
        <span className="h-3 w-48 animate-pulse rounded bg-muted-foreground/20" />
        <span className="h-3 w-24 animate-pulse rounded bg-muted-foreground/20" />
      </div>
    );
  }

  if (isHydrated && children) {
    return <>{children}</>;
  }

  return null;
}

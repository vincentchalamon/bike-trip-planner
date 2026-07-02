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
 * Rehydrates the floating chat panel with the persisted history for a given
 * trip.
 *
 * On mount the loader calls `GET /trips/{id}/ai-chat-history`, maps each entry
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
  // Gate on the in-memory history, not a one-shot "already fetched" flag: the
  // panel unmounts on close and the history is wiped on a trip switch
  // (use-trip-planner clears it), so a reopen must be able to re-pull the
  // persisted turns from PostgreSQL. We skip the fetch only when the store
  // still holds turns (freshly typed, or an async in-ride POI turn) that a
  // refetch could wipe (recette #649).
  const [isLoading, setIsLoading] = useState(
    () => useUiStore.getState().chatHistory.length === 0,
  );
  const [isHydrated, setIsHydrated] = useState(
    () => useUiStore.getState().chatHistory.length > 0,
  );

  useEffect(() => {
    if (useUiStore.getState().chatHistory.length > 0) {
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
        // flight the in-memory store is no longer empty. Seeding would then
        // duplicate the live turn, so skip it and keep what's on screen.
        if (entries.length > 0 && ui.chatHistory.length === 0) {
          for (const entry of entries) {
            ui.appendMessage(toAiChatMessage(entry));
          }
        }
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

"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import { useTranslations } from "next-intl";
import { Send, Sparkles, X } from "lucide-react";
import { useShallow } from "zustand/react/shallow";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { useUiStore, type AiChatMessage } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";
import { useTripPlanner } from "@/hooks/use-trip-planner";
import { useGeolocation } from "@/hooks/use-geolocation";
import { PoiCard } from "@/components/chat/PoiCard";
import { InRideDisclaimer } from "@/components/chat/InRideDisclaimer";
import { ChatHistoryLoader } from "@/components/chat/ChatHistoryLoader";

const CHAT_ACTION_INFO = "info";

/**
 * Fired on `document` whenever the chat endpoint returns an actionable
 * response (anything but `info`). Issue #311 wires the matching recomputation
 * flow on top of this signal so the bubble stays decoupled from the store.
 */
export const AI_CHAT_ACTION_EVENT = "ai-chat-action";

/** Detail shape of {@link AI_CHAT_ACTION_EVENT}. */
export interface AiChatActionEventDetail {
  action: string;
  params: Record<string, unknown>;
  response: string;
  tripId: string;
  dispatched: boolean;
  /** 1-indexed day numbers whose recomputation has been dispatched. */
  impactedStageNumbers?: number[];
  /** True when the action requires a full trip re-analysis (Acte 2). */
  requiresFullAnalysis?: boolean;
}

interface AiChatPanelProps {
  /**
   * Called when the panel is dismissed (close button, Escape key, backdrop
   * click on mobile). Parent components remain in charge of the global open
   * flag so the bubble and the panel stay in sync.
   */
  onClose: () => void;
}

/**
 * Floating AI assistant chat panel.
 *
 * Renders a 400 × 500 anchored panel on desktop (≥ md) and a full-screen
 * sheet on mobile. The header carries the title and close button, the body
 * shows alternating user/assistant bubbles (auto-scrolled), and the footer
 * holds the composer (Enter to send, Shift+Enter for newline).
 *
 * The panel reads/writes {@link useUiStore} for the chat history, the
 * conversational context (currentStage), and the in-flight typing indicator.
 * It calls {@link useTripPlanner.sendChatMessage} to round-trip the message
 * to `POST /trips/{id}/chat` and, on receiving an actionable response,
 * dispatches an {@link AI_CHAT_ACTION_EVENT} so issue #311 can plug the
 * recomputation wiring without coupling to this component.
 */
export function AiChatPanel({ onClose }: AiChatPanelProps) {
  const t = useTranslations("aiBubble");
  const tGeoloc = useTranslations("chat.inRide");

  const { chatHistory, isChatSending, currentContext } = useUiStore(
    useShallow((s) => ({
      chatHistory: s.chatHistory,
      isChatSending: s.isChatSending,
      currentContext: s.currentContext,
    })),
  );

  const tripId = useTripStore((s) => s.trip?.id ?? null);
  const { sendChatMessage, relaunchFullAnalysis } = useTripPlanner();
  const geo = useGeolocation();

  const [draft, setDraft] = useState("");
  const [pendingFullAnalysis, setPendingFullAnalysis] = useState(false);
  const historyRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const greeting: AiChatMessage = useMemo(
    () => ({ role: "assistant", content: t("greeting"), ts: 0 }),
    [t],
  );

  const transcript: ReadonlyArray<AiChatMessage> = useMemo(
    () => (chatHistory.length === 0 ? [greeting] : [greeting, ...chatHistory]),
    [chatHistory, greeting],
  );

  useEffect(() => {
    const el = historyRef.current;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
  }, [transcript.length, isChatSending]);

  useEffect(() => {
    textareaRef.current?.focus();
  }, []);

  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [onClose]);

  const handleSubmit = useCallback(async () => {
    const trimmed = draft.trim();
    if (!trimmed || isChatSending) return;
    setDraft("");
    // When the rider has granted geolocation permission and we have a fresh
    // fix, forward it to the backend so it can switch to in-ride POI search
    // mode. Without a position the planning pipeline runs as before.
    const position = geo.position
      ? { lat: geo.position.latitude, lon: geo.position.longitude }
      : null;
    const response = await sendChatMessage(trimmed, position);
    if (response && response.action !== CHAT_ACTION_INFO) {
      const detail: AiChatActionEventDetail = {
        action: response.action,
        params: response.params,
        response: response.response,
        tripId: response.tripId,
        dispatched: response.dispatched,
        impactedStageNumbers: response.impactedStageNumbers,
        requiresFullAnalysis: response.requiresFullAnalysis,
      };
      document.dispatchEvent(
        new CustomEvent<AiChatActionEventDetail>(AI_CHAT_ACTION_EVENT, {
          detail,
        }),
      );
      setPendingFullAnalysis(response.requiresFullAnalysis === true);
    }
  }, [draft, isChatSending, sendChatMessage]);

  const handleRelaunchAnalysis = useCallback(async () => {
    // Only clear the banner + close the panel once the analysis dispatch has
    // succeeded — otherwise the "Relancer l'analyse" CTA would vanish on
    // failure and the rider would have to ask the AI again to get it back.
    const ok = await relaunchFullAnalysis();
    if (ok) {
      setPendingFullAnalysis(false);
      onClose();
    }
  }, [relaunchFullAnalysis, onClose]);

  const handleKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLTextAreaElement>) => {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        void handleSubmit();
      }
    },
    [handleSubmit],
  );

  const hint =
    currentContext.currentStage !== null
      ? t("stageHint", { stage: currentContext.currentStage })
      : null;

  const canSend = draft.trim().length > 0 && !isChatSending;

  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-label={t("title")}
      data-testid="ai-chat-panel"
      className={cn(
        "fixed z-40 flex flex-col bg-background shadow-2xl",
        "inset-0 md:inset-auto md:bottom-24 md:right-6",
        "md:w-[400px] md:h-[500px] md:rounded-2xl border md:border-border",
      )}
    >
      <header className="flex items-center gap-2 border-b border-border px-4 py-3">
        <Sparkles className="h-4 w-4 text-brand" aria-hidden="true" />
        <div className="flex-1 min-w-0">
          <h2 className="text-sm font-semibold text-foreground">
            {t("title")}
          </h2>
          <p className="text-xs text-muted-foreground truncate">
            {t("subtitle")}
          </p>
        </div>
        <Button
          type="button"
          size="icon"
          variant="ghost"
          onClick={onClose}
          aria-label={t("closeAria")}
          data-testid="ai-chat-panel-close"
          className="h-8 w-8 shrink-0"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </Button>
      </header>

      {hint && (
        <p
          className="px-4 py-2 text-xs text-muted-foreground bg-muted/40 border-b border-border"
          data-testid="ai-chat-panel-hint"
        >
          {hint}
        </p>
      )}

      <div
        ref={historyRef}
        role="log"
        aria-live="polite"
        aria-label={t("historyAriaLabel")}
        data-testid="ai-chat-panel-history"
        className="flex-1 overflow-y-auto px-4 py-3 flex flex-col gap-3 bg-muted/20"
      >
        {tripId && <ChatHistoryLoader tripId={tripId} />}
        {transcript.map((message, index) => (
          <ChatBubble
            key={`${message.role}-${message.ts}-${index}`}
            message={message}
          />
        ))}
        {isChatSending && <TypingDots />}
      </div>

      {!geo.position && !geo.isRequesting && (
        <button
          type="button"
          data-testid="ai-chat-panel-geoloc-prompt"
          onClick={() => geo.request()}
          className="block w-full border-t border-border bg-muted/30 px-4 py-2 text-left text-xs text-muted-foreground underline-offset-2 hover:underline"
        >
          {tGeoloc("geolocPrompt")}
        </button>
      )}

      {pendingFullAnalysis && (
        <div
          data-testid="ai-chat-panel-full-analysis"
          className="border-t border-border bg-muted/30 px-4 py-3 flex flex-col gap-2"
        >
          <p className="text-xs text-muted-foreground">
            {t("fullAnalysisHint")}
          </p>
          <Button
            type="button"
            size="sm"
            onClick={() => void handleRelaunchAnalysis()}
            data-testid="ai-chat-panel-relaunch"
            className="bg-brand-fill text-white hover:bg-brand-fill-hover"
          >
            {t("relaunchAnalysis")}
          </Button>
        </div>
      )}

      <div className="border-t border-border p-3 flex items-end gap-2">
        <label htmlFor="ai-chat-panel-textarea" className="sr-only">
          {t("inputAriaLabel")}
        </label>
        <textarea
          ref={textareaRef}
          id="ai-chat-panel-textarea"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={handleKeyDown}
          placeholder={t("inputPlaceholder")}
          aria-label={t("inputAriaLabel")}
          rows={2}
          maxLength={2000}
          disabled={isChatSending}
          data-testid="ai-chat-panel-input"
          className={cn(
            "flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm",
            "placeholder:text-muted-foreground",
            "focus-visible:outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
            "disabled:cursor-not-allowed disabled:opacity-60",
          )}
        />
        <Button
          type="button"
          size="icon"
          onClick={() => void handleSubmit()}
          disabled={!canSend}
          aria-label={t("sendAria")}
          data-testid="ai-chat-panel-send"
          className="shrink-0 bg-brand-fill text-white hover:bg-brand-fill-hover disabled:bg-brand-fill/40"
        >
          <Send className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
    </div>
  );
}

interface ChatBubbleProps {
  message: AiChatMessage;
}

function ChatBubble({ message }: ChatBubbleProps) {
  const isUser = message.role === "user";
  const hasPois =
    !isUser && Array.isArray(message.pois) && message.pois.length > 0;

  return (
    <div
      data-testid="ai-chat-panel-message"
      data-role={message.role}
      className={cn("flex w-full", isUser ? "justify-end" : "justify-start")}
    >
      <div
        className={cn(
          "flex flex-col gap-2",
          hasPois ? "max-w-[95%] w-full" : "max-w-[85%]",
        )}
      >
        <div
          className={cn(
            "rounded-2xl px-3 py-2 text-sm leading-relaxed shadow-sm whitespace-pre-wrap",
            isUser
              ? "bg-brand-fill text-white rounded-br-sm self-end"
              : "bg-background border border-border text-foreground rounded-bl-sm self-start",
          )}
        >
          {message.content}
        </div>
        {hasPois && (
          <div data-testid="ai-chat-panel-pois" className="flex flex-col gap-2">
            {message.pois!.map((poi, idx) => (
              <PoiCard key={`${poi.deeplink}-${idx}`} poi={poi} />
            ))}
            <InRideDisclaimer />
          </div>
        )}
      </div>
    </div>
  );
}

function TypingDots() {
  const t = useTranslations("aiBubble");
  return (
    <div
      data-testid="ai-chat-panel-typing"
      role="status"
      aria-live="polite"
      className="flex w-full justify-start"
    >
      <div className="flex items-center gap-1 rounded-2xl rounded-bl-sm border border-border bg-background px-3 py-2 shadow-sm">
        <span className="sr-only">{t("typing")}</span>
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/70 animate-bounce [animation-delay:-0.3s]" />
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/70 animate-bounce [animation-delay:-0.15s]" />
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/70 animate-bounce" />
      </div>
    </div>
  );
}

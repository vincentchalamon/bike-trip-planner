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
import { useTripPlanner } from "@/hooks/use-trip-planner";

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

  const { chatHistory, isChatSending, currentContext } = useUiStore(
    useShallow((s) => ({
      chatHistory: s.chatHistory,
      isChatSending: s.isChatSending,
      currentContext: s.currentContext,
    })),
  );

  const { sendChatMessage } = useTripPlanner();

  const [draft, setDraft] = useState("");
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
    const response = await sendChatMessage(trimmed);
    if (response && response.action !== CHAT_ACTION_INFO) {
      const detail: AiChatActionEventDetail = {
        action: response.action,
        params: response.params,
        response: response.response,
        tripId: response.tripId,
        dispatched: response.dispatched,
      };
      document.dispatchEvent(
        new CustomEvent<AiChatActionEventDetail>(AI_CHAT_ACTION_EVENT, {
          detail,
        }),
      );
    }
  }, [draft, isChatSending, sendChatMessage]);

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
        {transcript.map((message, index) => (
          <ChatBubble
            key={`${message.role}-${message.ts}-${index}`}
            message={message}
          />
        ))}
        {isChatSending && <TypingDots />}
      </div>

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
          className="shrink-0 bg-brand text-white hover:bg-brand-hover disabled:bg-brand/40"
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
  return (
    <div
      data-testid="ai-chat-panel-message"
      data-role={message.role}
      className={cn("flex w-full", isUser ? "justify-end" : "justify-start")}
    >
      <div
        className={cn(
          "max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-relaxed shadow-sm whitespace-pre-wrap",
          isUser
            ? "bg-brand text-white rounded-br-sm"
            : "bg-background border border-border text-foreground rounded-bl-sm",
        )}
      >
        {message.content}
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

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
import { Send, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * One turn of the AI assistant conversation.
 *
 * The shell stores both sides of the dialogue locally so the UI behaves
 * realistically (auto-scroll, alternating bubbles, keyboard navigation) before
 * the backend is wired in.
 *
 * @remarks
 * TODO(sprint 31, #309) — replace the local message list with the real chat
 * stream from the backend AI endpoint. The Ollama infrastructure ships in
 * sprint 28, after which this card will plug into the streaming chat API and
 * actually generate stages instead of dispatching a stub navigation event.
 */
export interface AiChatMessage {
  /** Stable id used as React key — generated from a monotonic counter. */
  id: string;
  /** Speaker — `user` for human input, `assistant` for the AI reply. */
  role: "user" | "assistant";
  /** Free-text content of the turn. */
  content: string;
}

interface AiChatCardProps {
  /**
   * Fired when the user clicks "Valider et continuer". Receives the current
   * conversation transcript so the parent can hand it off to the wizard or
   * forward it to the AI endpoint (sprint 31). The shell remains agnostic
   * about how the conversation is consumed.
   */
  onSubmitConversation?: (messages: ReadonlyArray<AiChatMessage>) => void;
  /** Disables every interactive element (offline, parent processing, ...). */
  disabled?: boolean;
}

/**
 * Custom DOM event dispatched on the document when the user submits the chat.
 *
 * Sprint 31 / #309 will replace this with a proper navigation flow once the
 * backend chat endpoint exists; for now the wizard host listens to this event
 * to advance to the preview step (or simply ignores it during the shell-only
 * milestone).
 */
export const AI_CHAT_SUBMIT_EVENT = "ai-chat-submit";

/** Detail shape for {@link AI_CHAT_SUBMIT_EVENT}. */
export interface AiChatSubmitEventDetail {
  messages: ReadonlyArray<AiChatMessage>;
}

/** Stub assistant placeholder rendered before the user has typed anything. */
const STUB_ASSISTANT_GREETING_KEY = "stubGreeting";

/**
 * "Assistant IA" card — multi-turn chat shell for step 1 of `/trips/new`.
 *
 * The card renders three regions:
 *
 *  1. A scrollable history of alternating user / assistant bubbles, anchored
 *     at the bottom (auto-scroll on every new message).
 *  2. A free-form textarea so the user can describe their desired itinerary
 *     ("Je veux faire le tour de Corse en 10 jours en septembre", ...).
 *  3. A submit row with the primary "Valider et continuer" button.
 *
 * Until sprint 31 wires the backend (#309), the assistant replies are stubbed
 * locally so the interaction can be exercised end-to-end in mocked tests. The
 * primary button dispatches {@link AI_CHAT_SUBMIT_EVENT} with the transcript
 * so the wizard host can react without coupling to this component.
 *
 * Accessibility:
 *
 *  - The history is exposed as an `aria-live="polite"` log so screen readers
 *    announce new turns.
 *  - The textarea reacts to `Enter` (submit) / `Shift+Enter` (newline) like a
 *    standard chat composer.
 *  - The "Valider et continuer" button is disabled until at least one user
 *    turn exists, mirroring the validation rule of the URL / GPX cards.
 */
export function AiChatCard({
  onSubmitConversation,
  disabled = false,
}: AiChatCardProps) {
  const t = useTranslations("aiChat");

  const [messages, setMessages] = useState<AiChatMessage[]>([]);
  const [draft, setDraft] = useState("");
  const counterRef = useRef(0);
  const historyRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const stubGreeting = t(STUB_ASSISTANT_GREETING_KEY);
  const stubReply = t("stubReply");

  const transcript: ReadonlyArray<AiChatMessage> = useMemo(() => {
    const greeting: AiChatMessage = {
      id: "greeting",
      role: "assistant",
      content: stubGreeting,
    };
    return messages.length === 0 ? [greeting] : [greeting, ...messages];
  }, [messages, stubGreeting]);

  // Auto-scroll the history to the latest turn whenever a message is appended.
  useEffect(() => {
    const el = historyRef.current;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
  }, [transcript.length]);

  // Auto-resize the textarea to fit its content (single-line → 4 lines max).
  const autoResize = useCallback(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
  }, []);

  useEffect(() => {
    autoResize();
  }, [draft, autoResize]);

  const nextId = useCallback(() => {
    counterRef.current += 1;
    return `msg-${counterRef.current}`;
  }, []);

  const sendDraft = useCallback(() => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    if (disabled) return;

    setMessages((prev) => [
      ...prev,
      { id: nextId(), role: "user", content: trimmed },
      // TODO(sprint 31, #309) — replace this stub assistant reply with the
      // streamed response from the backend chat endpoint (Ollama, see #309).
      { id: nextId(), role: "assistant", content: stubReply },
    ]);
    setDraft("");
  }, [disabled, draft, nextId, stubReply]);

  const handleKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLTextAreaElement>) => {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        sendDraft();
      }
    },
    [sendDraft],
  );

  const handleSubmitConversation = useCallback(() => {
    if (disabled) return;
    if (messages.length === 0) return;

    // TODO(sprint 31, #309) — once the backend chat endpoint is available,
    // replace this stub navigation event with a proper API call that returns
    // the generated stages and lets the wizard advance to step 2 with real
    // data. Until then, we surface the transcript via a CustomEvent so the
    // wizard host (or E2E tests) can observe it without coupling.
    onSubmitConversation?.(messages);
    if (typeof document !== "undefined") {
      const event = new CustomEvent<AiChatSubmitEventDetail>(
        AI_CHAT_SUBMIT_EVENT,
        { detail: { messages } },
      );
      document.dispatchEvent(event);
    }
  }, [disabled, messages, onSubmitConversation]);

  const canSubmitConversation = messages.length > 0 && !disabled;
  const canSendDraft = draft.trim().length > 0 && !disabled;

  return (
    <div
      className="flex flex-col gap-4 w-full"
      data-testid="ai-chat-card"
      data-disabled={disabled || undefined}
    >
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Sparkles className="h-4 w-4 text-brand" aria-hidden="true" />
        <span>{t("subtitle")}</span>
      </div>

      <div
        ref={historyRef}
        role="log"
        aria-live="polite"
        aria-label={t("historyAriaLabel")}
        data-testid="ai-chat-history"
        className={cn(
          "flex flex-col gap-3 overflow-y-auto rounded-lg border bg-muted/20 p-3",
          // Fixed scrollable height so the history feels like a chat box,
          // not an ever-growing list. ~5 turns visible before scrolling.
          "h-64 max-h-[40vh]",
        )}
      >
        {transcript.map((message) => (
          <ChatBubble key={message.id} message={message} />
        ))}
      </div>

      <div className="flex flex-col gap-2">
        <label
          htmlFor="ai-chat-textarea"
          className="text-sm font-medium text-foreground"
        >
          {t("inputLabel")}
        </label>
        <div className="flex items-end gap-2">
          <textarea
            ref={textareaRef}
            id="ai-chat-textarea"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={t("inputPlaceholder")}
            disabled={disabled}
            rows={2}
            className={cn(
              "flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs",
              "placeholder:text-muted-foreground",
              "focus-visible:outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
              "disabled:cursor-not-allowed disabled:opacity-60",
            )}
            data-testid="ai-chat-textarea"
          />
          <Button
            type="button"
            size="icon"
            variant="outline"
            onClick={sendDraft}
            disabled={!canSendDraft}
            aria-label={t("sendAriaLabel")}
            data-testid="ai-chat-send"
            className="shrink-0"
          >
            <Send className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
        <p className="text-xs text-muted-foreground/80">{t("inputHint")}</p>
      </div>

      <Button
        type="button"
        onClick={handleSubmitConversation}
        disabled={!canSubmitConversation}
        data-testid="ai-chat-submit"
        className={cn(
          "w-full sm:w-auto self-end bg-brand text-white hover:bg-brand-hover",
          "disabled:bg-brand/50",
        )}
      >
        {t("submitLabel")}
      </Button>
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
      data-testid="ai-chat-message"
      data-role={message.role}
      className={cn("flex w-full", isUser ? "justify-end" : "justify-start")}
    >
      <div
        className={cn(
          "max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-relaxed shadow-sm",
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

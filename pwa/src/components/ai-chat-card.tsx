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
import Link from "next/link";
import { Loader2, Send, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { sendAiChat, type AiChatResponseBody } from "@/lib/api/client";

/**
 * One turn of the AI brief chat (`POST /trips/ai-chat`, ADR-045).
 *
 * Both sides of the dialogue are stored locally: the endpoint is stateless, so
 * the client carries the whole conversation on every turn. The assistant turns
 * are real model replies (no longer canned stubs); launching the computation
 * reuses `POST /trips/ai-generate` once the brief is complete enough.
 */
export interface AiChatMessage {
  /** Stable id used as React key — generated from a monotonic counter. */
  id: string;
  /** Speaker — `user` for human input, `assistant` for the AI reply. */
  role: "user" | "assistant";
  /** Free-text content of the turn. */
  content: string;
  /** When true the bubble renders as an error (failed turn), not a reply. */
  isError?: boolean;
}

/**
 * Maximum number of *user* turns before the card stops sending and pushes the
 * rider to launch (ADR-045 "filet, pas une cage"). Well under the backend
 * ceiling (`AiChatRequest::MAX_MESSAGES = 40`, counting both roles): a 20-user
 * cap leaves ample headroom for the interleaved assistant turns.
 */
export const MAX_USER_TURNS = 20;

interface AiChatCardProps {
  /**
   * Fired when the rider clicks "Lancer le calcul d'itinéraire". Receives the
   * consolidated brief (the structured `collected` parameters, with the rider's
   * own turns appended as fallback). The wizard host forwards it to
   * `POST /trips/ai-generate` (ADR-045).
   */
  onLaunchGeneration?: (brief: string) => void;
  /** Disables every interactive element (offline, parent processing, ...). */
  disabled?: boolean;
}

/**
 * Custom DOM event dispatched on the document when the rider launches the
 * generation, in addition to the {@link AiChatCardProps.onLaunchGeneration}
 * callback. Kept for test/legacy consumers that observe the launch without
 * coupling to this component.
 */
export const AI_CHAT_LAUNCH_EVENT = "ai-chat-launch";

/** Detail shape for {@link AI_CHAT_LAUNCH_EVENT}. */
export interface AiChatLaunchEventDetail {
  brief: string;
}

/**
 * Ordered list of the `collected` keys the recap surfaces, paired with their
 * i18n label key under `aiChat.recap`. Unknown keys returned by the model are
 * ignored: the recap stays a curated summary, not a raw dump.
 */
const RECAP_FIELDS: ReadonlyArray<{ key: string; labelKey: string }> = [
  { key: "start", labelKey: "start" },
  { key: "end", labelKey: "end" },
  { key: "loop", labelKey: "loop" },
  { key: "durationDays", labelKey: "durationDays" },
  { key: "profile", labelKey: "profile" },
  { key: "elevationTolerance", labelKey: "elevation" },
  { key: "dates", labelKey: "startDate" },
  { key: "resupply", labelKey: "supply" },
];

/** Render a `collected` scalar as a display string (skips empty/nullish). */
function formatRecapValue(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === "boolean") return value ? "✓" : null;
  const str = String(value).trim();
  return str === "" ? null : str;
}

/**
 * Serialize a `collected` value for the brief text handed to the generator.
 * Unlike the recap display (booleans → "✓"), the brief must be unambiguous for
 * the LLM spec extraction: a boolean becomes the literal `true`/`false`, so
 * `loop` is not lost as a checkmark and generation keeps it a loop (recette #649).
 */
function briefValue(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === "boolean") return value ? "true" : "false";
  const str = String(value).trim();
  return str === "" ? null : str;
}

/**
 * Whether the brief carries a geocodable departure. This is the single hard
 * gate on the launch button (ADR-045): without a non-empty `collected.start`
 * the AI route generation has nothing to geocode, so we never let the rider
 * launch. `readyToGenerate` only drives the *recommended* highlight, not this.
 */
function hasGeocodableStart(
  collected: AiChatResponseBody["collected"],
): boolean {
  const start = collected.start;
  return typeof start === "string" && start.trim().length > 0;
}

/**
 * Consolidate the brief sent to `POST /trips/ai-generate`. Prefers the
 * structured `collected` summary (one `label: value` line per known field) and
 * appends the rider's own turns as fallback so nothing the model failed to
 * structure is lost.
 */
function buildBrief(
  collected: AiChatResponseBody["collected"],
  userTurns: ReadonlyArray<string>,
): string {
  const structured = RECAP_FIELDS.map(({ key }) => {
    const value = briefValue(collected[key]);
    return value === null ? null : `${key}: ${value}`;
  }).filter((line): line is string => line !== null);

  const transcript = userTurns.map((t) => t.trim()).filter(Boolean);

  return [...structured, ...transcript].join("\n").trim();
}

/**
 * "Assistant IA" card — real multi-turn brief chat for step 1 of `/trips/new`
 * (ADR-045).
 *
 * The card renders three regions:
 *
 *  1. A scrollable history of alternating user / assistant bubbles, anchored
 *     at the bottom (auto-scroll on every new message).
 *  2. A live recap of the structured parameters the model `collected` so far
 *     (departure, destination/loop, duration, profile, ...).
 *  3. A composer textarea + a "Lancer le calcul d'itinéraire" button.
 *
 * Each user turn POSTs the whole transcript to `/trips/ai-chat`; the reply is
 * appended and the `collected` / `readyToGenerate` verdict updates the recap
 * and the launch button. The launch button is *enabled* as soon as a geocodable
 * departure is known and *highlighted* when the model judges the brief ready —
 * the AI recommends, the rider decides.
 *
 * Accessibility:
 *
 *  - The history is exposed as an `aria-live="polite"` log.
 *  - The textarea reacts to `Enter` (send) / `Shift+Enter` (newline).
 */
export function AiChatCard({
  onLaunchGeneration,
  disabled = false,
}: AiChatCardProps) {
  const t = useTranslations("aiChat");

  const [messages, setMessages] = useState<AiChatMessage[]>([]);
  const [collected, setCollected] = useState<AiChatResponseBody["collected"]>(
    {},
  );
  const [readyToGenerate, setReadyToGenerate] = useState(false);
  const [draft, setDraft] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [capReached, setCapReached] = useState(false);
  // i18n key of the message rendered in the settings-CTA banner, or null when
  // hidden. Shared by the non-actionable-by-retry failures (no provider set,
  // invalid token, exhausted quota): each shows the same "go to settings" CTA
  // with its own message instead of a misleading "retry" error bubble.
  const [configErrorKey, setConfigErrorKey] = useState<string | null>(null);
  const counterRef = useRef(0);
  const abortRef = useRef<AbortController | null>(null);
  const historyRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const greeting = t("greeting");

  const transcript: ReadonlyArray<AiChatMessage> = useMemo(() => {
    const greetingMessage: AiChatMessage = {
      id: "greeting",
      role: "assistant",
      content: greeting,
    };
    return messages.length === 0
      ? [greetingMessage]
      : [greetingMessage, ...messages];
  }, [messages, greeting]);

  const userTurnCount = useMemo(
    () => messages.filter((m) => m.role === "user").length,
    [messages],
  );

  // Auto-scroll the history to the latest turn whenever a message is appended.
  useEffect(() => {
    const el = historyRef.current;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
  }, [transcript.length, isSending]);

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

  // Abort any in-flight chat request on unmount so the LLM server doesn't keep
  // generating tokens for a card that's gone.
  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  const nextId = useCallback(() => {
    counterRef.current += 1;
    return `msg-${counterRef.current}`;
  }, []);

  const appendAssistantError = useCallback(
    (content: string) => {
      setMessages((prev) => [
        ...prev,
        { id: nextId(), role: "assistant", content, isError: true },
      ]);
    },
    [nextId],
  );

  const sendDraft = useCallback(async () => {
    const trimmed = draft.trim();
    if (!trimmed || disabled || isSending) return;

    // Client-side turn cap: a filet, not a cage. Stop sending and nudge the
    // rider to launch with what the model already understood (ADR-045).
    if (userTurnCount >= MAX_USER_TURNS) {
      setCapReached(true);
      return;
    }

    const userMessage: AiChatMessage = {
      id: nextId(),
      role: "user",
      content: trimmed,
    };
    // Build the wire transcript from the next state, not the stale closure.
    const wire = [...messages, userMessage].map((m) => ({
      role: m.role,
      content: m.content,
    }));

    setMessages((prev) => [...prev, userMessage]);
    setDraft("");
    setIsSending(true);

    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    const result = await sendAiChat(wire, controller.signal);

    // A newer turn (or unmount) superseded this request — drop its outcome.
    if (controller.signal.aborted) return;

    switch (result.status) {
      case "ok":
        setMessages((prev) => [
          ...prev,
          { id: nextId(), role: "assistant", content: result.data.reply },
        ]);
        // Merge, don't overwrite: the recap accumulates across turns, so a turn
        // that returns a partial (or, on a parse miss, empty) `collected` never
        // wipes a departure already known — which would empty the recap and
        // disable the launch button mid-conversation (recette #649).
        setCollected((prev) => ({ ...prev, ...result.data.collected }));
        setReadyToGenerate(result.data.readyToGenerate);
        setConfigErrorKey(null);
        break;
      case "not_configured":
        setConfigErrorKey("errorNotConfigured");
        break;
      case "invalid_token":
        setConfigErrorKey("errorInvalidToken");
        break;
      case "quota_exceeded":
        setConfigErrorKey("errorQuotaExceeded");
        break;
      case "rate_limited":
        appendAssistantError(t("errorRateLimit"));
        break;
      case "unavailable":
        appendAssistantError(t("errorUnavailable"));
        break;
      default:
        appendAssistantError(t("errorGeneric"));
    }
    setIsSending(false);
  }, [
    appendAssistantError,
    disabled,
    draft,
    isSending,
    messages,
    nextId,
    t,
    userTurnCount,
  ]);

  const handleKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLTextAreaElement>) => {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        void sendDraft();
      }
    },
    [sendDraft],
  );

  const canLaunch = hasGeocodableStart(collected) && !disabled;

  const handleLaunch = useCallback(() => {
    if (disabled || !hasGeocodableStart(collected)) return;

    const userTurns = messages
      .filter((m) => m.role === "user")
      .map((m) => m.content);
    const brief = buildBrief(collected, userTurns);
    if (!brief) return;

    onLaunchGeneration?.(brief);
    if (typeof document !== "undefined") {
      document.dispatchEvent(
        new CustomEvent<AiChatLaunchEventDetail>(AI_CHAT_LAUNCH_EVENT, {
          detail: { brief },
        }),
      );
    }
  }, [collected, disabled, messages, onLaunchGeneration]);

  const recapEntries = RECAP_FIELDS.map(({ key, labelKey }) => ({
    labelKey,
    value: formatRecapValue(collected[key]),
  })).filter((e) => e.value !== null);

  const canSendDraft =
    draft.trim().length > 0 && !disabled && !isSending && !capReached;

  return (
    <div
      className="flex flex-col gap-4 w-full lg:flex-row lg:items-stretch"
      data-testid="ai-chat-card"
      data-disabled={disabled || undefined}
      data-ready={readyToGenerate || undefined}
    >
      <div className="flex flex-col gap-4 flex-1 min-w-0">
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
            "h-64 max-h-[40vh]",
          )}
        >
          {transcript.map((message) => (
            <ChatBubble key={message.id} message={message} />
          ))}
          {isSending && <TypingBubble label={t("thinking")} />}
        </div>

        {configErrorKey && (
          <div
            data-testid="ai-chat-not-configured"
            role="alert"
            className="flex flex-col gap-2 rounded-md border border-brand/30 bg-brand/5 px-3 py-2 text-sm text-foreground"
          >
            <span className="flex items-center gap-2">
              <Sparkles
                className="h-4 w-4 shrink-0 text-brand"
                aria-hidden="true"
              />
              <span>{t(configErrorKey)}</span>
            </span>
            <Link
              href="/account/settings#ai"
              className="self-start text-sm font-medium text-brand hover:underline"
              data-testid="ai-chat-configure-cta"
            >
              {t("configureCta")}
            </Link>
          </div>
        )}

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
              disabled={disabled || capReached}
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
              onClick={() => void sendDraft()}
              disabled={!canSendDraft}
              aria-label={t("sendAriaLabel")}
              data-testid="ai-chat-send"
              className="shrink-0"
            >
              <Send className="h-4 w-4" aria-hidden="true" />
            </Button>
          </div>
          {capReached ? (
            <p
              className="text-xs text-amber-700 dark:text-amber-400"
              data-testid="ai-chat-cap-hint"
              role="status"
            >
              {t("capReached")}
            </p>
          ) : (
            <p className="text-xs text-muted-foreground/80">{t("inputHint")}</p>
          )}
        </div>
      </div>

      <aside
        className="flex flex-col gap-3 lg:w-64 lg:shrink-0"
        data-testid="ai-chat-recap"
        aria-label={t("recapAriaLabel")}
      >
        <div className="rounded-lg border bg-card p-3 shadow-sm">
          <h3 className="text-sm font-semibold text-foreground">
            {t("recapTitle")}
          </h3>
          {recapEntries.length === 0 ? (
            <p className="mt-2 text-xs text-muted-foreground">
              {t("recapEmpty")}
            </p>
          ) : (
            <dl className="mt-2 flex flex-col gap-1.5 text-sm">
              {recapEntries.map((entry) => (
                <div
                  key={entry.labelKey}
                  className="flex justify-between gap-2"
                  data-testid={`ai-chat-recap-${entry.labelKey}`}
                >
                  <dt className="text-muted-foreground shrink-0">
                    {t(`recap.${entry.labelKey}`)}
                  </dt>
                  <dd className="text-right font-medium text-foreground break-words">
                    {entry.value}
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>

        <Button
          type="button"
          onClick={handleLaunch}
          disabled={!canLaunch}
          data-testid="ai-chat-launch"
          data-recommended={(readyToGenerate && canLaunch) || undefined}
          className={cn(
            "w-full bg-brand-fill text-white hover:bg-brand-fill-hover",
            "disabled:bg-brand-fill/50",
            readyToGenerate &&
              canLaunch &&
              "ring-2 ring-brand ring-offset-2 animate-pulse",
          )}
        >
          {t("launchLabel")}
        </Button>
        {readyToGenerate && canLaunch && (
          <p
            className="text-xs text-brand"
            data-testid="ai-chat-launch-hint"
            role="status"
          >
            {t("launchRecommended")}
          </p>
        )}
        {!canLaunch && !disabled && (
          <p className="text-xs text-muted-foreground/80">
            {t("launchNeedsStart")}
          </p>
        )}
      </aside>
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
      data-error={message.isError || undefined}
      className={cn("flex w-full", isUser ? "justify-end" : "justify-start")}
    >
      <div
        className={cn(
          "max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-relaxed shadow-sm",
          isUser
            ? "bg-brand-fill text-white rounded-br-sm"
            : message.isError
              ? "bg-destructive/10 border border-destructive/40 text-destructive rounded-bl-sm"
              : "bg-background border border-border text-foreground rounded-bl-sm",
        )}
      >
        {message.content}
      </div>
    </div>
  );
}

function TypingBubble({ label }: { label: string }) {
  return (
    <div
      className="flex w-full justify-start"
      data-testid="ai-chat-typing"
      aria-hidden="true"
    >
      <div className="flex items-center gap-2 rounded-2xl rounded-bl-sm border border-border bg-background px-3 py-2 text-sm text-muted-foreground shadow-sm">
        <Loader2 className="h-4 w-4 animate-spin" />
        <span>{label}</span>
      </div>
    </div>
  );
}

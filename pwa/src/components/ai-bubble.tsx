"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Sparkles } from "lucide-react";
import { useShallow } from "zustand/react/shallow";
import { useUiStore } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";
import { AiChatPanel } from "@/components/ai-chat-panel";
import { ChatOfflineBadge } from "@/components/chat/ChatOfflineBadge";
import { useOnlineStatus } from "@/hooks/use-online-status";
import { trackEvent } from "@/lib/plausible";
import { cn } from "@/lib/utils";

/**
 * Floating AI assistant bubble + chat panel.
 *
 * - Bubble: bottom-right floating action button with a "Nouveau" badge on the
 *   first visit (persisted via `localStorage`). Visible as soon as a trip is
 *   loaded (ADR-043 — no analysis-phase gate); hidden only on the welcome /
 *   loader screens.
 * - Panel: anchored 400 × 500 chat on desktop, full-screen sheet on mobile.
 *
 * The bubble also keeps {@link useUiStore.currentContext} in sync with the
 * currently consulted stage (derived from {@link useUiStore.activeDayNumber})
 * so every chat message carries the right `context.currentStage` payload.
 */
export function AiBubble() {
  const t = useTranslations("aiBubble");
  const tOffline = useTranslations("chat.offline");
  const isOnline = useOnlineStatus();

  const { isBubbleOpen, hasSeenBubble, toggleBubble, closeBubble } = useUiStore(
    useShallow((s) => ({
      isBubbleOpen: s.isBubbleOpen,
      hasSeenBubble: s.hasSeenBubble,
      toggleBubble: s.toggleBubble,
      closeBubble: s.closeBubble,
    })),
  );

  const activeDayNumber = useUiStore((s) => s.activeDayNumber);
  const setCurrentContext = useUiStore((s) => s.setCurrentContext);

  const trip = useTripStore((s) => s.trip);
  const aiCapability = useUiStore((s) => s.aiCapability);

  useEffect(() => {
    setCurrentContext({ currentStage: activeDayNumber ?? null });
  }, [activeDayNumber, setCurrentContext]);

  // Track only the open transition so closing the panel does not emit an event.
  const handleToggle = () => {
    if (!isBubbleOpen) trackEvent("ai_chat_opened");
    toggleBubble();
  };

  // Hidden until a trip is loaded (welcome / loader screens). Once the trip
  // view renders it is always available (ADR-043 — no analysis-phase gate).
  if (!trip) return null;

  // Disabled-but-visible affordance when the network is down, the AI
  // tier is unreachable (#304), or no provider is configured on the account
  // (ADR-042): same visual treatment, distinct title + data attribute. The
  // not-configured state links to the settings via its title.
  const isAiDown = !aiCapability.available;
  const isNotConfigured = !aiCapability.configured;
  const isUnavailable = !isOnline || isAiDown || isNotConfigured;

  // When the only reason the bubble is unavailable is a missing provider
  // (online + tier reachable), render it as a link to the account settings so
  // the CTA is actionable rather than a dead button.
  const linksToSettings = isNotConfigured && isOnline && !isAiDown;

  const bubbleClassName = cn(
    "fixed bottom-6 right-6 z-30 inline-flex items-center justify-center",
    "h-14 w-14 rounded-full bg-brand-fill text-white shadow-lg",
    "hover:bg-brand-fill-hover transition-transform hover:scale-105",
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-hover focus-visible:ring-offset-2",
    isUnavailable &&
      !linksToSettings &&
      "cursor-not-allowed opacity-60 hover:scale-100 hover:bg-brand-fill",
    linksToSettings && "opacity-80",
  );

  const title = !isOnline
    ? tOffline("label")
    : isAiDown
      ? t("unavailableTitle")
      : isNotConfigured
        ? t("notConfiguredTitle")
        : undefined;

  return (
    <>
      {linksToSettings ? (
        <Link
          href="/account/settings#ai"
          aria-label={t("notConfiguredTitle")}
          data-testid="ai-bubble"
          data-not-configured=""
          title={title}
          className={bubbleClassName}
        >
          <Sparkles className="h-6 w-6" aria-hidden="true" />
          <span className="sr-only">{t("label")}</span>
        </Link>
      ) : (
        <button
          type="button"
          onClick={isUnavailable ? undefined : handleToggle}
          aria-disabled={isUnavailable}
          aria-label={isBubbleOpen ? t("closeAria") : t("openAria")}
          aria-expanded={isBubbleOpen}
          aria-controls="ai-chat-panel"
          data-testid="ai-bubble"
          data-open={isBubbleOpen || undefined}
          data-offline={!isOnline || undefined}
          data-ai-down={(isOnline && isAiDown) || undefined}
          title={title}
          className={bubbleClassName}
        >
          <Sparkles className="h-6 w-6" aria-hidden="true" />
          {!isOnline && <ChatOfflineBadge />}
          {isOnline && !isAiDown && !hasSeenBubble && (
            <span
              data-testid="ai-bubble-badge"
              className={cn(
                "absolute -top-1 -right-1 inline-flex items-center justify-center",
                "rounded-full bg-red-500 text-white text-[10px] font-semibold",
                "px-1.5 py-0.5 shadow-sm",
              )}
            >
              {t("newBadge")}
            </span>
          )}
          <span className="sr-only">{t("label")}</span>
        </button>
      )}

      {isBubbleOpen && !isUnavailable && <AiChatPanel onClose={closeBubble} />}
    </>
  );
}

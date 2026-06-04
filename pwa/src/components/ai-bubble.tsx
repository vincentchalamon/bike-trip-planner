"use client";

import { useEffect } from "react";
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
 *   first visit (persisted via `localStorage`). Hidden during Acte 2 (the
 *   narrative analysis screen) so it does not compete with the progress UI.
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

  const isAnalysisPhaseActive = useUiStore((s) => s.isAnalysisPhaseActive);
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

  if (!trip || isAnalysisPhaseActive) return null;
  // AI disabled by config (NEXT_PUBLIC_AI_ENABLED=0) — hide the assistant entirely.
  if (!aiCapability.enabled) return null;

  // Disabled affordance when the network is down OR the (enabled) AI tier is
  // unreachable: same visual treatment, distinct title + data attribute (#304).
  const isAiDown = !aiCapability.available;
  const isUnavailable = !isOnline || isAiDown;

  return (
    <>
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
        title={
          !isOnline
            ? tOffline("label")
            : isAiDown
              ? t("unavailableTitle")
              : undefined
        }
        className={cn(
          "fixed bottom-6 right-6 z-30 inline-flex items-center justify-center",
          "h-14 w-14 rounded-full bg-brand text-white shadow-lg",
          "hover:bg-brand-hover transition-transform hover:scale-105",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-hover focus-visible:ring-offset-2",
          isUnavailable &&
            "cursor-not-allowed opacity-60 hover:scale-100 hover:bg-brand",
        )}
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

      {isBubbleOpen && <AiChatPanel onClose={closeBubble} />}
    </>
  );
}

"use client";

import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { LifeBuoy } from "lucide-react";
import { useShallow } from "zustand/react/shallow";
import { useUiStore } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";
import { InRidePanel } from "@/components/in-ride/InRidePanel";
import { ChatOfflineBadge } from "@/components/chat/ChatOfflineBadge";
import { useOnlineStatus } from "@/hooks/use-online-status";
import { trackEvent } from "@/lib/plausible";
import { cn } from "@/lib/utils";

/**
 * Floating in-ride help bubble + guided panel (#935).
 *
 * Replaces the AI chat bubble: it reads no AI capability and is open to every
 * rider with no token. Gated only on a loaded trip and the network state — a
 * loss of connectivity disables the button and surfaces the offline badge.
 * Visible as soon as the trip view renders; hidden on the welcome / loader
 * screens via the `trip` guard.
 */
export function InRideBubble() {
  const t = useTranslations("chat.inRide");
  const isOnline = useOnlineStatus();

  const { isBubbleOpen, toggleBubble, closeBubble } = useUiStore(
    useShallow((s) => ({
      isBubbleOpen: s.isBubbleOpen,
      toggleBubble: s.toggleBubble,
      closeBubble: s.closeBubble,
    })),
  );

  const trip = useTripStore((s) => s.trip);

  const buttonRef = useRef<HTMLButtonElement>(null);
  const wasOpenRef = useRef(false);

  // Return focus to the bubble when the panel closes (accessibility).
  useEffect(() => {
    if (!isBubbleOpen && wasOpenRef.current) buttonRef.current?.focus();
    wasOpenRef.current = isBubbleOpen;
  }, [isBubbleOpen]);

  // Hidden until a trip is loaded (welcome / loader screens).
  if (!trip) return null;

  const handleToggle = () => {
    if (!isBubbleOpen) trackEvent("in_ride_opened");
    toggleBubble();
  };

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        onClick={isOnline ? handleToggle : undefined}
        disabled={!isOnline}
        aria-disabled={!isOnline}
        aria-label={isBubbleOpen ? t("closeAria") : t("openAria")}
        aria-expanded={isBubbleOpen}
        aria-controls="in-ride-panel"
        data-testid="in-ride-bubble"
        data-open={isBubbleOpen || undefined}
        data-offline={!isOnline || undefined}
        title={!isOnline ? t("openAria") : undefined}
        className={cn(
          "fixed bottom-6 right-6 z-30 inline-flex items-center justify-center",
          "h-14 w-14 rounded-full bg-brand-fill text-white shadow-lg",
          "hover:bg-brand-fill-hover transition-transform hover:scale-105",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-hover focus-visible:ring-offset-2",
          !isOnline &&
            "cursor-not-allowed opacity-60 hover:scale-100 hover:bg-brand-fill",
        )}
      >
        <LifeBuoy className="h-6 w-6" aria-hidden="true" />
        {!isOnline && <ChatOfflineBadge />}
      </button>

      {isBubbleOpen && isOnline && <InRidePanel onClose={closeBubble} />}
    </>
  );
}

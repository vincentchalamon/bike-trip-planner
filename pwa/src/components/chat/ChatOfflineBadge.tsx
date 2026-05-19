"use client";

import { useTranslations } from "next-intl";
import { WifiOff } from "lucide-react";
import { cn } from "@/lib/utils";

interface ChatOfflineBadgeProps {
  className?: string;
}

/**
 * Small visual indicator overlaid on the floating chat button when the user
 * has lost network connectivity. The badge is purely decorative for sighted
 * users; the parent button is responsible for the matching `aria-disabled`
 * state and tooltip wording.
 */
export function ChatOfflineBadge({ className }: ChatOfflineBadgeProps) {
  const t = useTranslations("chat.offline");

  return (
    <span
      data-testid="chat-offline-badge"
      role="img"
      aria-label={t("label")}
      title={t("label")}
      className={cn(
        "absolute -top-1 -right-1 inline-flex items-center justify-center",
        "h-5 w-5 rounded-full bg-neutral-700 text-white shadow-sm",
        "ring-2 ring-white",
        className,
      )}
    >
      <WifiOff className="h-3 w-3" aria-hidden="true" />
    </span>
  );
}

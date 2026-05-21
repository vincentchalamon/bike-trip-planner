"use client";

import { WifiOff } from "lucide-react";
import { cn } from "@/lib/utils";

interface ChatOfflineBadgeProps {
  className?: string;
}

/**
 * Small visual indicator overlaid on the floating chat button when the user
 * has lost network connectivity. The badge is purely decorative — the parent
 * button already owns the accessible name, the `aria-disabled` state, and the
 * tooltip wording — so we hide it from assistive technologies to avoid a
 * duplicate "offline" announcement in NVDA / JAWS browse mode.
 */
export function ChatOfflineBadge({ className }: ChatOfflineBadgeProps) {
  return (
    <span
      data-testid="chat-offline-badge"
      aria-hidden="true"
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

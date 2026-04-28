"use client";

import { Sparkles, Shuffle, Compass, X, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import type { AlertActionData } from "@/lib/validation/schemas";

/**
 * Maps each contextual action kind (defined backend-side, see #281/#282) to
 * its Lucide icon. The four kinds — `auto_fix`, `detour`, `navigate`,
 * `dismiss` — are exposed as visual buttons replacing the dot indicators
 * previously displayed next to each alert.
 */
const ACTION_ICON: Record<AlertActionData["kind"], LucideIcon> = {
  auto_fix: Sparkles,
  detour: Shuffle,
  navigate: Compass,
  dismiss: X,
};

interface AlertActionButtonProps {
  action: AlertActionData;
  onClick: () => void;
  /** When true, the button is rendered disabled (e.g. handler not wired yet). */
  disabled?: boolean;
  /** Optional override for the accessible label (defaults to action.label). */
  ariaLabel?: string;
  className?: string;
}

/**
 * Single contextual action button rendered next to an alert. Replaces the
 * previous dot indicators with a labelled, accessible button driven by the
 * `action` field returned by the backend.
 *
 * The handler is opaque to this component: callers wire it up to the
 * appropriate behaviour (dismiss in component state, auto-fix backend call,
 * external navigation, on-map detour preview, etc.).
 */
export function AlertActionButton({
  action,
  onClick,
  disabled = false,
  ariaLabel,
  className,
}: AlertActionButtonProps) {
  const Icon = ACTION_ICON[action.kind];

  return (
    <Button
      type="button"
      variant="outline"
      size="xs"
      onClick={onClick}
      disabled={disabled}
      aria-label={ariaLabel ?? action.label}
      data-testid="alert-action-button"
      data-action-kind={action.kind}
      className={cn(
        "h-6 px-2 text-xs text-emerald-700 dark:text-emerald-400 border-emerald-300 dark:border-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/20",
        className,
      )}
    >
      <Icon className="h-3 w-3" aria-hidden="true" />
      <span>{action.label}</span>
    </Button>
  );
}

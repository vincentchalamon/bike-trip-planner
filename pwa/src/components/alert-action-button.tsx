"use client";

import { Sparkles, Shuffle, Map, X, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { trackEvent } from "@/lib/plausible";
import type { AlertActionData } from "@btp/core";

/**
 * Maps each contextual action kind (defined backend-side, see #281/#282) to
 * its Lucide icon. The map stays exhaustive over the backend `kind` union;
 * only the wired kinds (`navigate`, `dismiss`) are ever rendered as a button
 * (see `AlertList`), the others are omitted upstream.
 */
const ACTION_ICON: Record<AlertActionData["kind"], LucideIcon> = {
  auto_fix: Sparkles,
  detour: Shuffle,
  // `navigate` highlights the concerned road stretch on the internal map
  // (issue #982): a map icon, not the old external-link compass.
  navigate: Map,
  dismiss: X,
};

interface AlertActionButtonProps {
  action: AlertActionData;
  onClick: () => void;
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
 * appropriate behaviour (dismiss in component state, navigate to highlight the
 * concerned road stretch on the map).
 */
export function AlertActionButton({
  action,
  onClick,
  ariaLabel,
  className,
}: AlertActionButtonProps) {
  const Icon = ACTION_ICON[action.kind];

  return (
    <Button
      type="button"
      variant="outline"
      size="xs"
      onClick={() => {
        trackEvent("alert_action_clicked", { kind: action.kind });
        onClick();
      }}
      aria-label={ariaLabel ?? action.label}
      data-testid="alert-action-button"
      data-action-kind={action.kind}
      className={cn(
        "h-6 px-2 text-xs text-emerald-700 dark:text-emerald-400 border-emerald-300 dark:border-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/20",
        className,
      )}
    >
      <span>{action.label}</span>
      <Icon className="h-3 w-3" aria-hidden="true" />
    </Button>
  );
}

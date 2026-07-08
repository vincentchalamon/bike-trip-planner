"use client";

import type { ReactNode } from "react";
import { Info } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";

interface InfoTooltipProps {
  /** Tooltip body — shown on hover/focus. */
  content: ReactNode;
  /** Accessible label for the trigger (falls back to a plain-text `content`). */
  label?: string;
  /** Side the tooltip opens on. */
  side?: "top" | "right" | "bottom" | "left";
  /** Icon size in `rem` via Tailwind size classes. */
  iconClassName?: string;
  /** Extra classes for the tooltip content box. */
  contentClassName?: string;
  className?: string;
  testId?: string;
}

/**
 * Factored "ⓘ info + tooltip" affordance: a `cursor-help` Info icon wrapped in a
 * Radix tooltip. Consolidates the pattern previously copy-pasted across
 * StageStatsRow (budget cell) and the difficulty pill.
 */
export function InfoTooltip({
  content,
  label,
  side = "top",
  iconClassName = "h-3.5 w-3.5",
  contentClassName = "max-w-[15rem]",
  className,
  testId,
}: InfoTooltipProps) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          className={cn(
            "flex items-center text-muted-foreground hover:text-foreground transition-colors cursor-help",
            className,
          )}
          aria-label={
            label ?? (typeof content === "string" ? content : undefined)
          }
          data-testid={testId}
        >
          <Info className={iconClassName} aria-hidden="true" />
        </button>
      </TooltipTrigger>
      <TooltipContent side={side} className={contentClassName}>
        {content}
      </TooltipContent>
    </Tooltip>
  );
}

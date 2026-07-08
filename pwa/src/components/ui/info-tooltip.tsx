"use client";

import type { ReactNode } from "react";
import { Info } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";

interface InfoTooltipBaseProps {
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
 * `label` (the trigger's accessible name) is optional when `content` is a plain
 * string — it falls back to that string. When `content` is a rich `ReactNode`,
 * `label` is required, so the icon-only trigger can never end up without an
 * accessible name.
 */
type InfoTooltipProps = InfoTooltipBaseProps &
  (
    | { content: string; label?: string }
    | { content: Exclude<ReactNode, string>; label: string }
  );

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

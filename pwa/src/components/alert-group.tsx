"use client";

import { useState, useCallback, type ReactNode } from "react";
import { ChevronDown, ChevronRight } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";

export type AlertSeverity = "critical" | "warning" | "nudge";

interface AlertGroupProps {
  severity: AlertSeverity;
  count: number;
  /** Whether the group is expanded by default. Critical defaults to true. */
  defaultExpanded?: boolean;
  children: ReactNode;
}

/** Severity-specific styling for the group header. */
const HEADER_TONE: Record<AlertSeverity, string> = {
  critical:
    "text-red-800 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300",
  warning:
    "text-orange-800 dark:text-orange-400 hover:text-orange-900 dark:hover:text-orange-300",
  nudge:
    "text-blue-800 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300",
};

/**
 * Collapsible alert group, rendered once per severity bucket. The header
 * exposes a chevron + a translated title + the alert count. Critical groups
 * are expanded by default while warning and nudge groups start collapsed,
 * surfacing the most urgent items first while keeping the section compact.
 *
 * Accessibility:
 * - The toggle is a real `<button>` with `aria-expanded` + `aria-controls`.
 * - The body has a stable id linked to the toggle and is hidden via
 *   `hidden` (not just CSS) when collapsed, ensuring assistive tech skips it.
 */
export function AlertGroup({
  severity,
  count,
  defaultExpanded,
  children,
}: AlertGroupProps) {
  const t = useTranslations("alertGroup");
  const expandedByDefault = defaultExpanded ?? severity === "critical";
  const [expanded, setExpanded] = useState(expandedByDefault);

  const toggle = useCallback(() => setExpanded((prev) => !prev), []);

  if (count === 0) return null;

  const bodyId = `alert-group-body-${severity}`;
  const ChevronIcon = expanded ? ChevronDown : ChevronRight;

  return (
    <div data-testid={`alert-group-${severity}`} className="flex flex-col">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={expanded}
        aria-controls={bodyId}
        data-testid={`alert-group-toggle-${severity}`}
        className={cn(
          "flex w-full items-center gap-1.5 py-1 text-xs font-semibold transition-colors cursor-pointer",
          HEADER_TONE[severity],
        )}
      >
        <ChevronIcon
          className="h-3.5 w-3.5 shrink-0 transition-transform"
          aria-hidden="true"
        />
        <span className="uppercase tracking-wide">
          {t(`title.${severity}`)}
        </span>
        <span
          className="font-normal opacity-80"
          data-testid={`alert-group-count-${severity}`}
        >
          ({count})
        </span>
      </button>

      <div
        id={bodyId}
        hidden={!expanded}
        data-testid={`alert-group-body-${severity}`}
        className="mt-1 ml-1 flex flex-col gap-2"
      >
        {children}
      </div>
    </div>
  );
}

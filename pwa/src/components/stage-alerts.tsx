"use client";

import { useState, useCallback } from "react";
import { ChevronDown, ChevronUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { AlertList } from "@/components/alert-list";
import { Button } from "@/components/ui/button";
import type { AlertData } from "@/lib/validation/schemas";

const INITIAL_VISIBLE_COUNT = 3;

const severityOrder = { critical: 0, warning: 1, nudge: 2 } as const;

function sortBySeverity(alerts: AlertData[]): AlertData[] {
  return [...alerts].sort(
    (a, b) => (severityOrder[a.type] ?? 2) - (severityOrder[b.type] ?? 2),
  );
}

interface StageAlertsProps {
  alerts: AlertData[];
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
}

/**
 * Collapsible, severity-sorted, paginated alert section for a stage.
 *
 * - Sorted by severity: critical → warning → nudge.
 * - First 3 alerts visible by default; a "Show N more" button reveals the rest.
 * - The entire section is collapsible via a header toggle (▼/▲).
 * - Starts expanded (no AI summary at this stage to justify collapsing).
 */
export function StageAlerts({ alerts, onAddPoiWaypoint }: StageAlertsProps) {
  const t = useTranslations("stageAlerts");
  const [expanded, setExpanded] = useState(true);
  const [showAll, setShowAll] = useState(false);

  const toggleExpanded = useCallback(() => setExpanded((prev) => !prev), []);
  const toggleShowAll = useCallback(() => setShowAll((prev) => !prev), []);

  if (alerts.length === 0) return null;

  const sorted = sortBySeverity(alerts);
  const visible = showAll ? sorted : sorted.slice(0, INITIAL_VISIBLE_COUNT);
  const hiddenCount = sorted.length - INITIAL_VISIBLE_COUNT;

  return (
    <div data-testid="stage-alerts">
      {/* Section header */}
      <button
        type="button"
        className="flex w-full items-center justify-between gap-2 py-1 text-sm font-medium text-foreground hover:text-foreground/80 transition-colors"
        onClick={toggleExpanded}
        aria-expanded={expanded}
        data-testid="stage-alerts-toggle"
      >
        <span data-testid="stage-alerts-count">
          {t("title", { count: alerts.length })}
        </span>
        {expanded ? (
          <ChevronUp
            className="h-4 w-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
          />
        ) : (
          <ChevronDown
            className="h-4 w-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
          />
        )}
      </button>

      {/* Collapsible body */}
      {expanded && (
        <div className="mt-2 space-y-1" data-testid="stage-alerts-body">
          <AlertList alerts={visible} onAddPoiWaypoint={onAddPoiWaypoint} />

          {/* "Show N more" / "Show less" pagination */}
          {hiddenCount > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="mt-1 h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
              onClick={toggleShowAll}
              data-testid="stage-alerts-show-more"
            >
              {showAll ? t("showLess") : t("showMore", { count: hiddenCount })}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

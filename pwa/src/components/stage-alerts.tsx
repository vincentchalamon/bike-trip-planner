"use client";

import { useState, useCallback } from "react";
import { ChevronDown, ChevronUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { AlertList } from "@/components/alert-list";
import type { AlertData } from "@/lib/validation/schemas";

interface StageAlertsProps {
  alerts: AlertData[];
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
}

/**
 * Section wrapper for the per-stage alerts. The section itself is collapsible
 * (`stage-alerts-toggle`); inside, alerts are rendered by `AlertList`, which
 * groups them by severity (Critical expanded by default; Warning + Nudge
 * collapsed) — see #397.
 */
export function StageAlerts({ alerts, onAddPoiWaypoint }: StageAlertsProps) {
  const t = useTranslations("stageAlerts");
  const [expanded, setExpanded] = useState(true);

  const toggleExpanded = useCallback(() => setExpanded((prev) => !prev), []);

  if (alerts.length === 0) return null;

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

      {/* Collapsible body — alerts are grouped by severity inside */}
      {expanded && (
        <div className="mt-2" data-testid="stage-alerts-body">
          <AlertList alerts={alerts} onAddPoiWaypoint={onAddPoiWaypoint} />
        </div>
      )}
    </div>
  );
}

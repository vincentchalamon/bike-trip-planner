"use client";

import { useState, useCallback } from "react";
import { AlertTriangle, ChevronDown, ChevronUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
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
      {/* Section header — mirrors the events panel layout (icon + count). */}
      <Separator className="mt-4 mb-3" />
      <Button
        variant="ghost"
        className="w-full justify-between px-0 h-auto py-1 text-sm font-medium hover:bg-transparent cursor-pointer"
        onClick={toggleExpanded}
        aria-expanded={expanded}
        data-testid="stage-alerts-toggle"
      >
        <span className="flex items-center gap-1.5">
          <AlertTriangle className="h-4 w-4 text-muted-foreground" />
          <span data-testid="stage-alerts-count">
            {t("heading", { count: alerts.length })}
          </span>
        </span>
        {expanded ? (
          <ChevronUp className="h-4 w-4 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        )}
      </Button>

      {/* Collapsible body — alerts are grouped by severity inside. Left
          padding lines the content up with the header title (past the icon)
          and right padding gives it breathing room. */}
      {expanded && (
        <div className="mt-2 pl-[22px] pr-1" data-testid="stage-alerts-body">
          <AlertList alerts={alerts} onAddPoiWaypoint={onAddPoiWaypoint} />
        </div>
      )}
    </div>
  );
}

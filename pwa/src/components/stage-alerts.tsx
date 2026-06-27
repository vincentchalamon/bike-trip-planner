"use client";

import { useState, useCallback } from "react";
import {
  AlertTriangle,
  ChevronDown,
  ChevronUp,
  Landmark,
} from "lucide-react";
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
 * Per-stage alerts + cultural recommendations. Cultural-POI suggestions arrive
 * tagged `source: "cultural_poi"` (live via Mercure); they are split out of the
 * "Alertes" list into a dedicated "Recommandations culturelles" section so they
 * no longer inflate the alert count (recette #649 round 7). Each section is
 * collapsible; inside, `AlertList` groups by severity (#397).
 */
export function StageAlerts({ alerts, onAddPoiWaypoint }: StageAlertsProps) {
  const t = useTranslations("stageAlerts");
  const [alertsExpanded, setAlertsExpanded] = useState(true);
  const [culturalExpanded, setCulturalExpanded] = useState(true);

  const toggleAlerts = useCallback(() => setAlertsExpanded((p) => !p), []);
  const toggleCultural = useCallback(() => setCulturalExpanded((p) => !p), []);

  const cultural = alerts.filter((a) => a.source === "cultural_poi");
  const others = alerts.filter((a) => a.source !== "cultural_poi");

  if (alerts.length === 0) return null;

  return (
    <>
      {others.length > 0 && (
        <div data-testid="stage-alerts">
          <Separator className="mt-4 mb-3" />
          <Button
            variant="ghost"
            className="w-full justify-between px-0 h-auto py-1 text-sm font-medium hover:bg-transparent cursor-pointer"
            onClick={toggleAlerts}
            aria-expanded={alertsExpanded}
            data-testid="stage-alerts-toggle"
          >
            <span className="flex items-center gap-1.5">
              <AlertTriangle className="h-4 w-4 text-muted-foreground" />
              <span data-testid="stage-alerts-count">
                {t("heading", { count: others.length })}
              </span>
            </span>
            {alertsExpanded ? (
              <ChevronUp className="h-4 w-4 text-muted-foreground" />
            ) : (
              <ChevronDown className="h-4 w-4 text-muted-foreground" />
            )}
          </Button>

          {alertsExpanded && (
            <div className="mt-2 pl-[22px] pr-1" data-testid="stage-alerts-body">
              <AlertList alerts={others} onAddPoiWaypoint={onAddPoiWaypoint} />
            </div>
          )}
        </div>
      )}

      {cultural.length > 0 && (
        <div data-testid="stage-cultural">
          <Separator className="mt-4 mb-3" />
          <Button
            variant="ghost"
            className="w-full justify-between px-0 h-auto py-1 text-sm font-medium hover:bg-transparent cursor-pointer"
            onClick={toggleCultural}
            aria-expanded={culturalExpanded}
            data-testid="stage-cultural-toggle"
          >
            <span className="flex items-center gap-1.5">
              <Landmark className="h-4 w-4 text-muted-foreground" />
              <span data-testid="stage-cultural-count">
                {t("culturalHeading", { count: cultural.length })}
              </span>
            </span>
            {culturalExpanded ? (
              <ChevronUp className="h-4 w-4 text-muted-foreground" />
            ) : (
              <ChevronDown className="h-4 w-4 text-muted-foreground" />
            )}
          </Button>

          {culturalExpanded && (
            <div
              className="mt-2 pl-[22px] pr-1"
              data-testid="stage-cultural-body"
            >
              <AlertList alerts={cultural} onAddPoiWaypoint={onAddPoiWaypoint} />
            </div>
          )}
        </div>
      )}
    </>
  );
}

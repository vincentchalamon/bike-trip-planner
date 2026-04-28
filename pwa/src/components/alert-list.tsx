"use client";

import { useState, useCallback, useMemo } from "react";
import { useTranslations } from "next-intl";
import { AlertBadge } from "@/components/alert-badge";
import { AlertGroup, type AlertSeverity } from "@/components/alert-group";
import { AlertActionButton } from "@/components/alert-action-button";
import { Button } from "@/components/ui/button";
import {
  MarkerIcon,
  MARKER_CATEGORY_COLOR,
  resolveCategory,
  UserWaypointIcon,
  CulturalPoiEnrichedIcon,
} from "@/components/Map/icons";
import { cn } from "@/lib/utils";
import type { AlertActionData, AlertData } from "@/lib/validation/schemas";

interface AlertListProps {
  alerts: AlertData[];
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
}

/** Order in which severity groups are rendered, top-down. */
const SEVERITY_ORDER: readonly AlertSeverity[] = [
  "critical",
  "warning",
  "nudge",
] as const;

function isCulturalPoiAlert(alert: AlertData): boolean {
  return (
    alert.source === "cultural_poi" &&
    typeof alert.poiLat === "number" &&
    typeof alert.poiLon === "number"
  );
}

/** Stable identifier for an alert within the current render. */
function alertKey(alert: AlertData, index: number): string {
  return `${alert.type}-${alert.source ?? ""}-${index}-${alert.message}`;
}

/**
 * Group alerts by severity while preserving the order they were received in.
 * Empty buckets are kept so callers can decide whether to render them.
 */
function groupBySeverity(alerts: AlertData[]): Record<AlertSeverity, AlertData[]> {
  const groups: Record<AlertSeverity, AlertData[]> = {
    critical: [],
    warning: [],
    nudge: [],
  };
  for (const alert of alerts) {
    groups[alert.type].push(alert);
  }
  return groups;
}

/**
 * Renders a list of alerts grouped by severity (Critical, Warning, Nudge).
 * Each severity bucket is wrapped in a collapsible `AlertGroup`: Critical is
 * expanded by default while Warning and Nudge start collapsed, mirroring the
 * roadbook UX defined in #397.
 *
 * Each alert exposes its contextual action — when the backend provides one —
 * via an `AlertActionButton` (replacing the previous dot indicators). The
 * `dismiss` action is handled in-component via session-only state and reduces
 * the alert opacity. The other action kinds are stubbed for now and surface
 * as disabled buttons (`auto_fix`, `detour`) or open an OSM map view
 * (`navigate`); wiring them to backend handlers is tracked separately.
 */
export function AlertList({ alerts, onAddPoiWaypoint }: AlertListProps) {
  const t = useTranslations("alertList");
  const [dismissedKeys, setDismissedKeys] = useState<Set<string>>(new Set());

  const handleDismiss = useCallback((key: string) => {
    setDismissedKeys((prev) => {
      const next = new Set(prev);
      next.add(key);
      return next;
    });
  }, []);

  const handleAction = useCallback(
    (key: string, action: AlertActionData) => {
      switch (action.kind) {
        case "dismiss":
          handleDismiss(key);
          break;
        case "navigate": {
          const payload = action.payload as { lat?: number; lon?: number };
          if (
            typeof payload?.lat === "number" &&
            typeof payload?.lon === "number"
          ) {
            window.open(
              `https://www.openstreetmap.org/?mlat=${payload.lat}&mlon=${payload.lon}&zoom=15`,
              "_blank",
              "noopener,noreferrer",
            );
          }
          break;
        }
        case "auto_fix":
          // TODO(#397): wire to backend auto-fix handler (e.g. split-stage call).
          break;
        case "detour":
          // TODO(#397): wire to detour preview on the map.
          break;
      }
    },
    [handleDismiss],
  );

  const groups = useMemo(() => groupBySeverity(alerts), [alerts]);

  if (alerts.length === 0) return null;

  return (
    <div className="flex flex-col gap-3" data-testid="alert-list">
      {SEVERITY_ORDER.map((severity) => {
        const bucket = groups[severity];
        if (bucket.length === 0) return null;

        return (
          <AlertGroup
            key={severity}
            severity={severity}
            count={bucket.length}
          >
            {bucket.map((alert, index) => {
              const key = alertKey(alert, index);
              const isDismissed = dismissedKeys.has(key);
              const action = alert.action ?? null;
              const category = resolveCategory(alert.source);
              const CategoryIcon = category ? MarkerIcon[category] : null;
              const isEnrichedCulturalPoi =
                category === "cultural-poi" &&
                Boolean(
                  alert.description ?? alert.openingHours ?? alert.estimatedPrice,
                );

              // Some action kinds are not wired yet; surface them as disabled.
              const isActionDisabled = Boolean(
                action && action.kind !== "dismiss" && action.kind !== "navigate",
              );

              return (
                <div
                  key={key}
                  className={cn(
                    "flex flex-col gap-1",
                    isDismissed && "opacity-50",
                  )}
                  data-testid={isDismissed ? "alert-dismissed" : undefined}
                >
                  <div className="flex items-center gap-2">
                    {CategoryIcon && (
                      <span
                        className={cn(
                          "shrink-0",
                          category
                            ? MARKER_CATEGORY_COLOR[category]
                            : undefined,
                        )}
                        aria-hidden
                        data-testid={`alert-category-icon-${category}`}
                        data-enriched={
                          isEnrichedCulturalPoi ? "true" : undefined
                        }
                      >
                        {isEnrichedCulturalPoi ? (
                          <CulturalPoiEnrichedIcon size={20} />
                        ) : (
                          <CategoryIcon size={20} />
                        )}
                      </span>
                    )}
                    <AlertBadge type={alert.type} message={alert.message} />
                    {action && !isDismissed && (
                      <AlertActionButton
                        action={action}
                        disabled={isActionDisabled}
                        onClick={() => handleAction(key, action)}
                        className="ml-auto"
                      />
                    )}
                  </div>

                  {isCulturalPoiAlert(alert) && (
                    <div className="ml-1 flex flex-col gap-0.5">
                      {alert.description && (
                        <p
                          className="text-xs text-muted-foreground line-clamp-2"
                          data-testid="poi-description"
                        >
                          {alert.description}
                        </p>
                      )}
                      {alert.openingHours && (
                        <span
                          className="text-xs text-muted-foreground"
                          data-testid="poi-opening-hours"
                        >
                          {alert.openingHours}
                        </span>
                      )}
                      {typeof alert.estimatedPrice === "number" && (
                        <span
                          className="text-xs text-muted-foreground"
                          data-testid="poi-estimated-price"
                        >
                          {alert.estimatedPrice === 0
                            ? t("free")
                            : `${alert.estimatedPrice.toFixed(2)} €`}
                        </span>
                      )}
                      {alert.wikipediaUrl && (
                        <a
                          href={alert.wikipediaUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-xs text-primary flex items-center gap-0.5 hover:underline"
                          data-testid="poi-wikipedia-link"
                        >
                          Voir sur Wikipedia
                        </a>
                      )}
                    </div>
                  )}

                  {isCulturalPoiAlert(alert) && onAddPoiWaypoint && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="ml-1 h-6 px-2 text-xs text-blue-700 dark:text-blue-400 border-blue-300 dark:border-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/20 self-start"
                      onClick={() =>
                        onAddPoiWaypoint(alert.poiLat!, alert.poiLon!)
                      }
                      data-testid="add-poi-to-itinerary"
                    >
                      <UserWaypointIcon size={12} className="mr-1" />
                      {t("addToItinerary")}
                    </Button>
                  )}
                </div>
              );
            })}
          </AlertGroup>
        );
      })}
    </div>
  );
}

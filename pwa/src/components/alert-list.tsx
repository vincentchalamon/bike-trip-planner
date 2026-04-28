import { useState, useCallback } from "react";
import { AlertBadge } from "@/components/alert-badge";
import type { AlertData } from "@/lib/validation/schemas";
import { Button } from "@/components/ui/button";
import { sortBySeverity } from "@/lib/alert-utils";
import { Wrench, Map as MapIcon, Navigation, Check } from "lucide-react";
import { useTranslations } from "next-intl";
import {
  MarkerIcon,
  MARKER_CATEGORY_COLOR,
  resolveCategory,
  UserWaypointIcon,
  CulturalPoiEnrichedIcon,
} from "@/components/Map/icons";
import { cn } from "@/lib/utils";

const actionIcons = {
  auto_fix: Wrench,
  detour: MapIcon,
  navigate: Navigation,
  dismiss: Check,
} as const;

interface AlertListProps {
  alerts: AlertData[];
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
}

function isCulturalPoiAlert(alert: AlertData): boolean {
  return (
    alert.source === "cultural_poi" &&
    typeof alert.poiLat === "number" &&
    typeof alert.poiLon === "number"
  );
}

export function AlertList({ alerts, onAddPoiWaypoint }: AlertListProps) {
  const t = useTranslations("alertList");
  const [dismissedKeys, setDismissedKeys] = useState<Set<string>>(new Set());

  const handleDismiss = useCallback((key: string) => {
    setDismissedKeys((prev) => new Set(prev).add(key));
  }, []);

  if (alerts.length === 0) return null;

  const sorted = sortBySeverity(alerts);

  return (
    <div className="flex flex-col gap-2">
      {sorted.map((alert) => {
        const alertKey = `${alert.type}-${alert.source ?? ""}-${alert.message}`;
        const isDismissed = dismissedKeys.has(alertKey);
        const action = alert.action;
        const ActionIcon = action ? actionIcons[action.kind] : null;
        const category = resolveCategory(alert.source);
        const CategoryIcon = category ? MarkerIcon[category] : null;
        const isEnrichedCulturalPoi =
          isCulturalPoiAlert(alert) &&
          Boolean(
            alert.description ?? alert.openingHours ?? alert.estimatedPrice,
          );

        return (
          <div
            key={alertKey}
            className={isDismissed ? "opacity-50" : undefined}
            data-testid={isDismissed ? "alert-dismissed" : undefined}
          >
            <div className="flex items-center gap-2">
              {CategoryIcon && (
                <span
                  className={cn(
                    "shrink-0",
                    category ? MARKER_CATEGORY_COLOR[category] : undefined,
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
            </div>
            {isCulturalPoiAlert(alert) && (
              <div className="mt-1 ml-1 flex flex-col gap-0.5">
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
                className="mt-1 ml-1 h-6 px-2 text-xs text-blue-700 dark:text-blue-400 border-blue-300 dark:border-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/20"
                onClick={() => onAddPoiWaypoint(alert.poiLat!, alert.poiLon!)}
                data-testid="add-poi-to-itinerary"
              >
                <UserWaypointIcon size={12} className="mr-1" />
                {t("addToItinerary")}
              </Button>
            )}
            {action && !isDismissed && (
              <Button
                variant="outline"
                size="sm"
                className="mt-1 ml-1 h-6 px-2 text-xs text-emerald-700 dark:text-emerald-400 border-emerald-300 dark:border-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/20"
                disabled={
                  action.kind !== "dismiss" && action.kind !== "navigate"
                }
                onClick={() => {
                  if (action.kind === "dismiss") {
                    handleDismiss(alertKey);
                  } else if (action.kind === "navigate") {
                    const { lat, lon } = action.payload as {
                      lat: number;
                      lon: number;
                    };
                    window.open(
                      `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}&zoom=15`,
                      "_blank",
                      "noopener,noreferrer",
                    );
                  }
                }}
                data-testid="alert-action-button"
              >
                {ActionIcon && <ActionIcon className="h-3 w-3 mr-1" />}
                {action.kind === "navigate" &&
                alert.source === "railway_station"
                  ? t("navigateToStation")
                  : action.kind === "navigate" &&
                      alert.source === "border_crossing"
                    ? t("navigateToCrossing")
                    : action.label}
              </Button>
            )}
          </div>
        );
      })}
    </div>
  );
}

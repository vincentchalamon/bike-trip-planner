import { AlertBadge } from "@/components/alert-badge";
import type { AlertData } from "@/lib/validation/schemas";
import { Button } from "@/components/ui/button";
import { MapPin } from "lucide-react";

const severityOrder = { critical: 0, warning: 1, nudge: 2 } as const;

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
  if (alerts.length === 0) return null;

  const sorted = [...alerts].sort(
    (a, b) => (severityOrder[a.type] ?? 2) - (severityOrder[b.type] ?? 2),
  );

  return (
    <div className="flex flex-col gap-2">
      {sorted.map((alert, index) => (
        <div key={`${alert.type}-${alert.source}-${index}`}>
          <AlertBadge
            type={alert.type}
            message={alert.message}
          />
          {isCulturalPoiAlert(alert) && onAddPoiWaypoint && (
            <Button
              variant="outline"
              size="sm"
              className="mt-1 ml-1 h-6 px-2 text-xs text-blue-700 dark:text-blue-400 border-blue-300 dark:border-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/20"
              onClick={() =>
                onAddPoiWaypoint(alert.poiLat!, alert.poiLon!)
              }
              data-testid="add-poi-to-itinerary"
            >
              <MapPin className="h-3 w-3 mr-1" />
              Ajouter à l&apos;itinéraire
            </Button>
          )}
        </div>
      ))}
    </div>
  );
}

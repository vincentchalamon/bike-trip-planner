import { Bike, Mountain } from "lucide-react";

interface TripSummaryProps {
  totalDistance: number | null;
  totalElevation: number | null;
}

export function TripSummary({
  totalDistance,
  totalElevation,
}: TripSummaryProps) {
  if (totalDistance === null && totalElevation === null) return null;

  return (
    <div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
      {totalDistance !== null && (
        <div className="flex items-center gap-1.5">
          <Bike className="h-4 w-4 text-brand" />
          <span>
            Total distance:{" "}
            <span data-testid="total-distance">
              {Math.round(totalDistance)}km
            </span>
          </span>
        </div>
      )}
      {totalElevation !== null && (
        <div className="flex items-center gap-1.5">
          <Mountain className="h-4 w-4 text-orange-500" />
          <span>
            Total elevation:{" "}
            <span data-testid="total-elevation">
              {Math.round(totalElevation)}m
            </span>
          </span>
        </div>
      )}
    </div>
  );
}

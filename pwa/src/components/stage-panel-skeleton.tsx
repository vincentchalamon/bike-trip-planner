"use client";

import { useTranslations } from "next-intl";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Loading skeleton for the right-hand stage detail panel (sprint 27, #402).
 *
 * Mimics the visible structure of {@link StageCard}: stats row, weather card,
 * alerts block. Used by `StageDetailPanel` while the trip is still loading
 * so the layout stays stable. Reuses the shared shimmer animation from
 * `Skeleton` (Tailwind `animate-pulse`) to match `StageSkeleton` (sprint 24).
 */
export function StagePanelSkeleton() {
  const t = useTranslations("stage");

  return (
    <Card
      className="border-border shadow-sm rounded-xl w-full relative"
      data-testid="stage-panel-skeleton"
      aria-busy="true"
      aria-label={t("loadingAlerts")}
    >
      <CardContent className="p-4 md:p-6 space-y-4">
        {/* Header — locations / day chip */}
        <div className="flex flex-col gap-2">
          <Skeleton className="h-5 w-2/3" />
          <Skeleton className="h-4 w-1/2" />
        </div>

        {/* Stats grid — distance / elevation / duration / budget */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 pt-2">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="flex flex-col gap-1.5">
              <Skeleton className="h-3 w-12" />
              <Skeleton className="h-5 w-16" />
            </div>
          ))}
        </div>

        {/* Weather block */}
        <div className="rounded-lg border border-border p-4 space-y-2">
          <Skeleton className="h-4 w-1/3" />
          <div className="flex items-center gap-3">
            <Skeleton className="h-10 w-10 rounded-full" />
            <div className="flex-1 space-y-1.5">
              <Skeleton className="h-3 w-2/5" />
              <Skeleton className="h-3 w-3/5" />
            </div>
          </div>
        </div>

        {/* Alerts block — title + 2 alert rows */}
        <div className="space-y-2 pt-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-12 w-full rounded-md" />
          <Skeleton className="h-12 w-3/4 rounded-md" />
        </div>
      </CardContent>
    </Card>
  );
}

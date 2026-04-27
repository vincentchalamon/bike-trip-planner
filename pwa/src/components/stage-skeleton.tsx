"use client";

import { useTranslations } from "next-intl";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Shimmer skeleton that replaces a {@link StageCard} during an inline
 * recomputation (Acte 3). Matches the minimum dimensions of the real card
 * to avoid layout shift while the backend re-processes the stage.
 */
export function StageSkeleton() {
  const t = useTranslations("stage");

  return (
    <Card
      className="border-border shadow-sm rounded-xl w-full relative"
      data-testid="stage-skeleton"
      aria-busy="true"
      aria-label={t("recomputing")}
    >
      <CardContent className="p-4 md:p-6">
        {/* Status label */}
        <p className="text-xs text-muted-foreground mb-3">{t("recomputing")}</p>

        {/* Locations row */}
        <div className="flex flex-col gap-1.5 mb-3">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-1/2" />
        </div>

        {/* Metadata row */}
        <div className="flex items-center gap-3 flex-wrap mt-3">
          <Skeleton className="h-5 w-1/4" />
          <Skeleton className="h-5 w-1/3" />
          <Skeleton className="h-5 w-1/5" />
        </div>

        {/* Bottom area — mirrors accommodations section height */}
        <div className="mt-6">
          <Skeleton className="h-px w-full mb-4" />
          <Skeleton className="h-10 w-full" />
        </div>
      </CardContent>
    </Card>
  );
}

"use client";

import { useTranslations } from "next-intl";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Loading skeleton for {@link TripSummary} (sprint 27, #402).
 *
 * Shimmer card with three short lines (distance / elevation / dates) plus a
 * wider rectangle (weather / budget). Used when the trip data is still being
 * fetched but the page chrome must remain stable. Mirrors the inline pulses
 * already rendered when `TripSummary` has partial data, but provides a
 * standalone block that can be slotted in front of the real summary.
 */
export function TripSummarySkeleton() {
  const t = useTranslations("tripSummary");

  return (
    <div
      className="space-y-2"
      role="status"
      aria-busy="true"
      aria-label={t("loadingAriaLabel")}
      data-testid="trip-summary-skeleton"
    >
      <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-1">
        <Skeleton className="h-4 w-20" />
        <Skeleton className="h-4 w-28" />
        <Skeleton className="h-4 w-32" />
      </div>
      <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-1">
        <Skeleton className="h-5 w-44 rounded-md" />
        <Skeleton className="h-4 w-24" />
      </div>
    </div>
  );
}

"use client";

import { useTranslations } from "next-intl";
import type { components } from "@btp/core/schema";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

// Taken from the generated schema rather than restated: a hand-written copy silently
// survived the server gaining `failed` (ADR-072), which is the drift the type contract
// exists to catch.
type TripStatus = NonNullable<
  components["schemas"]["Trip.TripListItem.jsonld"]["status"]
>;

export function TripStatusBadge({
  status,
  className,
}: {
  status?: string;
  className?: string;
}) {
  const t = useTranslations("tripList");

  if (status === ("failed" satisfies TripStatus)) {
    return (
      <Badge
        variant="secondary"
        className={cn(
          "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300 border-red-200 dark:border-red-700",
          className,
        )}
        data-testid="status-failed"
      >
        {t("status_failed")}
      </Badge>
    );
  }

  if (status === ("analyzing" satisfies TripStatus)) {
    return (
      <Badge
        variant="secondary"
        className={cn(
          "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border-amber-200 dark:border-amber-700",
          className,
        )}
        data-testid="status-analyzing"
      >
        {t("status_analyzing")}
      </Badge>
    );
  }

  if (status === ("analyzed" satisfies TripStatus)) {
    return (
      <Badge
        variant="secondary"
        className={cn(
          "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 border-green-200 dark:border-green-700",
          className,
        )}
        data-testid="status-analyzed"
      >
        {t("status_analyzed")}
      </Badge>
    );
  }

  return (
    <Badge variant="outline" className={className} data-testid="status-draft">
      {t("status_draft")}
    </Badge>
  );
}

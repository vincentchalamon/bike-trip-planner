"use client";

import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

type TripStatus = "draft" | "analyzing" | "analyzed";

export function TripStatusBadge({
  status,
  className,
}: {
  status?: string;
  className?: string;
}) {
  const t = useTranslations("tripList");

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

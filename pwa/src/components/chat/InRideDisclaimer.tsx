"use client";

import { useTranslations } from "next-intl";
import { Info, ShieldCheck } from "lucide-react";
import { cn } from "@/lib/utils";

interface InRideDisclaimerProps {
  className?: string;
}

/**
 * Fixed disclaimer rendered beneath in-ride POI cards.
 *
 * Reassures the rider that the planning route is untouched (POIs are surfaced
 * for navigation only) and reminds them that the underlying data comes from
 * OpenStreetMap — so they should still double-check on the ground.
 */
export function InRideDisclaimer({ className }: InRideDisclaimerProps) {
  const t = useTranslations("chat.inRide");

  return (
    <aside
      data-testid="in-ride-disclaimer"
      className={cn(
        "flex flex-col gap-1 rounded-lg border border-dashed border-border bg-muted/40 px-3 py-2",
        "text-[11px] leading-snug text-muted-foreground",
        className,
      )}
      aria-live="polite"
    >
      <p className="flex items-center gap-1">
        <ShieldCheck
          className="h-3 w-3 shrink-0 text-brand"
          aria-hidden="true"
        />
        <span>{t("routeNotModified")}</span>
      </p>
      <p className="flex items-center gap-1">
        <Info className="h-3 w-3 shrink-0" aria-hidden="true" />
        <span>{t("osmDisclaimer")}</span>
      </p>
    </aside>
  );
}

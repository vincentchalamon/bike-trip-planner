"use client";

import { useTranslations } from "next-intl";
import { Search } from "lucide-react";
import type { InRideMessage } from "@/store/ui-store";
import { PoiCard } from "@/components/chat/PoiCard";
import { cn } from "@/lib/utils";

interface RecapMessageProps {
  message: InRideMessage;
  /** Whether the "widen search" affordance should be offered under this recap. */
  showWiden: boolean;
  onWiden: () => void;
}

/**
 * Composes the assistant recap from i18n metadata (#935).
 *
 * No server text is ever shown: the five outcomes (results, truncated results,
 * none, cap reached, out of coverage) are rendered from `chat.inRide.recap.*`
 * with the counts carried on the {@link InRideMessage}. POI cards follow, then
 * the fixed OSM disclaimer; a "widen" button doubles the radius when relevant.
 */
export function RecapMessage({
  message,
  showWiden,
  onWiden,
}: RecapMessageProps) {
  const t = useTranslations("chat.inRide");

  const pois = message.pois ?? [];
  const totalFound = message.totalFound ?? 0;
  const shown = pois.length;

  let recapText: string;
  if (message.outOfCoverage) {
    recapText = t("recap.outOfCoverage");
  } else if (shown > 0) {
    recapText =
      totalFound > shown
        ? t("recap.foundTruncated", { count: shown, total: totalFound })
        : t("recap.found", { count: shown });
  } else if (message.capReached) {
    recapText = t("recap.capReached", { count: totalFound });
  } else {
    recapText = t("recap.none");
  }

  return (
    <div className="flex w-full max-w-[95%] flex-col gap-2 self-start">
      <p
        data-testid="in-ride-recap"
        className="self-start rounded-2xl rounded-bl-sm border border-border bg-background px-3 py-2 text-sm leading-relaxed text-foreground shadow-sm"
      >
        {recapText}
      </p>

      {shown > 0 && (
        <div data-testid="in-ride-pois" className="flex flex-col gap-2">
          {pois.map((poi, idx) => (
            <PoiCard key={`${poi.deeplink}-${idx}`} poi={poi} />
          ))}
        </div>
      )}

      {showWiden && (
        <button
          type="button"
          onClick={onWiden}
          data-testid="in-ride-widen"
          className={cn(
            "inline-flex items-center gap-1.5 self-start rounded-full border border-border bg-background",
            "px-3 py-1.5 text-xs font-medium text-foreground shadow-sm",
            "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand",
          )}
        >
          <Search className="h-3.5 w-3.5" aria-hidden="true" />
          {t("widenSearch")}
        </button>
      )}
    </div>
  );
}

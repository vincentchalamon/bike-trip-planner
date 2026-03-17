"use client";

import { useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import {
  Popover,
  PopoverTrigger,
  PopoverContent,
} from "@/components/ui/popover";
import type { SupplyMarkerData } from "@/lib/validation/schemas";

interface SupplyTimelineProps {
  markers: SupplyMarkerData[];
  stageDistance: number;
}

function markerEmoji(type: SupplyMarkerData["type"]): string {
  switch (type) {
    case "water":
      return "💧";
    case "food":
      return "🍴";
    case "both":
      return "🏘️";
  }
}

function markerAriaLabel(
  type: SupplyMarkerData["type"],
  distanceKm: number,
  t: ReturnType<typeof useTranslations<"supplyTimeline">>,
): string {
  const dist = Math.round(distanceKm);
  switch (type) {
    case "water":
      return t("waterMarkerAriaLabel", { distance: dist });
    case "food":
      return t("foodMarkerAriaLabel", { distance: dist });
    case "both":
      return t("bothMarkerAriaLabel", { distance: dist });
  }
}

interface MarkerTooltipProps {
  marker: SupplyMarkerData;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leftPct: number;
}

function MarkerTooltip({
  marker,
  open,
  onOpenChange,
  leftPct,
}: MarkerTooltipProps) {
  const t = useTranslations("supplyTimeline");
  const emoji = markerEmoji(marker.type);

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <button
          type="button"
          data-testid={`supply-marker-${Math.round(marker.distanceFromStart)}`}
          aria-label={markerAriaLabel(marker.type, marker.distanceFromStart, t)}
          aria-pressed={open}
          style={{ left: `${leftPct}%`, top: 0 }}
          className="absolute -translate-x-1/2 text-base leading-none cursor-pointer hover:scale-125 transition-transform duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
          title={markerAriaLabel(marker.type, marker.distanceFromStart, t)}
        >
          {emoji}
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-64 p-3 text-xs"
        side="top"
        align="center"
        data-testid="supply-tooltip"
      >
        <div className="space-y-2">
          {/* Water section */}
          {marker.water.length > 0 && (
            <div>
              <div className="font-semibold text-xs mb-1 flex items-center gap-1">
                <span>💧</span>
                <span>{t("waterSection")}</span>
              </div>
              <ul className="space-y-0.5 max-h-32 overflow-y-auto">
                {marker.water.map((w, idx) => (
                  <li key={idx} className="flex justify-between gap-2">
                    <span className="text-muted-foreground truncate">
                      {w.name ?? t("unnamedWaterPoint")}
                    </span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {w.distanceFromStart} km
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Divider when both sections present */}
          {marker.water.length > 0 && marker.food.length > 0 && (
            <div className="border-t border-border" />
          )}

          {/* Food section */}
          {marker.food.length > 0 && (
            <div>
              <div className="font-semibold text-xs mb-1 flex items-center gap-1">
                <span>🍴</span>
                <span>{t("foodSection")}</span>
              </div>
              <ul className="space-y-0.5 max-h-32 overflow-y-auto">
                {marker.food.map((f, idx) => (
                  <li key={idx} className="flex justify-between gap-2">
                    <span className="text-muted-foreground truncate">
                      {f.name ??
                        t("unnamedFoodPoint", { category: f.category })}
                    </span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {f.distanceFromStart} km
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}

/**
 * Horizontal supply timeline for a stage.
 *
 * Shows a thin line from start to end of the stage (proportional to distance)
 * with emoji markers at each supply cluster:
 * - 💧 for water points only
 * - 🍴 for food/shops only
 * - 🏘️ for both in the same zone
 *
 * Markers are positioned proportionally to their distance from stage start.
 * Clicking/tapping a marker opens a non-blocking popover with POI details.
 */
export function SupplyTimeline({
  markers,
  stageDistance,
}: SupplyTimelineProps) {
  const t = useTranslations("supplyTimeline");
  const [openMarkerIndex, setOpenMarkerIndex] = useState<number | null>(null);

  const handleMarkerOpenChange = useCallback(
    (index: number, isOpen: boolean) => {
      setOpenMarkerIndex(isOpen ? index : null);
    },
    [],
  );

  if (markers.length === 0 || stageDistance <= 0) {
    return null;
  }

  return (
    <div
      role="region"
      aria-label={t("ariaLabel")}
      data-testid="supply-timeline"
      className="relative w-full"
      style={{ height: 28 }}
    >
      {/* Background line */}
      <div
        className="absolute top-[7px] h-0.5 bg-border"
        style={{ left: 0, right: 0 }}
        aria-hidden="true"
      />

      {/* Start endpoint dot */}
      <div
        className="absolute w-2 h-2 rounded-full bg-muted-foreground/40 border border-border"
        style={{ left: 0, top: 3 }}
        aria-hidden="true"
      />

      {/* End endpoint dot */}
      <div
        className="absolute w-2 h-2 rounded-full bg-muted-foreground/40 border border-border -translate-x-full"
        style={{ left: "100%", top: 3 }}
        aria-hidden="true"
      />

      {/* Supply markers */}
      {markers.map((marker, index) => {
        const leftPct = (marker.distanceFromStart / stageDistance) * 100;
        // Clamp between 2% and 98% to avoid overflow
        const clampedPct = Math.min(Math.max(leftPct, 2), 98);

        return (
          <MarkerTooltip
            key={index}
            marker={marker}
            open={openMarkerIndex === index}
            onOpenChange={(isOpen) => handleMarkerOpenChange(index, isOpen)}
            leftPct={clampedPct}
          />
        );
      })}
    </div>
  );
}

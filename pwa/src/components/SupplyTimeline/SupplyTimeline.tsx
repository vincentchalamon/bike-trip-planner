"use client";

import { useState, useCallback, useMemo } from "react";
import { useTranslations } from "next-intl";
import {
  Popover,
  PopoverTrigger,
  PopoverContent,
} from "@/components/ui/popover";
import type {
  SupplyMarkerData,
  SupplyWaterPointData,
  SupplyFoodPointData,
} from "@/lib/validation/schemas";

/** Cluster markers whose timeline positions are within this many percent. */
const CLUSTER_THRESHOLD_PCT = 4;

interface ClusteredMarker {
  type: SupplyMarkerData["type"];
  /** Average distanceFromStart of all clustered markers (km). */
  distanceFromStart: number;
  /** Clamped [2, 98] position on the timeline (%). */
  leftPct: number;
  /** Number of original markers merged into this cluster. */
  count: number;
  water: SupplyWaterPointData[];
  food: SupplyFoodPointData[];
}

function clusterMarkers(
  markers: SupplyMarkerData[],
  stageDistance: number,
): ClusteredMarker[] {
  if (markers.length === 0) return [];

  const sorted = [...markers].sort(
    (a, b) => a.distanceFromStart - b.distanceFromStart,
  );

  const groups: SupplyMarkerData[][] = [];
  let current: SupplyMarkerData[] = [sorted[0]!];

  for (let i = 1; i < sorted.length; i++) {
    const prevPct =
      (current[current.length - 1]!.distanceFromStart / stageDistance) * 100;
    const currPct = (sorted[i]!.distanceFromStart / stageDistance) * 100;

    if (currPct - prevPct < CLUSTER_THRESHOLD_PCT) {
      current.push(sorted[i]!);
    } else {
      groups.push(current);
      current = [sorted[i]!];
    }
  }
  groups.push(current);

  return groups.map((group) => {
    const avgDist =
      group.reduce((sum, m) => sum + m.distanceFromStart, 0) / group.length;
    const rawPct = (avgDist / stageDistance) * 100;
    const allWater = group.every((m) => m.type === "water");
    const allFood = group.every((m) => m.type === "food");
    return {
      type: allWater ? "water" : allFood ? "food" : "both",
      distanceFromStart: avgDist,
      leftPct: Math.min(Math.max(rawPct, 2), 98),
      count: group.length,
      water: group.flatMap((m) => m.water),
      food: group.flatMap((m) => m.food),
    };
  });
}

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

interface ClusterTooltipProps {
  cluster: ClusteredMarker;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

function ClusterTooltip({ cluster, open, onOpenChange }: ClusterTooltipProps) {
  const t = useTranslations("supplyTimeline");
  const emoji = markerEmoji(cluster.type);

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <button
          type="button"
          data-testid={`supply-marker-${Math.round(cluster.distanceFromStart)}`}
          aria-label={markerAriaLabel(cluster.type, cluster.distanceFromStart, t)}
          aria-pressed={open}
          style={{ left: `${cluster.leftPct}%`, top: 0 }}
          className="absolute -translate-x-1/2 text-base leading-none cursor-pointer hover:scale-125 transition-transform duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
          title={markerAriaLabel(cluster.type, cluster.distanceFromStart, t)}
        >
          <span className="relative inline-block">
            {emoji}
            {cluster.count > 1 && (
              <span
                aria-hidden="true"
                className="absolute -top-1 -right-1.5 text-[8px] font-bold bg-primary text-primary-foreground rounded-full w-3.5 h-3.5 flex items-center justify-center leading-none"
              >
                {cluster.count}
              </span>
            )}
          </span>
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
          {cluster.water.length > 0 && (
            <div>
              <div className="font-semibold text-xs mb-1 flex items-center gap-1">
                <span>💧</span>
                <span>{t("waterSection")}</span>
              </div>
              <ul className="space-y-0.5 max-h-32 overflow-y-auto">
                {cluster.water.map((w, idx) => (
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
          {cluster.water.length > 0 && cluster.food.length > 0 && (
            <div className="border-t border-border" />
          )}

          {/* Food section */}
          {cluster.food.length > 0 && (
            <div>
              <div className="font-semibold text-xs mb-1 flex items-center gap-1">
                <span>🍴</span>
                <span>{t("foodSection")}</span>
              </div>
              <ul className="space-y-0.5 max-h-32 overflow-y-auto">
                {cluster.food.map((f, idx) => (
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
 * Nearby markers (within 4% of the timeline width) are automatically merged
 * into a single cluster marker with a count badge. Clicking/tapping a marker
 * opens a non-blocking popover with POI details for all merged points.
 */
export function SupplyTimeline({
  markers,
  stageDistance,
}: SupplyTimelineProps) {
  const t = useTranslations("supplyTimeline");
  const [openClusterIndex, setOpenClusterIndex] = useState<number | null>(null);

  const clusters = useMemo(
    () => clusterMarkers(markers, stageDistance),
    [markers, stageDistance],
  );

  const handleOpenChange = useCallback(
    (index: number, isOpen: boolean) => {
      setOpenClusterIndex(isOpen ? index : null);
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

      {/* Clustered supply markers */}
      {clusters.map((cluster, index) => (
        <ClusterTooltip
          key={index}
          cluster={cluster}
          open={openClusterIndex === index}
          onOpenChange={(isOpen) => handleOpenChange(index, isOpen)}
        />
      ))}
    </div>
  );
}

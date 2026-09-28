"use client";

import { useCallback, useMemo, useRef, useState, memo } from "react";
import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";
import {
  buildProfilePoints,
  findClosestProfilePoint,
  minMax,
  type ProfilePoint,
  type StageData,
} from "@btp/core";
import { getStageColor } from "./stage-colors";

// SVG viewport constants
const VW = 800;
const VH = 160;
const PAD_L = 4;
const PAD_R = 8;
const PAD_T = 8;
const PAD_B = 20;

interface ElevationProfileProps {
  focusedStageIndex: number | null;
  onHover: (coordIndex: number | null, stageIndex: number | null) => void;
  stages?: StageData[];
}

export const ElevationProfile = memo(function ElevationProfile({
  focusedStageIndex,
  onHover,
  stages: externalStages,
}: ElevationProfileProps) {
  const t = useTranslations("map");
  const svgRef = useRef<SVGSVGElement>(null);
  const [hoveredPoint, setHoveredPoint] = useState<{
    x: number;
    screenX: number;
    flipLeft: boolean;
    gradient: number;
    distance: number;
  } | null>(null);
  const storeStages = useTripStore((s) => s.stages);
  const stages = externalStages ?? storeStages;
  const activeStages = useMemo(
    () => stages.filter((s) => !s.isRestDay),
    [stages],
  );

  const points = useMemo(
    () => buildProfilePoints(stages, focusedStageIndex),
    [stages, focusedStageIndex],
  );

  const hasData = points.length >= 2;

  const { maxDist, displayMinEle, displayMaxEle } = useMemo(() => {
    if (!hasData) return { maxDist: 0, displayMinEle: 0, displayMaxEle: 1000 };

    const { min: minEle, max: maxEle } = minMax(points.map((p) => p.ele));
    const dist = points[points.length - 1]?.distanceKm ?? 0;
    const elevRange = maxEle - minEle;

    // Small buffer below so the terrain doesn't sit flush with the baseline.
    // Generous buffer above so peaks breathe — terrain occupies roughly the
    // bottom third of the graph (Komoot-style), with min 100 m headroom.
    const bufferBelow = Math.max(elevRange * 0.1, 10);
    const bufferAbove = Math.max(elevRange * 1.5, 100);

    return {
      maxDist: dist,
      displayMinEle: minEle - bufferBelow,
      displayMaxEle: maxEle + bufferAbove,
    };
  }, [points, hasData]);

  const toX = useCallback(
    (distKm: number) =>
      PAD_L + (distKm / (maxDist || 1)) * (VW - PAD_L - PAD_R),
    [maxDist],
  );

  const toY = useCallback(
    (ele: number) => {
      const range = displayMaxEle - displayMinEle || 1;
      return PAD_T + (1 - (ele - displayMinEle) / range) * (VH - PAD_T - PAD_B);
    },
    [displayMinEle, displayMaxEle],
  );

  // Build per-stage SVG area paths
  const stagePaths = useMemo(() => {
    if (!hasData) return [];

    const byStage = new Map<number, ProfilePoint[]>();
    for (const pt of points) {
      const arr = byStage.get(pt.stageIndex) ?? [];
      arr.push(pt);
      byStage.set(pt.stageIndex, arr);
    }

    const result: { stageIndex: number; d: string; color: string }[] = [];
    byStage.forEach((pts, stageIndex) => {
      const stage = activeStages[stageIndex];
      if (!stage || pts.length < 2) return;

      const color = getStageColor(stage.dayNumber);
      const firstPt = pts[0]!;
      const lastPt = pts[pts.length - 1]!;

      const lineD = pts
        .map(
          (pt, i) =>
            `${i === 0 ? "M" : "L"}${toX(pt.distanceKm).toFixed(1)},${toY(pt.ele).toFixed(1)}`,
        )
        .join(" ");

      const d =
        lineD +
        ` L${toX(lastPt.distanceKm).toFixed(1)},${(VH - PAD_B).toFixed(1)}` +
        ` L${toX(firstPt.distanceKm).toFixed(1)},${(VH - PAD_B).toFixed(1)} Z`;

      result.push({ stageIndex, d, color });
    });
    return result;
  }, [points, activeStages, toX, toY, hasData]);

  const handleMouseMove = useCallback(
    (e: React.MouseEvent<SVGSVGElement>) => {
      if (!svgRef.current || points.length === 0) return;
      const rect = svgRef.current.getBoundingClientRect();
      const svgX = ((e.clientX - rect.left) / rect.width) * VW;
      const distKm = ((svgX - PAD_L) / (VW - PAD_L - PAD_R)) * maxDist;

      const best = findClosestProfilePoint(points, distKm);
      if (!best) return;
      onHover(best.coordIndex, best.stageIndex);

      const screenX = e.clientX - rect.left;
      setHoveredPoint({
        x: toX(best.distanceKm),
        screenX,
        flipLeft: screenX > rect.width * 0.6,
        gradient: best.gradient,
        distance: best.distanceKm,
      });
    },
    [points, maxDist, onHover, toX],
  );

  const handleMouseLeave = useCallback(() => {
    onHover(null, null);
    setHoveredPoint(null);
  }, [onHover]);

  if (!hasData) {
    return null;
  }

  return (
    <div
      className="relative w-full bg-background/80 backdrop-blur-sm border border-border rounded-xl px-2 py-1"
      data-testid="elevation-profile"
      aria-label={t("elevationProfileAriaLabel")}
    >
      <svg
        ref={svgRef}
        viewBox={`0 0 ${VW} ${VH}`}
        preserveAspectRatio="none"
        className="w-full"
        style={{ height: 100 }}
        onMouseMove={handleMouseMove}
        onMouseLeave={handleMouseLeave}
        role="img"
        aria-label={t("elevationProfileAriaLabel")}
      >
        {/* Stage area fills */}
        {stagePaths.map(({ stageIndex, d, color }) => (
          <path
            key={stageIndex}
            d={d}
            fill={color}
            fillOpacity={0.35}
            stroke={color}
            strokeWidth={1.5}
            strokeOpacity={0.8}
          />
        ))}

        {/* Vertical crosshair line */}
        {hoveredPoint !== null && (
          <line
            data-testid="elevation-crosshair"
            x1={hoveredPoint.x}
            y1={PAD_T}
            x2={hoveredPoint.x}
            y2={VH - PAD_B}
            stroke="currentColor"
            strokeWidth={1}
            opacity={0.5}
          />
        )}
      </svg>

      {/* HTML tooltip — rendered outside the SVG so fonts use CSS units, not SVG viewBox units. */}
      {hoveredPoint !== null && (
        <div
          data-testid="elevation-tooltip-bg"
          className="absolute top-1 pointer-events-none z-10 bg-background border border-border/50 rounded px-2 py-1 text-xs shadow-sm whitespace-nowrap"
          style={{
            left: `${hoveredPoint.screenX}px`,
            transform: hoveredPoint.flipLeft
              ? "translateX(calc(-100% - 8px))"
              : "translateX(8px)",
          }}
        >
          <div className="font-medium">
            {hoveredPoint.gradient >= 0 ? "+" : ""}
            {hoveredPoint.gradient.toFixed(1)}%
          </div>
          <div className="text-muted-foreground">
            {hoveredPoint.distance.toFixed(1)} km
          </div>
        </div>
      )}
    </div>
  );
});

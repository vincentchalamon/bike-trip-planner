"use client";

import { useCallback, useMemo, useRef, useState, memo } from "react";
import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";
import type { StageData } from "@/lib/validation/schemas";
import { getStageColor } from "./stage-colors";

/** Haversine distance between two lat/lon points in km. */
function haversineKm(
  lat1: number,
  lon1: number,
  lat2: number,
  lon2: number,
): number {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** Binary search for the point with the closest distanceKm to target. */
function findClosestPoint<T extends { distanceKm: number }>(
  points: T[],
  target: number,
): T {
  let lo = 0;
  let hi = points.length - 1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (points[mid]!.distanceKm < target) lo = mid + 1;
    else hi = mid;
  }
  // Check lo-1 as well in case it's closer
  if (
    lo > 0 &&
    Math.abs(points[lo - 1]!.distanceKm - target) <
      Math.abs(points[lo]!.distanceKm - target)
  ) {
    return points[lo - 1]!;
  }
  return points[lo]!;
}

interface ProfilePoint {
  distanceKm: number;
  ele: number;
  stageIndex: number;
  coordIndex: number;
}

/** Builds a flat array of profile points for one or all stages. */
function buildProfilePoints(
  stages: StageData[],
  focusedStageIndex: number | null,
): ProfilePoint[] {
  const activeStages = stages.filter((s) => !s.isRestDay);
  const entries: { stage: StageData; stageIndex: number }[] =
    focusedStageIndex !== null
      ? activeStages[focusedStageIndex]
        ? [
            {
              stage: activeStages[focusedStageIndex]!,
              stageIndex: focusedStageIndex,
            },
          ]
        : []
      : activeStages.map((stage, idx) => ({ stage, stageIndex: idx }));

  const points: ProfilePoint[] = [];
  let cumulativeKm = 0;

  for (const { stage, stageIndex } of entries) {
    const coords = stage.geometry;
    if (coords.length < 2) continue;

    for (let ci = 0; ci < coords.length; ci++) {
      let distKm = cumulativeKm;
      if (ci > 0) {
        const prev = coords[ci - 1]!;
        const curr = coords[ci]!;
        const lastPoint = points[points.length - 1];
        distKm =
          (lastPoint?.distanceKm ?? cumulativeKm) +
          haversineKm(prev.lat, prev.lon, curr.lat, curr.lon);
      }

      points.push({
        distanceKm: distKm,
        ele: coords[ci]!.ele,
        stageIndex,
        coordIndex: ci,
      });
    }

    cumulativeKm = points[points.length - 1]?.distanceKm ?? cumulativeKm;
  }

  return points;
}

// SVG viewport constants
const VW = 800;
const VH = 160;
const PAD_L = 32;
const PAD_R = 8;
const PAD_T = 8;
const PAD_B = 20;

interface ElevationProfileProps {
  focusedStageIndex: number | null;
  onHover: (coordIndex: number | null, stageIndex: number | null) => void;
}

export const ElevationProfile = memo(function ElevationProfile({
  focusedStageIndex,
  onHover,
}: ElevationProfileProps) {
  const t = useTranslations("map");
  const svgRef = useRef<SVGSVGElement>(null);
  const [hoveredPoint, setHoveredPoint] = useState<{
    x: number;
    elevation: number;
    distance: number;
  } | null>(null);
  const stages = useTripStore((s) => s.stages);
  const activeStages = useMemo(
    () => stages.filter((s) => !s.isRestDay),
    [stages],
  );

  const points = useMemo(
    () => buildProfilePoints(stages, focusedStageIndex),
    [stages, focusedStageIndex],
  );

  const hasData = points.length >= 2;

  const { minEle, maxEle, maxDist } = useMemo(() => {
    if (!hasData) return { minEle: 0, maxEle: 0, maxDist: 0 };
    return {
      minEle: Math.min(...points.map((p) => p.ele)),
      maxEle: Math.max(...points.map((p) => p.ele)),
      maxDist: points[points.length - 1]?.distanceKm ?? 0,
    };
  }, [points, hasData]);

  const toX = useCallback(
    (distKm: number) =>
      PAD_L + (distKm / (maxDist || 1)) * (VW - PAD_L - PAD_R),
    [maxDist],
  );

  const toY = useCallback(
    (ele: number) => {
      const range = maxEle - minEle || 1;
      return PAD_T + (1 - (ele - minEle) / range) * (VH - PAD_T - PAD_B);
    },
    [minEle, maxEle],
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

  // Y-axis grid labels
  const yLabels = useMemo(() => {
    if (!hasData) return [];
    const range = maxEle - minEle;
    const step = range > 800 ? 200 : range > 400 ? 100 : range > 200 ? 50 : 25;
    const labels: { ele: number; y: number }[] = [];
    const start = Math.ceil(minEle / step) * step;
    for (let e = start; e <= maxEle; e += step) {
      labels.push({ ele: e, y: toY(e) });
    }
    return labels;
  }, [hasData, minEle, maxEle, toY]);

  const handleMouseMove = useCallback(
    (e: React.MouseEvent<SVGSVGElement>) => {
      if (!svgRef.current || points.length === 0) return;
      const rect = svgRef.current.getBoundingClientRect();
      const svgX = ((e.clientX - rect.left) / rect.width) * VW;
      const distKm = ((svgX - PAD_L) / (VW - PAD_L - PAD_R)) * maxDist;

      const best = findClosestPoint(points, distKm);
      onHover(best.coordIndex, best.stageIndex);
      setHoveredPoint({
        x: toX(best.distanceKm),
        elevation: best.ele,
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
      className="w-full bg-background/80 backdrop-blur-sm border border-border rounded-xl px-2 py-1"
      data-testid="elevation-profile"
      aria-label={t("elevationProfileAriaLabel")}
    >
      <p className="text-[10px] text-muted-foreground px-1 mb-0.5">
        {t("elevationProfile")}
      </p>
      <svg
        ref={svgRef}
        viewBox={`0 0 ${VW} ${VH}`}
        preserveAspectRatio="none"
        className="w-full"
        style={{ height: 80 }}
        onMouseMove={handleMouseMove}
        onMouseLeave={handleMouseLeave}
        role="img"
        aria-label={t("elevationProfileAriaLabel")}
      >
        {/* Y-axis grid */}
        {yLabels.map(({ ele, y }) => (
          <g key={ele}>
            <line
              x1={PAD_L}
              y1={y}
              x2={VW - PAD_R}
              y2={y}
              stroke="currentColor"
              strokeOpacity={0.1}
              strokeWidth={0.5}
            />
            <text
              x={PAD_L - 3}
              y={y + 3}
              textAnchor="end"
              fontSize={9}
              fill="currentColor"
              fillOpacity={0.5}
            >
              {ele}m
            </text>
          </g>
        ))}

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

        {/* Hover crosshair + tooltip */}
        {hoveredPoint !== null &&
          (() => {
            const tooltipW = 90;
            const tooltipH = 28;
            const tooltipPad = 6;
            // Flip tooltip to left side when near the right edge
            const tooltipX =
              hoveredPoint.x + tooltipPad + tooltipW > VW - PAD_R
                ? hoveredPoint.x - tooltipPad - tooltipW
                : hoveredPoint.x + tooltipPad;
            const tooltipY = PAD_T;

            return (
              <g>
                {/* Vertical crosshair line */}
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
                {/* Tooltip background */}
                <rect
                  data-testid="elevation-tooltip-bg"
                  x={tooltipX}
                  y={tooltipY}
                  width={tooltipW}
                  height={tooltipH}
                  rx={4}
                  fill="var(--background)"
                  stroke="currentColor"
                  strokeOpacity={0.2}
                  strokeWidth={0.5}
                />
                {/* Elevation value */}
                <text
                  x={tooltipX + tooltipW / 2}
                  y={tooltipY + 11}
                  textAnchor="middle"
                  fontSize={9}
                  fill="currentColor"
                  fillOpacity={0.9}
                >
                  {Math.round(hoveredPoint.elevation)}m
                </text>
                {/* Distance value */}
                <text
                  x={tooltipX + tooltipW / 2}
                  y={tooltipY + 22}
                  textAnchor="middle"
                  fontSize={9}
                  fill="currentColor"
                  fillOpacity={0.6}
                >
                  {hoveredPoint.distance.toFixed(1)}km
                </text>
              </g>
            );
          })()}
      </svg>
    </div>
  );
});

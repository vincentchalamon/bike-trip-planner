"use client";

import { useCallback, useMemo } from "react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import type { StageData } from "@/lib/validation/schemas";

/**
 * Computes a map of dayNumber → total distance for that day.
 * When multiple stages share the same dayNumber, their distances are summed.
 */
function buildDayDistances(stages: StageData[]): Map<number, number> {
  const map = new Map<number, number>();
  for (const stage of stages) {
    map.set(stage.dayNumber, (map.get(stage.dayNumber) ?? 0) + stage.distance);
  }
  return map;
}

/**
 * Horizontal progress bar mirroring the vertical timeline design.
 *
 * A thin brand-colored line connects circular markers (one per day + an end
 * marker), positioned proportionally to each day's distance. Clicking a marker
 * scrolls the corresponding day heading into view. The active day marker is
 * filled; others show an outline on the background color.
 */
export function StageProgressBar() {
  const t = useTranslations("progressBar");

  const stages = useTripStore((s) => s.stages);
  const activeDayNumber = useUiStore((s) => s.activeDayNumber);

  const dayDistances = useMemo(() => buildDayDistances(stages), [stages]);

  const totalDistance = useMemo(
    () => Array.from(dayDistances.values()).reduce((sum, d) => sum + d, 0),
    [dayDistances],
  );

  // Ordered list of unique day numbers
  const dayNumbers = useMemo(
    () => Array.from(dayDistances.keys()).sort((a, b) => a - b),
    [dayDistances],
  );

  // Index of the active day (used to split past/current/future styling).
  const activeDayIndex = useMemo(
    () => (activeDayNumber !== null ? dayNumbers.indexOf(activeDayNumber) : -1),
    [dayNumbers, activeDayNumber],
  );

  // Cumulative left-percentage for each day's start marker (0..100).
  // Index 0 = first day = 0%, last entry = 100% (end marker).
  const cumulativePcts = useMemo(() => {
    const pcts: number[] = [0];
    let cum = 0;
    for (const dn of dayNumbers) {
      cum += ((dayDistances.get(dn) ?? 0) / totalDistance) * 100;
      pcts.push(Math.min(cum, 100));
    }
    return pcts;
  }, [dayNumbers, dayDistances, totalDistance]);

  const handleSegmentClick = useCallback((dayNumber: number) => {
    const target = document.getElementById(`timeline-day-${dayNumber}`);
    if (target) {
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, []);

  if (dayNumbers.length === 0 || totalDistance === 0) {
    return null;
  }

  // Dot half-width in px (w-4 = 16px → 8px). Used to inset the line so it
  // starts/ends at the centre of the first and last dots.
  const DOT_HALF = 8;

  return (
    <div
      role="navigation"
      aria-label={t("ariaLabel")}
      data-testid="stage-progress-bar"
      // Reserve vertical space: dot (16px) + label (~14px) + gap
      className="relative w-full"
      style={{ height: 44 }}
    >
      {/* Horizontal line — inset by half-dot on each side so it runs dot-centre to dot-centre */}
      {/* Future portion (faded) */}
      <div
        className="absolute top-2 h-0.5 bg-brand/30"
        style={{ left: DOT_HALF, right: DOT_HALF }}
        aria-hidden="true"
      />
      {/* Past + active portion (full brand), up to end of the active day */}
      {activeDayIndex >= 0 && (
        <div
          className="absolute top-2 h-0.5 bg-brand transition-all duration-300"
          style={{
            left: DOT_HALF,
            width: `calc(${cumulativePcts[activeDayIndex + 1] ?? 100}% - ${DOT_HALF}px)`,
          }}
          aria-hidden="true"
        />
      )}

      {/* Day markers */}
      {dayNumbers.map((dayNumber, index) => {
        const distance = dayDistances.get(dayNumber) ?? 0;
        const segWidthPct = (distance / totalDistance) * 100;
        const leftPct = cumulativePcts[index] ?? 0;
        const isActive = activeDayNumber === dayNumber;
        const isPast = activeDayIndex >= 0 ? index < activeDayIndex : false;
        const isFuture = activeDayIndex >= 0 ? index > activeDayIndex : false;
        const showLabel = segWidthPct >= 8;

        return (
          <button
            key={dayNumber}
            type="button"
            onClick={() => handleSegmentClick(dayNumber)}
            aria-label={t("segmentAriaLabel", {
              day: dayNumber,
              distance: Math.round(distance),
            })}
            aria-current={isActive ? "true" : undefined}
            data-testid={`progress-segment-${dayNumber}`}
            style={{ left: `${leftPct}%` }}
            className="absolute flex flex-col items-center cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand -translate-x-1/2"
            title={t("segmentTitle", {
              day: dayNumber,
              distance: Math.round(distance),
            })}
          >
            {/* TimelineMarker-style dot */}
            <div
              className={cn(
                "w-4 h-4 rounded-full border-[3px] transition-colors duration-200",
                isActive
                  ? "border-brand bg-brand"
                  : isPast
                    ? "border-brand bg-brand/60 hover:bg-brand/80"
                    : isFuture
                      ? "border-brand/30 bg-background hover:border-brand/60"
                      : "border-brand bg-background hover:bg-brand/20",
              )}
            />
            {/* Label below the dot */}
            {showLabel && (
              <span className="text-[10px] leading-none mt-1 text-muted-foreground whitespace-nowrap">
                {t("segmentLabel", {
                  day: dayNumber,
                  distance: Math.round(distance),
                })}
              </span>
            )}
          </button>
        );
      })}

      {/* End marker */}
      <div
        className="absolute flex flex-col items-center -translate-x-1/2"
        style={{ left: "100%" }}
        aria-hidden="true"
      >
        <div className="w-4 h-4 rounded-full border-[3px] border-brand bg-background" />
      </div>
    </div>
  );
}

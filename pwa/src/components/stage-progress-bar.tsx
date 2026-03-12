"use client";

import { useCallback, useMemo } from "react";
import { useTranslations } from "next-intl";
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
 * Horizontal segmented progress bar showing one segment per day.
 *
 * Each segment width is proportional to the day's total distance relative to
 * the overall trip distance. The currently active day (tracked in `useUiStore`)
 * is highlighted. Clicking a segment scrolls the timeline day heading into view
 * and sets it as the active day.
 *
 * Synchronisation with the timeline is achieved via `useUiStore.activeDayNumber`:
 * any consumer (map hover, elevation profile scrub, timeline scroll) can write
 * to that field and this component will react accordingly.
 */
export function StageProgressBar() {
  const t = useTranslations("progressBar");

  const stages = useTripStore((s) => s.stages);
  const activeDayNumber = useUiStore((s) => s.activeDayNumber);
  const setActiveDayNumber = useUiStore((s) => s.setActiveDayNumber);

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

  const handleSegmentClick = useCallback(
    (dayNumber: number) => {
      setActiveDayNumber(dayNumber);

      // Scroll the corresponding day heading in the timeline into view
      const target = document.getElementById(`timeline-day-${dayNumber}`);
      if (target) {
        target.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    },
    [setActiveDayNumber],
  );

  if (dayNumbers.length === 0 || totalDistance === 0) {
    return null;
  }

  return (
    <div
      role="navigation"
      aria-label={t("ariaLabel")}
      data-testid="stage-progress-bar"
      className="flex w-full gap-0.5 h-3 rounded-full overflow-hidden"
    >
      {dayNumbers.map((dayNumber, index) => {
        const distance = dayDistances.get(dayNumber) ?? 0;
        const widthPct = (distance / totalDistance) * 100;
        const isActive = activeDayNumber === dayNumber;
        const isFirst = index === 0;
        const isLast = index === dayNumbers.length - 1;

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
            style={{ width: `${widthPct}%` }}
            className={[
              "h-full transition-all duration-200 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand",
              isActive ? "bg-brand" : "bg-brand/30 hover:bg-brand/60",
              isFirst ? "rounded-l-full" : "",
              isLast ? "rounded-r-full" : "",
            ]
              .filter(Boolean)
              .join(" ")}
            title={t("segmentTitle", {
              day: dayNumber,
              distance: Math.round(distance),
            })}
          />
        );
      })}
    </div>
  );
}

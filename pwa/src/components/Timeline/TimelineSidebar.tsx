"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { BedDouble } from "lucide-react";
import { TimelineSidebarSkeleton } from "@/components/timeline-sidebar-skeleton";
import { cn } from "@/lib/utils";
import type { StageData } from "@/lib/validation/schemas";

interface TimelineSidebarProps {
  stages: StageData[];
  selectedIndex: number;
  onSelect: (index: number) => void;
  isProcessing?: boolean;
}

function formatStageLabel(stage: StageData, fallback: string): string {
  if (stage.isRestDay) return fallback;
  // Prefer the human-readable arrival label (it's the destination of the day).
  return stage.endLabel?.trim() || stage.startLabel?.trim() || fallback;
}

/**
 * Vertical master timeline rendered in the roadbook sidebar.
 *
 * Each stage is materialised as a clickable row featuring a brand-colored dot,
 * the stage name, and the cumulative distance from departure. A continuous
 * connecting line links consecutive dots. The active stage is highlighted with
 * the soft amber background and the deep accent ink, mirroring the design
 * tokens introduced in sprint 25.
 */
export function TimelineSidebar({
  stages,
  selectedIndex,
  onSelect,
  isProcessing,
}: TimelineSidebarProps) {
  const t = useTranslations("timeline");
  const tStage = useTranslations("stage");
  const tRest = useTranslations("restDay");

  // Cumulative distance up to and including each stage (km).
  const cumulativeDistances = useMemo(() => {
    const arr: number[] = [];
    let acc = 0;
    for (const s of stages) {
      acc += s.distance;
      arr.push(acc);
    }
    return arr;
  }, [stages]);

  if (stages.length === 0) {
    if (!isProcessing) return null;
    return <TimelineSidebarSkeleton />;
  }

  return (
    <nav
      aria-label={t("tripStages")}
      data-testid="timeline-sidebar"
      className="relative"
    >
      {/* Vertical connecting line — runs from the centre of the first dot
          to the centre of the last dot. */}
      <div
        aria-hidden="true"
        className="absolute left-[15px] top-3 bottom-3 w-px bg-brand/30"
      />

      <ul className="flex flex-col">
        {stages.map((stage, index) => {
          const isActive = index === selectedIndex;
          const cumulative = cumulativeDistances[index] ?? 0;
          const fallbackName = stage.isRestDay
            ? tRest("label")
            : tStage("day", { dayNumber: stage.dayNumber });
          const stageName = formatStageLabel(stage, fallbackName);

          return (
            <li key={`${stage.dayNumber}-${index}`}>
              <button
                type="button"
                onClick={() => onSelect(index)}
                aria-current={isActive ? "true" : undefined}
                data-testid={`timeline-sidebar-stage-${index}`}
                data-active={isActive ? "true" : undefined}
                className={cn(
                  "group w-full flex items-start gap-3 rounded-lg px-2 py-2 text-left",
                  "transition-colors duration-150 cursor-pointer",
                  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand",
                  isActive
                    ? "bg-[var(--accent-soft)] text-[var(--accent-ink)]"
                    : "hover:bg-[var(--accent-soft)]/60 text-foreground",
                )}
              >
                {/* Dot marker */}
                <span
                  aria-hidden="true"
                  className={cn(
                    "relative z-10 mt-1 shrink-0 rounded-full transition-all duration-150",
                    isActive
                      ? "w-[14px] h-[14px] border-[3px] border-brand bg-brand shadow-[0_0_0_3px_var(--accent-soft)]"
                      : stage.isRestDay
                        ? "w-3 h-3 border-2 border-dashed border-brand/60 bg-background mt-[5px]"
                        : "w-3 h-3 border-[3px] border-brand bg-background mt-[5px] group-hover:bg-brand/30",
                  )}
                />

                {/* Stage name + cumulative distance */}
                <span className="flex-1 min-w-0 flex flex-col gap-0.5">
                  <span className="flex items-center gap-1.5 min-w-0">
                    {stage.isRestDay && (
                      <BedDouble className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <span
                      className={cn(
                        "truncate text-sm",
                        isActive ? "font-semibold" : "font-medium",
                      )}
                      title={stageName}
                    >
                      {tStage("day", { dayNumber: stage.dayNumber })}
                      <span className="text-muted-foreground"> · </span>
                      {stageName}
                    </span>
                  </span>
                  <span
                    className={cn(
                      "text-xs tabular-nums",
                      isActive
                        ? "text-[var(--accent-ink)]/80"
                        : "text-muted-foreground",
                    )}
                  >
                    {stage.isRestDay
                      ? tRest("label")
                      : t("cumulativeDistance", {
                          km: Math.round(cumulative),
                        })}
                  </span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

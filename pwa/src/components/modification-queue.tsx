"use client";

import { useTranslations } from "next-intl";
import { ClipboardList, Clock, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useTripStore } from "@/store/trip-store";

/**
 * Seconds of backend recompute time estimated per modification.
 *
 * This is a rough heuristic shown to the user so they understand why
 * batching several changes saves time. Values derived from observed p50
 * durations across the handler pipeline:
 * - accommodation: triggers RecalculateStages + ScanAccommodations (~5 s)
 * - distance:      triggers RecalculateStages + POIs + terrain + … (~15 s)
 * - dates:         triggers FetchWeather + CheckCalendar + ScanEvents (~8 s)
 * - pacing:        triggers RecalculateStages for all stages (~10 s)
 */
const SECONDS_PER_MODIFICATION: Record<string, number> = {
  accommodation: 5,
  distance: 15,
  dates: 8,
  pacing: 10,
};

/**
 * Maximum estimated seconds to display in the queue panel.
 * Beyond this threshold we show "~1 min" instead of an exact count.
 */
const MAX_DISPLAY_SECONDS = 59;

interface ModificationQueueProps {
  onApply: () => void;
  onCancel: () => void;
  isApplying?: boolean;
}

/**
 * Floating panel displayed at the bottom of the page when the user has
 * accumulated one or more modifications in the batch queue.
 *
 * Shows the list of pending modifications, an estimated recompute time and
 * two actions: "Apply all" (sends a single POST /recompute) and "Cancel"
 * (clears the queue and restores the previous state).
 */
export function ModificationQueue({
  onApply,
  onCancel,
  isApplying = false,
}: ModificationQueueProps) {
  const t = useTranslations("modificationQueue");
  const pendingModifications = useTripStore((s) => s.pendingModifications);

  if (pendingModifications.length === 0) return null;

  const totalSeconds = pendingModifications.reduce((sum, mod) => {
    return sum + (SECONDS_PER_MODIFICATION[mod.type] ?? 5);
  }, 0);

  const estimatedLabel =
    totalSeconds > MAX_DISPLAY_SECONDS
      ? t("estimatedTimeMinute")
      : t("estimatedTimeSeconds", { seconds: totalSeconds });

  return (
    <div
      role="region"
      aria-label={t("panelLabel")}
      data-testid="modification-queue"
      className="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 w-full max-w-md mx-auto px-4"
    >
      <div className="bg-background border border-border rounded-xl shadow-lg p-4 flex flex-col gap-3">
        {/* Header */}
        <div className="flex items-center gap-2">
          <ClipboardList className="h-4 w-4 text-brand flex-shrink-0" />
          <span
            className="font-semibold text-sm"
            data-testid="modification-queue-count"
          >
            {t("title", { count: pendingModifications.length })}
          </span>
        </div>

        {/* Modification list */}
        <ul className="space-y-1" data-testid="modification-queue-list">
          {pendingModifications.map((mod, i) => (
            <li
              key={`${mod.type}-${mod.stageIndex ?? "trip"}-${i}`}
              className="text-sm text-muted-foreground flex items-start gap-1.5"
            >
              <span className="mt-0.5 h-1.5 w-1.5 rounded-full bg-brand flex-shrink-0" />
              <span data-testid={`modification-item-${i}`}>{mod.label}</span>
            </li>
          ))}
        </ul>

        {/* Estimated time */}
        <div
          className="flex items-center gap-1.5 text-xs text-muted-foreground"
          data-testid="modification-queue-estimate"
        >
          <Clock className="h-3.5 w-3.5 flex-shrink-0" />
          <span>{estimatedLabel}</span>
        </div>

        {/* Actions */}
        <div className="flex gap-2 justify-end pt-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={onCancel}
            disabled={isApplying}
            data-testid="modification-queue-cancel"
          >
            <X className="h-3.5 w-3.5 mr-1.5" />
            {t("cancel")}
          </Button>
          <Button
            size="sm"
            onClick={onApply}
            disabled={isApplying}
            data-testid="modification-queue-apply"
          >
            {isApplying ? t("applying") : t("applyAll")}
          </Button>
        </div>
      </div>
    </div>
  );
}

"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { Check, Loader2, AlertTriangle } from "lucide-react";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/store/ui-store";
import { TripHeader } from "@/components/trip-header";

type CategoryKey =
  | "terrain_security"
  | "supply"
  | "accommodations"
  | "weather"
  | "services"
  | "ai";

interface CategoryDefinition {
  /** Translation key under `processingProgress.categories`. */
  translationKey: CategoryKey;
  icon: string;
  /**
   * Backend `ComputationName::value` identifiers that drive this narrative
   * category. A category is "done" once every listed step has been seen
   * in a `computation_step_completed` event. See issue #323 for the
   * handler → category mapping and `ComputationName` on the backend.
   */
  steps: string[];
  /**
   * When true, the category is hidden from the UI until at least one of
   * its steps has been reported. Used for the optional AI category: when
   * Ollama is disabled no `ai_*` events arrive, and the row stays hidden.
   */
  optional?: boolean;
}

/**
 * Narrative categories displayed to the user during Acte 2. Each row is
 * backed by one or more backend computation steps. Order matches the
 * visual order on screen (per issue #323).
 */
const CATEGORIES: { key: CategoryKey; def: CategoryDefinition }[] = [
  {
    key: "terrain_security",
    def: {
      translationKey: "terrain_security",
      icon: "🛣️",
      // ScanAllOsmData (osm_scan) + AnalyzeTerrain (terrain)
      steps: ["osm_scan", "terrain"],
    },
  },
  {
    key: "supply",
    def: {
      translationKey: "supply",
      icon: "💧",
      // CheckWaterPoints (water_points) + ScanPois (pois)
      steps: ["water_points", "pois"],
    },
  },
  {
    key: "accommodations",
    def: {
      translationKey: "accommodations",
      icon: "🏕️",
      // ScanAccommodations
      steps: ["accommodations"],
    },
  },
  {
    key: "weather",
    def: {
      translationKey: "weather",
      icon: "🌤️",
      // FetchWeather + AnalyzeWind + CheckCalendar
      steps: ["weather", "wind", "calendar"],
    },
  },
  {
    key: "services",
    def: {
      translationKey: "services",
      icon: "🔧",
      // CheckBikeShops
      steps: ["bike_shops"],
    },
  },
  {
    key: "ai",
    def: {
      translationKey: "ai",
      icon: "🤖",
      // AnalyzeStageWithLlm + AnalyzeTripOverviewWithLlm (Ollama-only)
      steps: ["ai_stage", "ai_overview"],
      optional: true,
    },
  },
];

type AggregatedStatus = "pending" | "in_progress" | "done" | "failed";

interface ProcessingProgressProps {
  title: string;
  onTitleChange: (title: string) => void;
}

/**
 * Acte 2 — narrative progress screen.
 *
 * Shown during Phase 2 (initial enrichment). Displays a checklist of
 * user-facing categories (terrain, supply, accommodations, weather,
 * services, optional AI) each backed by one or more Messenger handlers
 * on the backend. Rows transition through
 *   pending ○ → in progress (spinner) → done ✓ | failed ⚠
 * as `computation_step_completed` (and `computation_error`) Mercure events
 * arrive. A global percentage bar sits at the bottom.
 *
 * Rules:
 * - The only interactive affordance is the trip title. Every other action
 *   is intentionally omitted to keep the user focused while the backend
 *   is crunching through the pipeline (issue #323).
 * - The optional AI row is only rendered once at least one `ai_*` step
 *   has been seen — so when Ollama is disabled, the row stays hidden.
 * - `trip_ready` flips `isProcessing` to `false`, which is the trigger
 *   for the parent component to fade this screen out toward Acte 3.
 */
export function ProcessingProgress({
  title,
  onTitleChange,
}: ProcessingProgressProps) {
  const t = useTranslations("processingProgress");
  const analysisProgress = useUiStore((s) => s.analysisProgress);
  const stepStates = useUiStore((s) => s.analysisStepStates);
  const currentStep = analysisProgress?.step ?? null;

  const rows = useMemo(
    () =>
      CATEGORIES.map(({ key, def }) => {
        const statuses = def.steps.map(
          (step) => stepStates[step]?.status ?? "pending",
        );
        const errors = def.steps
          .map((step) => stepStates[step]?.error)
          .filter((e): e is string => !!e);

        let status: AggregatedStatus;
        if (statuses.includes("failed")) {
          status = "failed";
        } else if (statuses.every((s) => s === "done")) {
          status = "done";
        } else if (
          statuses.some((s) => s === "done") ||
          (currentStep !== null && def.steps.includes(currentStep))
        ) {
          // Either we already collected some results for this category or
          // the in-flight step belongs to it — highlight it as in progress.
          status = "in_progress";
        } else {
          status = "pending";
        }

        const hidden =
          def.optional &&
          statuses.every((s) => s === "pending") &&
          !(currentStep !== null && def.steps.includes(currentStep));

        return { key, def, status, error: errors[0] ?? null, hidden };
      }),
    [stepStates, currentStep],
  );

  const percent = analysisProgress
    ? Math.min(
        100,
        Math.max(
          0,
          Math.round(
            (analysisProgress.completed / Math.max(1, analysisProgress.total)) *
              100,
          ),
        ),
      )
    : 0;

  return (
    <section
      className="min-h-[60vh] flex flex-col items-center justify-center py-8 animate-in fade-in duration-300"
      data-testid="processing-progress"
      aria-live="polite"
      aria-busy="true"
    >
      <div className="w-full max-w-2xl space-y-6">
        {/* Editable title — the only affordance the user retains during
            the system step (see issue #323). */}
        <div className="text-center">
          <TripHeader title={title} onTitleChange={onTitleChange} />
          <p className="mt-2 text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>

        {/* Category checklist (boxed, per the design spec) */}
        <div
          className="rounded-xl border bg-card p-4 md:p-6 shadow-sm"
          data-testid="processing-progress-box"
        >
          <ul className="space-y-3">
            {rows.map(({ key, def, status, error, hidden }) => {
              if (hidden) return null;
              return (
                <li
                  key={key}
                  data-testid={`processing-category-${key}`}
                  data-status={status}
                  className={cn(
                    "flex items-start gap-3 rounded-lg px-3 py-2 transition-colors",
                    status === "in_progress" && "bg-brand/5",
                    status === "failed" && "bg-destructive/5",
                  )}
                >
                  <span
                    aria-hidden="true"
                    className="text-2xl leading-none shrink-0"
                  >
                    {def.icon}
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-semibold text-sm md:text-base">
                        {t(`categories.${def.translationKey}.label`)}
                      </span>
                      <StatusIndicator status={status} />
                    </div>
                    <p className="text-xs md:text-sm text-muted-foreground">
                      {t(`categories.${def.translationKey}.description`)}
                    </p>
                    {status === "failed" && error && (
                      <p
                        className="mt-1 text-xs text-destructive"
                        data-testid={`processing-category-${key}-error`}
                      >
                        {t("failureMessage", { message: error })}
                      </p>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>

          {/* Global progress bar */}
          <div className="mt-6">
            <div
              className="h-2 w-full rounded-full bg-muted overflow-hidden"
              role="progressbar"
              aria-valuenow={percent}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label={t("progressLabel")}
            >
              <div
                data-testid="processing-progress-bar"
                className="h-full bg-brand transition-all duration-300"
                style={{ width: `${percent}%` }}
              />
            </div>
            <p
              className="mt-2 text-xs text-muted-foreground text-right"
              data-testid="processing-progress-percent"
            >
              {t("progressPercent", { percent })}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

function StatusIndicator({ status }: { status: AggregatedStatus }) {
  const t = useTranslations("processingProgress");
  switch (status) {
    case "done":
      return (
        <span
          aria-label={t("statusLabels.done")}
          data-testid="status-done"
          className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand text-white"
        >
          <Check className="h-3 w-3" aria-hidden="true" />
        </span>
      );
    case "in_progress":
      return (
        <span
          aria-label={t("statusLabels.inProgress")}
          data-testid="status-in-progress"
          className="inline-flex h-5 w-5 items-center justify-center text-brand"
        >
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
        </span>
      );
    case "failed":
      return (
        <span
          aria-label={t("statusLabels.failed")}
          data-testid="status-failed"
          className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-destructive/10 text-destructive"
        >
          <AlertTriangle className="h-3 w-3" aria-hidden="true" />
        </span>
      );
    case "pending":
    default:
      return (
        <span
          aria-label={t("statusLabels.pending")}
          data-testid="status-pending"
          className="inline-flex h-5 w-5 items-center justify-center rounded-full border border-muted-foreground/30 text-muted-foreground/50"
        >
          <span className="h-2 w-2 rounded-full bg-muted-foreground/30" />
        </span>
      );
  }
}

"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import {
  Check,
  Loader2,
  AlertTriangle,
  Mountain,
  MapPin,
  Tent,
  CloudSun,
  CalendarDays,
  Shield,
  Sparkles,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/store/ui-store";
import { TripHeader } from "@/components/trip-header";

/**
 * Narrative acts displayed during step 3 "Analyse" of the wizard. Each act
 * groups one or more backend `ComputationName` values: when every backing
 * step has been seen in a `computation_step_completed` event the act flips
 * to "done"; otherwise the currently-running step (if it belongs to the act)
 * marks it as "in progress".
 *
 * The keys double as translation slugs under
 * `processingProgress.categories.<key>` (legacy keys are preserved to keep
 * the existing translations / tests working) and as `data-testid` suffixes.
 */
type ActKey =
  | "terrain_security"
  | "supply"
  | "accommodations"
  | "weather"
  | "services"
  | "context"
  | "ai";

interface ActDefinition {
  /** Translation slug under `processingProgress.categories.<key>`. */
  translationKey: ActKey;
  /** Lucide icon — keeps the visual identity consistent with the rest of the app. */
  icon: LucideIcon;
  /**
   * Backend `ComputationName::value` identifiers that drive this act. An act
   * is "done" once every listed step has been seen in a
   * `computation_step_completed` event.
   */
  steps: string[];
  /**
   * Optional acts are hidden until at least one of their steps has been
   * reported (e.g. AI when Ollama is disabled).
   */
  optional?: boolean;
}

/**
 * Seven narrative acts mapped onto the backend `ComputationName` enum:
 *   1. Analyse du terrain & sécurité — `osm_scan`, `terrain`
 *   2. Points d'intérêt & ravitaillement — `pois`, `water_points`, `cultural_pois`
 *   3. Hébergements — `accommodations`
 *   4. Météo & conditions — `weather`, `wind`
 *   5. Services & secours — `bike_shops`, `health_services`,
 *      `railway_stations`, `border_crossing`
 *   6. Contexte local — `calendar`, `events`
 *   7. Synthèse IA (optional) — `ai_stage`, `ai_overview`
 *
 * Order matches the visual order on screen and the importance ranking from
 * #391: terrain & security comes first, AI comes last.
 */
const ACTS: { key: ActKey; def: ActDefinition }[] = [
  {
    key: "terrain_security",
    def: {
      translationKey: "terrain_security",
      icon: Mountain,
      steps: ["osm_scan", "terrain"],
    },
  },
  {
    key: "supply",
    def: {
      translationKey: "supply",
      icon: MapPin,
      steps: ["pois", "water_points", "cultural_pois"],
    },
  },
  {
    key: "accommodations",
    def: {
      translationKey: "accommodations",
      icon: Tent,
      steps: ["accommodations"],
    },
  },
  {
    key: "weather",
    def: {
      translationKey: "weather",
      icon: CloudSun,
      steps: ["weather", "wind"],
    },
  },
  {
    key: "services",
    def: {
      translationKey: "services",
      icon: Shield,
      steps: [
        "bike_shops",
        "health_services",
        "railway_stations",
        "border_crossing",
      ],
    },
  },
  {
    key: "context",
    def: {
      translationKey: "context",
      icon: CalendarDays,
      steps: ["calendar", "events"],
    },
  },
  {
    key: "ai",
    def: {
      translationKey: "ai",
      icon: Sparkles,
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
 * Step 3 "Analyse" — narrative SSE display.
 *
 * Renders 5-8 acts (see {@link ACTS}), each backed by one or more backend
 * `ComputationName` values (issue #391). Acts transition through
 *   pending ○ → in progress (spinner) → done ✓ | failed ⚠
 * as `computation_step_completed` (and `computation_error`) Mercure events
 * arrive. A global percentage bar sits at the bottom.
 *
 * Each act exposes a **dynamic sub-description** computed from the latest
 * SSE payload (e.g. "Interrogation d'OpenStreetMap…" while
 * `osm_scan` runs, then "Terrain analysé" once both `osm_scan` and `terrain`
 * are done).
 *
 * Rules:
 * - The only interactive affordance is the trip title. Every other action is
 *   intentionally omitted to keep the user focused while the backend is
 *   crunching through the pipeline.
 * - The optional AI act stays hidden until at least one `ai_*` step has been
 *   seen — so when Ollama is disabled, the row never appears.
 * - `trip_ready` flips `isProcessing` to `false`, which is the trigger
 *   for the parent component to advance to step 4.
 */
export function ProcessingProgress({
  title,
  onTitleChange,
}: ProcessingProgressProps) {
  const t = useTranslations("processingProgress");
  const analysisProgress = useUiStore((s) => s.analysisProgress);
  const stepStates = useUiStore((s) => s.analysisStepStates);
  const currentStep = analysisProgress?.step ?? null;

  const acts = useMemo(
    () =>
      ACTS.map(({ key, def }) => {
        const statuses = def.steps.map(
          (step) => stepStates[step]?.status ?? "pending",
        );
        const errors = def.steps
          .map((step) => stepStates[step]?.error)
          .filter((e): e is string => !!e);
        const doneCount = statuses.filter((s) => s === "done").length;

        let status: AggregatedStatus;
        if (statuses.includes("failed")) {
          status = "failed";
        } else if (statuses.every((s) => s === "done")) {
          status = "done";
        } else if (
          statuses.some((s) => s === "done") ||
          (currentStep !== null && def.steps.includes(currentStep))
        ) {
          status = "in_progress";
        } else {
          status = "pending";
        }

        const hidden =
          def.optional &&
          statuses.every((s) => s === "pending") &&
          !(currentStep !== null && def.steps.includes(currentStep));

        return {
          key,
          def,
          status,
          error: errors[0] ?? null,
          doneCount,
          totalCount: def.steps.length,
          hidden,
        };
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
      <div className="w-full max-w-3xl space-y-6">
        {/* Editable title — the only affordance the user retains during
            step 3 (per #391). */}
        <div className="text-center">
          <TripHeader title={title} onTitleChange={onTitleChange} />
          <p className="mt-2 text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>

        {/* Narrative acts (boxed, per the design spec) */}
        <div
          className="rounded-xl border bg-card p-4 md:p-6 shadow-sm"
          data-testid="processing-progress-box"
        >
          <ul className="space-y-3">
            {acts.map(
              ({ key, def, status, error, doneCount, totalCount, hidden }) => {
                if (hidden) return null;
                const Icon = def.icon;
                return (
                  <li
                    key={key}
                    data-testid={`processing-category-${key}`}
                    data-status={status}
                    className={cn(
                      "flex items-start gap-3 rounded-lg px-3 py-3 transition-colors",
                      status === "in_progress" && "bg-brand/5",
                      status === "failed" && "bg-destructive/5",
                    )}
                  >
                    <span
                      aria-hidden="true"
                      className={cn(
                        "flex items-center justify-center w-10 h-10 rounded-full shrink-0",
                        status === "done"
                          ? "bg-brand/10 text-brand"
                          : status === "in_progress"
                            ? "bg-brand/15 text-brand"
                            : status === "failed"
                              ? "bg-destructive/10 text-destructive"
                              : "bg-muted text-muted-foreground/60",
                      )}
                    >
                      <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-semibold text-sm md:text-base">
                          {t(`categories.${def.translationKey}.label`)}
                        </span>
                        <StatusIndicator status={status} />
                      </div>
                      <p
                        className="text-xs md:text-sm text-muted-foreground"
                        data-testid={`processing-category-${key}-description`}
                      >
                        <ActDescription
                          actKey={def.translationKey}
                          status={status}
                          doneCount={doneCount}
                          totalCount={totalCount}
                        />
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
              },
            )}
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

interface ActDescriptionProps {
  actKey: ActKey;
  status: AggregatedStatus;
  doneCount: number;
  totalCount: number;
}

/**
 * Resolve the user-facing sub-description for an act, depending on its
 * lifecycle state. Each state maps to a distinct translation key under
 * `processingProgress.categories.<key>` so the UI text can be tuned per
 * locale without touching this component:
 *
 *   - `pending`     → `description` (default static)
 *   - `in_progress` → `running`     (e.g. "Interrogation d'OpenStreetMap…")
 *   - `done`        → `done`        (e.g. "Terrain analysé")
 *   - `failed`      → `failed`      (short "ne pas pu" message)
 *
 * `done`/`total` counts are passed as ICU variables so translations can use
 * them when relevant (e.g. "{done} / {total} services analysés").
 */
function ActDescription({
  actKey,
  status,
  doneCount,
  totalCount,
}: ActDescriptionProps) {
  const t = useTranslations("processingProgress.categories");
  const variants: Record<AggregatedStatus, string> = {
    pending: "description",
    in_progress: "running",
    done: "done",
    failed: "failed",
  };
  const variant = variants[status];
  return (
    <>{t(`${actKey}.${variant}`, { done: doneCount, total: totalCount })}</>
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

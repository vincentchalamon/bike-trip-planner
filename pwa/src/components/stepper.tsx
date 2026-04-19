"use client";

import { Check } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { useUiStore, STEPS } from "@/store/ui-store";
import type { StepId } from "@/store/ui-store";

/**
 * Visual 4-step progress indicator for the trip planning workflow.
 *
 * ```
 *   ●━━━━━━━━━━●━━━━━━━━━━○━━━━━━━━━━○
 *   Préparation  Aperçu    Analyse    Mon voyage
 * ```
 *
 * - Completed steps show a checkmark and are clickable (backwards navigation).
 * - The active step is visually highlighted.
 * - "Analyse" (system step) is **never** clickable.
 * - At step "Mon voyage" the stepper becomes a non-interactive visual indicator.
 * - On mobile a compact "Étape 2/4 — Aperçu" label is shown.
 */
export function Stepper() {
  const t = useTranslations("stepper");
  const currentStep = useUiStore((s) => s.currentStep);
  const completedSteps = useUiStore((s) => s.completedSteps);
  const goToStep = useUiStore((s) => s.goToStep);

  const isMyTrip = currentStep === "my_trip";
  const currentIndex = STEPS.indexOf(currentStep);

  /** Human-readable labels for each step, in order. */
  const stepLabels: Record<StepId, string> = {
    preparation: t("preparation"),
    preview: t("preview"),
    analysis: t("analysis"),
    my_trip: t("myTrip"),
  };

  function isClickable(step: StepId): boolean {
    // Non-interactive at "my_trip" (Act 3 lock)
    if (isMyTrip) return false;
    // "analysis" is always a system step
    if (step === "analysis") return false;
    // Cannot click the current active step
    if (step === currentStep) return false;
    // Only completed steps are backwards-navigable
    const stepIdx = STEPS.indexOf(step);
    if (stepIdx < currentIndex) return completedSteps.has(step);
    // Forward steps are not navigable directly (flow is driven by app logic)
    return false;
  }

  return (
    <nav
      role="navigation"
      aria-label={t("ariaLabel")}
      data-testid="stepper"
      className="w-full"
    >
      {/* Mobile compact label */}
      <div
        className="sm:hidden text-sm text-muted-foreground text-center py-2"
        data-testid="stepper-mobile-label"
        aria-hidden="true"
      >
        {t("mobileLabel", {
          current: currentIndex + 1,
          total: STEPS.length,
          stepName: stepLabels[currentStep],
        })}
      </div>

      {/* Desktop horizontal stepper */}
      <ol
        className="hidden sm:flex items-center w-full"
        aria-label={t("ariaLabel")}
      >
        {STEPS.map((step, index) => {
          const isActive = step === currentStep;
          const isCompleted = completedSteps.has(step);
          const isPast = index < currentIndex;
          const isFuture = index > currentIndex;
          const clickable = isClickable(step);

          return (
            <li
              key={step}
              className={cn(
                "flex items-center",
                index < STEPS.length - 1 ? "flex-1" : "",
              )}
            >
              {/* Step button / indicator */}
              <div className="flex flex-col items-center gap-1 relative">
                {clickable ? (
                  <button
                    type="button"
                    onClick={() => goToStep(step)}
                    aria-current={isActive ? "step" : undefined}
                    aria-label={stepLabels[step]}
                    data-testid={`stepper-step-${step}`}
                    className={cn(
                      "flex items-center justify-center w-8 h-8 rounded-full border-2 transition-colors duration-200 cursor-pointer",
                      "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand",
                      isCompleted
                        ? "border-brand bg-brand text-white hover:bg-brand/80"
                        : "border-brand bg-background text-brand hover:bg-brand/10",
                    )}
                  >
                    {isCompleted ? (
                      <Check className="w-4 h-4" aria-hidden="true" />
                    ) : (
                      <span className="text-xs font-semibold">{index + 1}</span>
                    )}
                  </button>
                ) : (
                  <div
                    role="listitem"
                    aria-current={isActive ? "step" : undefined}
                    aria-label={stepLabels[step]}
                    data-testid={`stepper-step-${step}`}
                    className={cn(
                      "flex items-center justify-center w-8 h-8 rounded-full border-2 transition-colors duration-200",
                      isActive
                        ? "border-brand bg-brand text-white"
                        : isCompleted || isPast
                          ? "border-brand bg-brand text-white"
                          : isFuture
                            ? "border-muted-foreground/30 bg-background text-muted-foreground/50"
                            : "border-brand bg-background text-brand",
                    )}
                  >
                    {isCompleted || isPast ? (
                      <Check className="w-4 h-4" aria-hidden="true" />
                    ) : (
                      <span className="text-xs font-semibold">{index + 1}</span>
                    )}
                  </div>
                )}

                {/* Step label */}
                <span
                  className={cn(
                    "text-xs whitespace-nowrap absolute top-full mt-1",
                    isActive
                      ? "text-foreground font-semibold"
                      : isCompleted || isPast
                        ? "text-muted-foreground"
                        : "text-muted-foreground/50",
                  )}
                >
                  {stepLabels[step]}
                </span>
              </div>

              {/* Connector line between steps */}
              {index < STEPS.length - 1 && (
                <div
                  className={cn(
                    "flex-1 h-0.5 mx-2 transition-colors duration-200",
                    index < currentIndex
                      ? "bg-brand"
                      : "bg-muted-foreground/20",
                  )}
                  aria-hidden="true"
                />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

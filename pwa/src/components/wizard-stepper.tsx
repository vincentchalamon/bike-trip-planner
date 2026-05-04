"use client";

import { useCallback } from "react";
import { Check } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";

/**
 * Wizard step identifiers — the four acts of the `/trips/new` flow.
 *
 * - `preparation` (1) — user picks an input method (link, GPX, AI assistant)
 * - `preview`     (2) — coarse route preview, pacing sliders, "Lancer l'analyse"
 * - `analysis`    (3) — narrative SSE display (system step, never clickable)
 * - `my_trip`     (4) — redirect to `/trips/[id]` (visual end-state only)
 */
export type WizardStepId = "preparation" | "preview" | "analysis" | "my_trip";

export const WIZARD_STEPS: WizardStepId[] = [
  "preparation",
  "preview",
  "analysis",
  "my_trip",
];

/**
 * Map a 1-based wizard step number from the URL (`?step=1..4`) to its
 * canonical {@link WizardStepId}. Returns `preparation` for any out-of-range
 * input so the user always lands on a valid screen.
 */
export function wizardStepFromNumber(step: number | null): WizardStepId {
  if (step === null || step < 1 || step > WIZARD_STEPS.length) {
    return "preparation";
  }
  return WIZARD_STEPS[step - 1] ?? "preparation";
}

/** Inverse of {@link wizardStepFromNumber}. */
export function wizardStepToNumber(step: WizardStepId): number {
  return WIZARD_STEPS.indexOf(step) + 1;
}

interface WizardStepperProps {
  /** Currently active step. */
  currentStep: WizardStepId;
  /** Set of steps the user has completed (drives back-navigation affordances). */
  completedSteps: Set<WizardStepId>;
  /**
   * Optional callback fired when the user clicks a previously completed step.
   * `analysis` is never reported (system step). When omitted, the stepper
   * renders as a read-only progress indicator.
   */
  onNavigate?: (step: WizardStepId) => void;
}

/**
 * Desktop-first 4-step horizontal wizard indicator for `/trips/new`.
 *
 * ```
 *   ●━━━━━━━━━━●━━━━━━━━━━○━━━━━━━━━━○
 *   1            2            3            4
 *   Préparation  Aperçu       Analyse      Mon voyage
 * ```
 *
 * Behaviour:
 * - Completed steps render as a checkmark and are clickable (when `onNavigate`
 *   is provided) — the parent decides whether to consume the click and rewind
 *   the wizard. The current step is highlighted; future steps are dimmed.
 * - "Analyse" (step 3) is **never** clickable. It is a system step driven by
 *   the SSE pipeline, not a user-controlled screen.
 * - On `my_trip` the entire stepper becomes read-only — the user has reached
 *   the trip dashboard.
 * - On mobile the stepper collapses to a compact "Étape N/4 — Nom" label
 *   (per the issue spec).
 */
export function WizardStepper({
  currentStep,
  completedSteps,
  onNavigate,
}: WizardStepperProps) {
  const t = useTranslations("stepper");
  const currentIndex = WIZARD_STEPS.indexOf(currentStep);
  const isMyTrip = currentStep === "my_trip";

  const stepLabels: Record<WizardStepId, string> = {
    preparation: t("preparation"),
    preview: t("preview"),
    analysis: t("analysis"),
    my_trip: t("myTrip"),
  };

  const isClickable = useCallback(
    (step: WizardStepId): boolean => {
      if (!onNavigate) return false;
      // Lock once the user has reached the final step.
      if (isMyTrip) return false;
      // System step — never user-navigable.
      if (step === "analysis") return false;
      // Cannot click the active step.
      if (step === currentStep) return false;
      // Only completed (past) steps are reachable backwards.
      const stepIdx = WIZARD_STEPS.indexOf(step);
      if (stepIdx < currentIndex) return completedSteps.has(step);
      return false;
    },
    [completedSteps, currentIndex, currentStep, isMyTrip, onNavigate],
  );

  const handleClick = useCallback(
    (step: WizardStepId) => {
      if (!onNavigate) return;
      onNavigate(step);
    },
    [onNavigate],
  );

  return (
    <nav
      role="navigation"
      aria-label={t("ariaLabel")}
      data-testid="wizard-stepper"
      data-current-step={currentStep}
      className="w-full"
    >
      {/* Mobile compact label — "Étape 2/4 — Aperçu" */}
      <div
        className="sm:hidden text-sm text-muted-foreground text-center py-2"
        data-testid="wizard-stepper-mobile-label"
        aria-hidden="true"
      >
        {t("mobileLabel", {
          current: currentIndex + 1,
          total: WIZARD_STEPS.length,
          stepName: stepLabels[currentStep],
        })}
      </div>

      {/* Desktop horizontal stepper */}
      <ol className="hidden sm:flex items-start w-full">
        {WIZARD_STEPS.map((step, index) => {
          const isActive = step === currentStep;
          const isCompleted = completedSteps.has(step);
          const isPast = index < currentIndex;
          const isFuture = index > currentIndex;
          const clickable = isClickable(step);
          const number = index + 1;

          return (
            <li
              key={step}
              className={cn(
                "flex items-start",
                index < WIZARD_STEPS.length - 1 ? "flex-1" : "",
              )}
            >
              {/* Step indicator (button when clickable, div otherwise) */}
              <div className="flex flex-col items-center gap-2 relative">
                {clickable ? (
                  <button
                    type="button"
                    onClick={() => handleClick(step)}
                    aria-current={isActive ? "step" : undefined}
                    aria-label={stepLabels[step]}
                    data-testid={`wizard-stepper-step-${step}`}
                    data-step-number={number}
                    className={cn(
                      "flex items-center justify-center w-9 h-9 rounded-full border-2 transition-colors duration-200 cursor-pointer",
                      "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand",
                      "border-brand bg-brand text-white hover:bg-brand/80",
                    )}
                  >
                    {isCompleted ? (
                      <Check className="w-4 h-4" aria-hidden="true" />
                    ) : (
                      <span className="text-sm font-semibold">{number}</span>
                    )}
                  </button>
                ) : (
                  <div
                    aria-current={isActive ? "step" : undefined}
                    aria-label={stepLabels[step]}
                    data-testid={`wizard-stepper-step-${step}`}
                    data-step-number={number}
                    className={cn(
                      "flex items-center justify-center w-9 h-9 rounded-full border-2 transition-colors duration-200",
                      isActive
                        ? "border-brand bg-brand text-white shadow-sm"
                        : isCompleted || isPast
                          ? "border-brand bg-brand text-white"
                          : isFuture
                            ? "border-muted-foreground/30 bg-background text-muted-foreground/50"
                            : "border-brand bg-background text-brand",
                    )}
                  >
                    {isCompleted || (isPast && !isActive) ? (
                      <Check className="w-4 h-4" aria-hidden="true" />
                    ) : (
                      <span className="text-sm font-semibold">{number}</span>
                    )}
                  </div>
                )}

                {/* Step label */}
                <span
                  className={cn(
                    "text-xs whitespace-nowrap font-medium",
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
              {index < WIZARD_STEPS.length - 1 && (
                <div
                  className={cn(
                    "flex-1 h-0.5 mt-[18px] mx-2 transition-colors duration-200",
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

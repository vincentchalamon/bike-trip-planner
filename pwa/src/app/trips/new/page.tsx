"use client";

import { Suspense, useCallback, useEffect, useMemo, useRef } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import {
  WizardStepper,
  WIZARD_STEPS,
  wizardStepFromNumber,
  wizardStepToNumber,
  type WizardStepId,
} from "@/components/wizard-stepper";
import { useUiStore } from "@/store/ui-store";
import type { StepId } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";

/**
 * `/trips/new` — 4-step wizard for trip creation (issue #391).
 *
 * Each act of the planner now sits behind an explicit URL-addressable step:
 *
 *   ?step=1 → preparation (Card Selection: Lien / GPX / Assistant IA)
 *   ?step=2 → preview     (Map + stats + stages + sliders + "Lancer l'analyse")
 *   ?step=3 → analysis    (Narrative SSE display)
 *   ?step=4 → my_trip     (Redirect to `/trips/[id]`)
 *
 * The {@link WizardStepper} mirrors the URL: clicking a completed step rewrites
 * `?step=` and rewinds the wizard. The query param is the source of truth so
 * the workflow is shareable, refresh-safe, and bookmarkable.
 *
 * Implementation note: we reuse the battle-tested {@link TripPlanner} for the
 * underlying state machine (URL submit, GPX upload, preview, analysis,
 * my_trip), but suppress its internal `<Stepper />` — the new
 * `<WizardStepper />` rendered at the top of this page replaces it for the
 * `/trips/new` route only.
 */
function WizardContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const t = useTranslations("stepper");
  const currentStep = useUiStore((s) => s.currentStep);
  const completedSteps = useUiStore((s) => s.completedSteps);
  const goToStep = useUiStore((s) => s.goToStep);
  const tripId = useTripStore((s) => s.trip?.id ?? null);

  const requestedStep = useMemo<WizardStepId | null>(() => {
    const raw = searchParams.get("step");
    if (raw === null) return null;
    const parsed = Number.parseInt(raw, 10);
    return Number.isFinite(parsed) ? wizardStepFromNumber(parsed) : null;
  }, [searchParams]);

  const clearTrip = useTripStore((s) => s.clearTrip);

  // Mirror `currentStep` into a ref so the URL→store effect can read the
  // latest value without re-running every time the store advances. This
  // prevents a race where `setProcessing(true)` synchronously moves the
  // store to "analysis" while the URL is still `?step=1` (router.replace is
  // async): the effect would otherwise observe the stale URL and call
  // `navigateToStep("preparation")`, wiping the trip and looping.
  const currentStepRef = useRef<WizardStepId>(currentStep as WizardStepId);
  currentStepRef.current = currentStep as WizardStepId;

  // Resolve a backwards navigation request (from the stepper or the URL):
  // when going back to "preparation" we must also clear the trip data, or
  // the lifecycle effect in {@link TripPlanner} would immediately re-advance
  // the stepper to "preview". Other backwards transitions are pure store
  // mutations.
  const navigateToStep = useCallback(
    (step: WizardStepId) => {
      if (step === "preparation") {
        clearTrip();
        const ui = useUiStore.getState();
        ui.setProcessing(false);
        ui.setAccommodationScanning(false);
        ui.resetStepper();
        return;
      }
      goToStep(step as StepId);
    },
    [clearTrip, goToStep],
  );

  // URL → store: when the URL contains a `?step=` value, drive the store. Only
  // apply it for backwards navigation (forward navigation is dictated by the
  // app logic — e.g. analysis is reached only after the user clicks
  // "Lancer l'analyse"). We rely on the store's own guards (see `goToStep`).
  useEffect(() => {
    if (requestedStep === null) return;
    // Read the latest store step from a ref so this effect only re-runs when
    // the URL changes — not when the store advances asynchronously.
    const current = currentStepRef.current;
    if (requestedStep === current) return;
    // The "analysis" step is system-driven and never reachable via URL.
    if (requestedStep === "analysis") return;
    // Forward navigation via URL is also blocked: the user must reach forward
    // steps via in-app actions (submitting a URL, clicking "Lancer l'analyse").
    const requestedIdx = WIZARD_STEPS.indexOf(requestedStep);
    const currentIdx = WIZARD_STEPS.indexOf(current);
    if (requestedIdx > currentIdx) return;
    navigateToStep(requestedStep);
  }, [requestedStep, navigateToStep]);

  // Store → URL: keep `?step=` in sync whenever the store advances. Use
  // `replace` so the back button doesn't pile up history entries for each
  // intermediate state.
  useEffect(() => {
    const current = searchParams.get("step");
    const desired = String(wizardStepToNumber(currentStep));
    if (current === desired) return;
    const next = new URLSearchParams(searchParams.toString());
    next.set("step", desired);
    router.replace(`/trips/new?${next.toString()}`, { scroll: false });
  }, [currentStep, router, searchParams]);

  // Step 4 "Mon voyage" → redirect to /trips/[id] once the trip identity is
  // known. We intentionally wait for `tripId` to be set; in the unlikely
  // event the wizard reaches `my_trip` without an identity (defensive), we
  // simply stay on the wizard and let the user manage from there.
  useEffect(() => {
    if (currentStep !== "my_trip") return;
    if (!tripId) return;
    router.replace(`/trips/${encodeURIComponent(tripId)}`);
  }, [currentStep, tripId, router]);

  const handleStepperNavigate = useCallback(
    (step: WizardStepId) => navigateToStep(step),
    [navigateToStep],
  );

  // Render the WizardStepper outside TripPlanner's main layout because
  // TripPlanner manages its own padded section. We mount everything inside
  // a single page wrapper so `/trips/new` looks coherent.
  return (
    <div data-testid="wizard-trip-new" data-current-step={currentStep}>
      <a
        href="#wizard-content"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        {t("ariaLabel")}
      </a>

      {/* Step indicator — desktop horizontal stepper, mobile compact label.
          {@link WizardStepId} and {@link StepId} share the same string union,
          so the cast is structurally safe — it only narrows the nominal type. */}
      <div className="max-w-[1200px] mx-auto px-4 md:px-6 pt-6 pb-2">
        <WizardStepper
          currentStep={currentStep as WizardStepId}
          completedSteps={completedSteps as Set<WizardStepId>}
          onNavigate={handleStepperNavigate}
        />
      </div>

      <div id="wizard-content">
        <TripPlanner hideStepper />
      </div>
    </div>
  );
}

/**
 * `/trips/new` page — wraps the wizard in the standard error/hydration
 * boundaries used elsewhere in the app. The {@link Suspense} is required by
 * `useSearchParams` for static export compatibility (see Next.js docs).
 */
export default function NewTripPage() {
  return (
    <HydrationBoundary>
      <TripPlannerErrorBoundary>
        <Suspense fallback={null}>
          <WizardContent />
        </Suspense>
      </TripPlannerErrorBoundary>
    </HydrationBoundary>
  );
}

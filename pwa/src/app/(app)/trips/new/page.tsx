"use client";

import { Suspense, useEffect } from "react";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";

/**
 * `/trips/new` — Saisie screen (ADR-043, PR4-front).
 *
 * The 4-step creation wizard (Saisie → Aperçu → Analyse → Voyage) is gone.
 * This route now only hosts the data-entry step: {@link TripPlanner} renders
 * its welcome state (the {@link CardSelection} card — Lien / GPX / Assistant
 * IA). Submitting a source POSTs the trip and `router.push('/trips/{id}')`
 * (handled inside `useTripPlanner`), where the structural computation result
 * arrives synchronously and the per-block weather / AI enrichments stream in.
 *
 * A bare `/trips/new` visit (e.g. the header "Nouveau voyage" link) clears any
 * stale trip so the Saisie screen always starts fresh and never bounces to a
 * leftover trip (recette #649).
 */
function NewTripContent() {
  const clearTrip = useTripStore((s) => s.clearTrip);

  useEffect(() => {
    clearTrip();
    const ui = useUiStore.getState();
    ui.setProcessing(false);
    ui.setAccommodationScanning(false);
    ui.setBlockStatus("weather", null);
    ui.setBlockStatus("ai", null);
    ui.setConfigPanelOpen(false);
    // Run once on mount: arriving on `/trips/new` is an explicit fresh-start
    // intent. Subsequent in-page state changes must not re-clear the trip.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return <TripPlanner />;
}

export default function NewTripPage() {
  return (
    <HydrationBoundary>
      <TripPlannerErrorBoundary>
        <Suspense fallback={null}>
          <NewTripContent />
        </Suspense>
      </TripPlannerErrorBoundary>
    </HydrationBoundary>
  );
}

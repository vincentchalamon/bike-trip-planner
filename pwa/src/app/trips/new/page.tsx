import { Suspense } from "react";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";

/**
 * New trip creation page.
 * Renders the full trip planner so authenticated users can import a route
 * or upload a GPX file to start planning a new trip.
 */
export default function NewTripPage() {
  return (
    <HydrationBoundary>
      <TripPlannerErrorBoundary>
        <Suspense fallback={null}>
          <TripPlanner />
        </Suspense>
      </TripPlannerErrorBoundary>
    </HydrationBoundary>
  );
}

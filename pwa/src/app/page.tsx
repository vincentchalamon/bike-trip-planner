import { Suspense } from "react";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";

export default function Page() {
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

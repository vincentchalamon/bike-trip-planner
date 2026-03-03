import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";

export default function Page() {
  return (
    <HydrationBoundary>
      <TripPlanner />
    </HydrationBoundary>
  );
}

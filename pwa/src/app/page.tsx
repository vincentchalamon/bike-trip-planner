"use client";

import { Suspense, useEffect, useState } from "react";
import { useAuthStore } from "@/store/auth-store";
import { LandingPage } from "@/components/landing-page";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";

/**
 * Home page — dual-mode component:
 * - Unauthenticated visitors (after auth check): marketing landing page
 * - Authenticated users: trip planner (existing behaviour)
 *
 * Runs a silent refresh on mount to restore the session from the httpOnly
 * refresh_token cookie before deciding which view to render. This prevents
 * a flash of the landing page for returning authenticated users.
 */
function HomePageContent() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const silentRefresh = useAuthStore((s) => s.silentRefresh);
  const [refreshAttempted, setRefreshAttempted] = useState(false);

  useEffect(() => {
    if (isAuthenticated) return;
    void silentRefresh().finally(() => setRefreshAttempted(true));
  }, [isAuthenticated, silentRefresh]);

  // Show nothing while the initial auth check is in flight
  if (!isAuthenticated && !refreshAttempted) {
    return null;
  }

  if (!isAuthenticated) {
    return <LandingPage />;
  }

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

export default function Page() {
  return (
    <Suspense fallback={null}>
      <HomePageContent />
    </Suspense>
  );
}

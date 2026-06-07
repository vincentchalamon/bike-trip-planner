"use client";

import { Suspense, useEffect, useRef } from "react";
import { useAuthStore } from "@/store/auth-store";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { LandingPage } from "@/components/landing-page";

/**
 * Home page (dual-state: anonymous landing / authenticated dashboard).
 *
 * `/` is public, so it runs its own silent auth check on mount. The landing is
 * rendered on the FIRST (server) pass — instead of the previous `null` — so the
 * SSR HTML carries the landing's `<main>` + `<h1>` (audit 35.2 A11Y-001/002 +
 * LH-A11Y, and a better LCP). Authenticated users briefly see the landing during
 * the silent refresh, then swap to the dashboard. (A `cookies()`-based hint would
 * avoid that flash but makes the route dynamic, which is incompatible with the
 * static mobile/Capacitor `output: export` build.)
 */
function HomeContent() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const checkStarted = useRef(false);

  useEffect(() => {
    if (checkStarted.current) return;
    checkStarted.current = true;
    if (isAuthenticated) return;
    void useAuthStore.getState().silentRefresh();
  }, [isAuthenticated]);

  if (!isAuthenticated) {
    return (
      <Suspense fallback={null}>
        <LandingPage />
      </Suspense>
    );
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
      <HomeContent />
    </Suspense>
  );
}

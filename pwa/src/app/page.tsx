"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import { useAuthStore } from "@/store/auth-store";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { LandingPage } from "@/components/landing-page";

/**
 * Home page.
 *
 * - Authenticated users see the TripPlanner.
 * - Unauthenticated users see the public LandingPage with early access form.
 *
 * Since `/` is a public route (no AuthGuard redirect), this component performs
 * its own silent auth check on mount to avoid flashing the landing page to
 * users who have a valid refresh token.
 */
function HomeContent() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  // When the user is already authenticated on mount we can render immediately;
  // otherwise we must await the silent refresh before deciding what to render.
  const [refreshDone, setRefreshDone] = useState(false);
  const checkStarted = useRef(false);

  useEffect(() => {
    if (checkStarted.current) return;
    checkStarted.current = true;

    if (isAuthenticated) return;

    const check = async () => {
      try {
        await useAuthStore.getState().silentRefresh();
      } finally {
        setRefreshDone(true);
      }
    };

    void check();
  }, [isAuthenticated]);

  // Render nothing while checking auth to avoid flash
  if (!isAuthenticated && !refreshDone) {
    return null;
  }

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

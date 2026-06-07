"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import { useAuthStore } from "@/store/auth-store";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { LandingPage } from "@/components/landing-page";

/**
 * Dual-state home content (anonymous landing / authenticated dashboard).
 *
 * `authHint` is derived by the server from the presence of the httpOnly
 * `refresh_token` cookie. It drives the FIRST client render so it matches the
 * server output (no hydration mismatch) and authenticated users do not flash
 * the landing. Once the silent refresh resolves, the auth store is the source of
 * truth (e.g. a stale cookie falls back to the landing).
 */
export function HomeContent({ authHint }: { authHint: "authed" | "anon" }) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const [refreshDone, setRefreshDone] = useState(false);
  const checkStarted = useRef(false);

  useEffect(() => {
    if (checkStarted.current) return;
    checkStarted.current = true;

    if (isAuthenticated) {
      setRefreshDone(true);
      return;
    }

    const check = async () => {
      try {
        await useAuthStore.getState().silentRefresh();
      } finally {
        setRefreshDone(true);
      }
    };

    void check();
  }, [isAuthenticated]);

  // Before the silent refresh resolves, trust the server cookie hint so SSR and
  // the first client render agree; afterwards the store decides.
  const showDashboard = refreshDone ? isAuthenticated : "authed" === authHint;

  if (!showDashboard) {
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

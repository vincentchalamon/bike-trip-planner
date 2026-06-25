"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import { useAuthStore } from "@/store/auth-store";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { LandingPage } from "@/components/landing-page";
import { SiteChrome } from "@/components/site-chrome";

/**
 * Client half of the home page. `initialAuthed` is the server's read of the
 * refresh-token cookie (web build only):
 *
 * - `true`  — the server saw a session, so render the dashboard (never the
 *   landing) so a logged-in user does not see the landing flash (#649).
 * - `false` — no cookie: render the landing (this keeps the logged-out SSR with
 *   its `<main>`/`<h1>` for SEO / LCP / a11y).
 * - `null`  — static mobile build (no server cookie access): fall back to the
 *   pure client-side check, as before.
 *
 * Once `silentRefresh` resolves, the store's `isAuthenticated` becomes
 * authoritative, so a stale cookie (hint `true` but the refresh fails) falls
 * back to the landing instead of being stuck on an unauthenticated dashboard.
 *
 * The dashboard (TripPlanner) is browser-only, so it is rendered after mount and
 * never server-rendered; the server only decides *not* to render the landing.
 */
export function HomeContent({
  initialAuthed,
}: {
  initialAuthed: boolean | null;
}) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const checkStarted = useRef(false);
  const [checked, setChecked] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
    if (checkStarted.current) return;
    checkStarted.current = true;
    if (isAuthenticated) {
      setChecked(true);
      return;
    }
    void useAuthStore
      .getState()
      .silentRefresh()
      .finally(() => setChecked(true));
  }, [isAuthenticated]);

  // Before the silent check resolves, trust the server hint to avoid the
  // landing→dashboard flash; afterwards trust the real auth state.
  const showDashboard = checked
    ? isAuthenticated
    : isAuthenticated || initialAuthed === true;

  if (!showDashboard) {
    return (
      <Suspense fallback={null}>
        <LandingPage />
      </Suspense>
    );
  }

  // SSR (and the first client render) return null for the dashboard: TripPlanner
  // is browser-only and unsafe to server-render. Crucially the landing is NOT
  // rendered here, so the logged-in user never sees it — the dashboard appears
  // on mount without waiting for the network.
  if (!mounted) {
    return null;
  }

  return (
    <SiteChrome variant="app">
      <HydrationBoundary>
        <TripPlannerErrorBoundary>
          <Suspense fallback={null}>
            <TripPlanner />
          </Suspense>
        </TripPlannerErrorBoundary>
      </HydrationBoundary>
    </SiteChrome>
  );
}

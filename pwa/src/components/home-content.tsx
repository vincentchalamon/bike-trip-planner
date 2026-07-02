"use client";

import { Suspense, useEffect, useState } from "react";
import { useAuthStore } from "@/store/auth-store";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { LandingPage } from "@/components/landing-page";
import { SiteChrome } from "@/components/site-chrome";

/**
 * Client half of the home page. `initialAuthed` is the server's VALIDATED auth
 * state (ADR-047 — a real `/auth/session` check, not a cookie-presence guess):
 *
 * - `true`  — validated session → render the dashboard (never the landing) so a
 *   logged-in user sees no landing flash (#649), and a stale/revoked cookie no
 *   longer briefly shows the dashboard shell.
 * - `false` — no/invalid session → render the landing (keeps the logged-out SSR
 *   with its `<main>`/`<h1>` for SEO / LCP / a11y).
 * - `null`  — static mobile build (no server) or a backend blip → fall back to
 *   the pure client-side check.
 *
 * Once the client bootstrap resolves, `isAuthenticated` becomes authoritative
 * (handles a later client login/logout). The dashboard (TripPlanner) is
 * browser-only, rendered after mount and never server-rendered.
 */
export function HomeContent({
  initialAuthed,
}: {
  initialAuthed: boolean | null;
}) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const [checked, setChecked] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
    // Defer to the shared, deduped auth bootstrap (AuthGuard runs it too, so
    // this fires no extra /auth/refresh). It just flips `checked` once the auth
    // state has resolved, after which the store is authoritative.
    void useAuthStore
      .getState()
      .ensureResolved()
      .finally(() => setChecked(true));
  }, []);

  // Before the client resolves, trust the server-validated hint to avoid the
  // landing→dashboard flash; afterwards the store's isAuthenticated is
  // authoritative (handles a later client login/logout).
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
    <SiteChrome>
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

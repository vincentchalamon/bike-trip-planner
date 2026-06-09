"use client";

import { useEffect, useRef } from "react";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { driver } from "driver.js";
import "driver.js/dist/driver.css";
import { useOnboarding } from "@/hooks/use-onboarding";
import { useAuthStore } from "@/store/auth-store";

/**
 * OnboardingTour — renders nothing in the DOM.
 *
 * On an authenticated user's first home-page visit (no localStorage flag), it
 * starts a 4-step driver.js tour through the core workflow:
 *   1. Paste a Komoot link
 *   2. Upload a GPX file (alternative input)
 *   3. Configure the rider profile (pacing / fatigue)
 *   4. Read stages in the timeline (shown after a trip loads)
 *
 * Steps 1–2 target elements that only exist on the authenticated home page
 * (`card-link` / `card-gpx`). Steps 3–4 are centred modals (no DOM target):
 * step 3 describes the rider profile (the settings gear only appears once a
 * trip is open), step 4 the timeline, since no trip data is loaded yet on a
 * first visit.
 *
 * The tour is dismissed by finishing, pressing Escape, or clicking the
 * overlay. In all cases `markOnboardingDone()` is called so the tour
 * never shows again.
 */
export function OnboardingTour() {
  const t = useTranslations("onboarding");
  const pathname = usePathname();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const { hasSeenOnboarding, markOnboardingDone } = useOnboarding();
  const startedRef = useRef(false);

  useEffect(() => {
    // The tour targets the authenticated planner's cards (card-link / card-gpx),
    // which only exist on the home page once signed in — never on the public
    // landing. Gating on auth also keeps the anon landing clear of driver.js's
    // injected helper elements (accessibility: aria-allowed-attr / contrast).
    if (pathname !== "/" || !isAuthenticated) {
      // Reset so the tour can restart if the user re-authenticates on this same
      // mount (e.g. logs out mid-tour then back in) without a full reload.
      startedRef.current = false;
      return;
    }
    // Wait until localStorage has been read (null = not yet resolved)
    if (hasSeenOnboarding !== false) return;
    // Guard against StrictMode double-invoke
    if (startedRef.current) return;
    startedRef.current = true;

    const driverObj = driver({
      showProgress: true,
      animate: true,
      overlayOpacity: 0.6,
      popoverClass: "onboarding-popover",
      disableActiveInteraction: true,
      nextBtnText: t("nextBtn"),
      prevBtnText: t("prevBtn"),
      doneBtnText: t("doneBtn"),
      onDestroyed: () => {
        markOnboardingDone();
        // Clean up test helper if present
        delete (window as Window & { __onboardingDone?: () => void })
          .__onboardingDone;
      },
      steps: [
        {
          // Step 1 — paste a link (Link card on the welcome screen)
          element: "[data-testid='card-link']",
          popover: {
            title: t("step1Title"),
            description: t.raw("step1Description"),
            side: "bottom",
            align: "start",
          },
        },
        {
          // Step 2 — GPX upload alternative (GPX card on the welcome screen)
          element: "[data-testid='card-gpx']",
          popover: {
            title: t("step2Title"),
            description: t.raw("step2Description"),
            side: "bottom",
            align: "center",
          },
        },
        {
          // Step 3 — configure pacing. The settings gear now lives in the
          // top bar and only appears once a trip is open (#384), so on the
          // welcome screen this step is a centred modal with no DOM target.
          popover: {
            title: t("step3Title"),
            description: t.raw("step3Description"),
            side: "over",
          },
        },
        {
          // Step 4 — centred modal describing what happens after a trip loads
          popover: {
            title: t("step4Title"),
            description: t.raw("step4Description"),
            side: "over",
          },
        },
      ],
    });

    // Expose a test helper so E2E tests can complete the tour programmatically
    if (
      (window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean })
        .__PLAYWRIGHT_SHOW_ONBOARDING
    ) {
      (window as Window & { __onboardingDone?: () => void }).__onboardingDone =
        () => driverObj.destroy();
    }

    // Small delay so the page has fully painted before the tour starts
    const timeout = setTimeout(() => {
      driverObj.drive();
    }, 800);

    return () => {
      clearTimeout(timeout);
      // Destroy without triggering onDestroyed if the component unmounts early
      if (driverObj.isActive()) {
        driverObj.destroy();
      }
    };
  }, [pathname, isAuthenticated, hasSeenOnboarding, markOnboardingDone, t]);

  return null;
}

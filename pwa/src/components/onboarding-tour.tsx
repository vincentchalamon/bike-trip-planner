"use client";

import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { driver } from "driver.js";
import "driver.js/dist/driver.css";
import { useOnboarding } from "@/hooks/use-onboarding";

/**
 * OnboardingTour — renders nothing in the DOM.
 *
 * On first visit (no localStorage flag), automatically starts a 4-step
 * driver.js tour that walks the user through the core workflow:
 *   1. Paste a Komoot link
 *   2. Upload a GPX file (alternative input)
 *   3. Configure the rider profile (pacing / fatigue)
 *   4. Read stages in the timeline (shown after a trip loads)
 *
 * Steps 1–3 target elements that are always in the DOM. Step 4 is a
 * centred modal (no DOM target) that describes the timeline, since no
 * trip data is loaded yet on a first visit.
 *
 * The tour is dismissed by finishing, pressing Escape, or clicking the
 * overlay. In all cases `markOnboardingDone()` is called so the tour
 * never shows again.
 */
export function OnboardingTour() {
  const t = useTranslations("onboarding");
  const { hasSeenOnboarding, markOnboardingDone } = useOnboarding();
  const startedRef = useRef(false);

  useEffect(() => {
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
          // Step 1 — paste a link
          element: "[data-testid='magic-link-input']",
          popover: {
            title: t("step1Title"),
            description: t.raw("step1Description"),
            side: "bottom",
            align: "start",
          },
        },
        {
          // Step 2 — GPX upload alternative
          element: "[data-testid='gpx-upload-button']",
          popover: {
            title: t("step2Title"),
            description: t.raw("step2Description"),
            side: "bottom",
            align: "center",
          },
        },
        {
          // Step 3 — configure pacing via the settings button
          element: "[data-testid='config-open-button']",
          popover: {
            title: t("step3Title"),
            description: t.raw("step3Description"),
            side: "bottom",
            align: "end",
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
  }, [hasSeenOnboarding, markOnboardingDone, t]);

  return null;
}

"use client";

import { useCallback, useEffect, useState } from "react";

const ONBOARDING_STORAGE_KEY = "bike-trip-planner:onboarding-done";

/**
 * Hook managing the onboarding tour state.
 *
 * Persists to localStorage so the tour is shown only once per browser.
 * The `hasSeenOnboarding` value is `null` during SSR/hydration to avoid
 * hydration mismatches; it resolves to a boolean on the client.
 */
export function useOnboarding() {
  const [hasSeenOnboarding, setHasSeenOnboarding] = useState<boolean | null>(
    null,
  );

  // Read from localStorage after hydration (in a microtask to satisfy lint rule)
  useEffect(() => {
    Promise.resolve().then(() => {
      // Skip onboarding in automated test environments.
      // navigator.webdriver is true in all Playwright/Selenium sessions;
      // __PLAYWRIGHT_SHOW_ONBOARDING allows specific tests to opt back in.
      const isAutomated = navigator.webdriver;
      const forceShow =
        (window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean })
          .__PLAYWRIGHT_SHOW_ONBOARDING === true;
      if (isAutomated && !forceShow) {
        setHasSeenOnboarding(true);
        return;
      }
      try {
        const stored = localStorage.getItem(ONBOARDING_STORAGE_KEY);
        setHasSeenOnboarding(stored === "true");
      } catch {
        // localStorage may be unavailable (e.g. private browsing with strict settings)
        setHasSeenOnboarding(true);
      }
    });
  }, []);

  const markOnboardingDone = useCallback(() => {
    try {
      localStorage.setItem(ONBOARDING_STORAGE_KEY, "true");
    } catch {
      // ignore write errors
    }
    setHasSeenOnboarding(true);
  }, []);

  return { hasSeenOnboarding, markOnboardingDone };
}

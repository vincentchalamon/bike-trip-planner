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

  const resetOnboarding = useCallback(() => {
    try {
      localStorage.removeItem(ONBOARDING_STORAGE_KEY);
    } catch {
      // ignore
    }
    setHasSeenOnboarding(false);
  }, []);

  return { hasSeenOnboarding, markOnboardingDone, resetOnboarding };
}

"use client";

import { useEffect, useState } from "react";

/**
 * Reactive wrapper around `navigator.onLine`.
 *
 * Subscribes to the `online` / `offline` window events so consumers re-render
 * whenever connectivity changes. Defaults to `true` during SSR and before the
 * browser-side effect runs, so the UI is optimistic by default.
 */
export function useOnlineStatus(): boolean {
  const [isOnline, setIsOnline] = useState<boolean>(() => {
    if (typeof navigator === "undefined") return true;
    return navigator.onLine !== false;
  });

  useEffect(() => {
    if (typeof window === "undefined") return;

    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    // Sync with the current value in case it changed between the initial
    // render and the effect (e.g. hydration after a navigation while offline).
    setIsOnline(navigator.onLine !== false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

  return isOnline;
}

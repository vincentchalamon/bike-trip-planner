"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { WifiOff, Wifi } from "lucide-react";
import { useOfflineStore } from "@/store/offline-store";

/**
 * Listens to `navigator.onLine` and `online`/`offline` browser events,
 * syncing the result into {@link useOfflineStore}.
 *
 * Displays a banner when offline:
 *   "Hors ligne — consultation des données en cache. Modification désactivée."
 *
 * On reconnection, briefly shows "Connexion rétablie." / "Connection restored."
 * before auto-dismissing after 3 seconds.
 */
export function OfflineBanner() {
  const t = useTranslations("offline");
  const isOnline = useOfflineStore((s) => s.isOnline);
  const setOnline = useOfflineStore((s) => s.setOnline);
  const [showReconnected, setShowReconnected] = useState(false);

  useEffect(() => {
    function handleOnline() {
      setOnline(true);
      setShowReconnected(true);
    }

    function handleOffline() {
      setOnline(false);
      setShowReconnected(false);
    }

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);

    // Sync initial state
    setOnline(navigator.onLine);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, [setOnline]);

  // Auto-dismiss the "reconnected" banner after 3 seconds
  useEffect(() => {
    if (!showReconnected) return;
    const timer = setTimeout(() => setShowReconnected(false), 3000);
    return () => clearTimeout(timer);
  }, [showReconnected]);

  if (isOnline && !showReconnected) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="offline-banner"
      className={[
        "flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg mb-4",
        isOnline
          ? "bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200"
          : "bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
      ].join(" ")}
    >
      {isOnline ? (
        <>
          <Wifi className="h-4 w-4 shrink-0" />
          <span>{t("reconnected")}</span>
        </>
      ) : (
        <>
          <WifiOff className="h-4 w-4 shrink-0" />
          <span>{t("offlineMessage")}</span>
        </>
      )}
    </div>
  );
}

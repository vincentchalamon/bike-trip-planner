"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { WifiOff, Wifi } from "lucide-react";
import { useOnlineStatus } from "@/hooks/use-online-status";

/**
 * Displays a banner when offline:
 *   "Hors ligne — consultation des données en cache. Modification désactivée."
 *
 * On reconnection, briefly shows "Connexion rétablie." / "Connection restored."
 * before auto-dismissing after 3 seconds.
 */
export function OfflineBanner() {
  const t = useTranslations("offline");
  const isOnline = useOnlineStatus();
  const [showReconnected, setShowReconnected] = useState(false);

  useEffect(() => {
    const handleOnline = () => setShowReconnected(true);
    const handleOffline = () => setShowReconnected(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

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

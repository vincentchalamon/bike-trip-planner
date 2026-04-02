"use client";

import { useEffect } from "react";
import { useTranslations, useLocale } from "next-intl";
import dayjs from "dayjs";
import "dayjs/locale/fr";
import { WifiOff } from "lucide-react";
import { useOfflineStore } from "@/store/offline-store";
import { useTripStore } from "@/store/trip-store";

export function SavedTripsSection() {
  const t = useTranslations();
  const locale = useLocale();
  const savedTrips = useOfflineStore((s) => s.savedTrips);
  const loadSavedTrips = useOfflineStore((s) => s.loadSavedTrips);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const loadFromSavedTrip = useTripStore((s) => s.loadFromSavedTrip);

  useEffect(() => {
    void loadSavedTrips();
    // Run only on mount
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (savedTrips.length === 0) return null;

  return (
    <div className="w-full max-w-2xl mt-4" data-testid="saved-trips-section">
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
          {t("offline.savedTrips.title")}
        </h2>
      </div>
      <ul className="space-y-2" role="list">
        {savedTrips.map((trip) => {
          const stageCount = trip.stages.filter((s) => !s.isRestDay).length;
          const distanceKm = Math.round((trip.totalDistance ?? 0) / 1000);

          return (
            <li key={trip.id}>
              <button
                type="button"
                className="w-full text-left rounded-lg border bg-card px-4 py-3 shadow-sm hover:bg-accent/50 transition-colors cursor-pointer"
                onClick={() => loadFromSavedTrip(trip)}
                data-testid={`saved-trip-card-${trip.id}`}
              >
                <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                  <span className="font-medium truncate">{trip.title}</span>
                  {distanceKm > 0 && (
                    <span className="text-sm text-muted-foreground">
                      {distanceKm} km
                    </span>
                  )}
                  {stageCount > 0 && (
                    <span className="text-sm text-muted-foreground">
                      {t("offline.savedTrips.stages", { count: stageCount })}
                    </span>
                  )}
                  {!isOnline && (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground border rounded px-1.5 py-0.5">
                      <WifiOff className="h-3 w-3" />
                      {t("offline.savedTrips.offlineBadge")}
                    </span>
                  )}
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                  {trip.startDate || trip.endDate ? (
                    <span>
                      {trip.startDate
                        ? dayjs(trip.startDate)
                            .locale(locale)
                            .format("D MMM YYYY")
                        : "?"}
                      {" — "}
                      {trip.endDate
                        ? dayjs(trip.endDate)
                            .locale(locale)
                            .format("D MMM YYYY")
                        : "?"}
                    </span>
                  ) : (
                    <span>{t("offline.savedTrips.noDate")}</span>
                  )}
                </div>
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

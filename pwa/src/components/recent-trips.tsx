"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { formatDistanceKm } from "@/lib/formatters";
import { API_URL } from "@/lib/constants";
import type { components } from "@/lib/api/schema";

type TripListItem = components["schemas"]["Trip.TripListItem.jsonld"];
type TripCollection = components["schemas"]["HydraCollectionBaseSchema"] & {
  member: TripListItem[];
};

export function RecentTrips() {
  const t = useTranslations();
  const router = useRouter();
  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [totalItems, setTotalItems] = useState(0);
  const [isLoading, setIsLoading] = useState(true);

  const fetchRecentTrips = useCallback(async () => {
    try {
      const params = new URLSearchParams({
        page: "1",
        itemsPerPage: "5",
      });
      const res = await apiFetch(`${API_URL}/trips?${params.toString()}`, {
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) return;
      const data = (await res.json()) as TripCollection;
      setTrips(data.member ?? []);
      setTotalItems(data.totalItems ?? 0);
    } catch {
      // silently ignore — recent trips is a non-critical widget
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void fetchRecentTrips();
  }, [fetchRecentTrips]);

  if (isLoading) {
    return (
      <div
        className="flex justify-center py-4"
        data-testid="recent-trips-loading"
      >
        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (trips.length === 0) return null;

  return (
    <div className="w-full max-w-2xl mt-4" data-testid="recent-trips">
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
          {t("recentTrips.title")}
        </h2>
        {totalItems > 0 && (
          <Link
            href="/trips"
            className="text-sm text-brand hover:underline"
            data-testid="recent-trips-view-all"
          >
            {t("recentTrips.viewAll", { count: totalItems })} →
          </Link>
        )}
      </div>
      <ul className="space-y-2" role="list">
        {trips.map((trip) => (
          <li key={trip.id}>
            <button
              type="button"
              className="w-full text-left rounded-lg border bg-card px-4 py-3 shadow-sm hover:bg-accent/50 transition-colors cursor-pointer"
              onClick={() => router.push(`/trips/${trip.id ?? ""}`)}
              data-testid={`recent-trip-${trip.id}`}
            >
              <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span className="font-medium truncate">
                  {trip.title ?? t("tripList.untitled")}
                </span>
                {(trip.totalDistance ?? 0) > 0 && (
                  <span className="text-sm text-muted-foreground">
                    {formatDistanceKm(trip.totalDistance ?? 0)}
                  </span>
                )}
              </div>
              <div className="mt-0.5 text-xs text-muted-foreground">
                {trip.startDate || trip.endDate ? (
                  <span>
                    {trip.startDate
                      ? new Date(trip.startDate).toLocaleDateString()
                      : "?"}
                    {" — "}
                    {trip.endDate
                      ? new Date(trip.endDate).toLocaleDateString()
                      : "?"}
                  </span>
                ) : (
                  <span>{t("recentTrips.noDate")}</span>
                )}
              </div>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

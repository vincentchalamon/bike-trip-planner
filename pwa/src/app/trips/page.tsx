"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Trash2, ChevronLeft, ChevronRight, Loader2, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiFetch } from "@/lib/api/client";
import { formatDistanceKm } from "@/lib/formatters";
import { API_URL } from "@/lib/constants";
import { Badge } from "@/components/ui/badge";
import type { components } from "@/lib/api/schema";

type TripListItem = components["schemas"]["Trip.TripListItem.jsonld"] & {
  status?: string;
};
type TripCollection = components["schemas"]["HydraCollectionBaseSchema"] & {
  member: TripListItem[];
};

function TripStatusBadge({ status }: { status?: string }) {
  const t = useTranslations("tripList");

  if (status === "analyzing") {
    return (
      <Badge
        variant="secondary"
        className="bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border-amber-200 dark:border-amber-700"
        data-testid="status-analyzing"
      >
        {t("status_analyzing")}
      </Badge>
    );
  }

  if (status === "analyzed") {
    return (
      <Badge
        variant="secondary"
        className="bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 border-green-200 dark:border-green-700"
        data-testid="status-analyzed"
      >
        {t("status_analyzed")}
      </Badge>
    );
  }

  // draft (default)
  return (
    <Badge variant="outline" data-testid="status-draft">
      {t("status_draft")}
    </Badge>
  );
}

const ITEMS_PER_PAGE = 10;

export default function TripsPage() {
  const t = useTranslations("tripList");
  const router = useRouter();

  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [totalItems, setTotalItems] = useState(0);
  const [page, setPage] = useState(1);
  const [titleFilter, setTitleFilter] = useState("");
  const [debouncedTitle, setDebouncedTitle] = useState("");
  const [startDateFilter, setStartDateFilter] = useState("");
  const [endDateFilter, setEndDateFilter] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState<TripListItem | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // Debounce title filter
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedTitle(titleFilter);
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [titleFilter]);

  const fetchTrips = useCallback(async () => {
    setIsLoading(true);
    setLoadError(false);

    const params = new URLSearchParams({
      page: String(page),
      itemsPerPage: String(ITEMS_PER_PAGE),
    });
    if (debouncedTitle) params.set("title", debouncedTitle);
    if (startDateFilter) params.set("startDate", startDateFilter);
    if (endDateFilter) params.set("endDate", endDateFilter);

    try {
      const res = await apiFetch(`${API_URL}/trips?${params.toString()}`, {
        headers: { Accept: "application/ld+json" },
      });

      if (!res.ok) {
        setLoadError(true);
        return;
      }

      const data = (await res.json()) as TripCollection;
      setTrips(data.member ?? []);
      setTotalItems(data.totalItems ?? 0);
    } catch {
      setLoadError(true);
    } finally {
      setIsLoading(false);
    }
  }, [page, debouncedTitle, startDateFilter, endDateFilter]);

  useEffect(() => {
    void fetchTrips();
  }, [fetchTrips]);

  async function handleDelete() {
    if (!deleteTarget) return;

    setIsDeleting(true);
    try {
      const res = await apiFetch(
        `${API_URL}/trips/${encodeURIComponent(deleteTarget.id ?? "")}`,
        { method: "DELETE" },
      );

      if (res.ok) {
        toast.success(t("deleteSuccess"));
        setDeleteTarget(null);
        await fetchTrips();
      } else {
        toast.error(t("deleteError"));
      }
    } catch {
      toast.error(t("deleteError"));
    } finally {
      setIsDeleting(false);
    }
  }

  const totalPages = Math.max(1, Math.ceil(totalItems / ITEMS_PER_PAGE));

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-4 mb-8">
        <h1 className="text-2xl font-bold">{t("title")}</h1>
        <div className="flex items-center gap-2">
          <Button
            asChild
            variant="default"
            size="sm"
            data-testid="new-trip-button"
          >
            <Link href="/trips/new">{t("newTrip")}</Link>
          </Button>
          <Button
            asChild
            variant="ghost"
            size="icon"
            title={t("close")}
            aria-label={t("close")}
          >
            <Link href="/">
              <X className="h-4 w-4" />
            </Link>
          </Button>
        </div>
      </div>

      {/* Filters */}
      <div className="mb-6 flex flex-wrap items-end gap-3">
        <Input
          type="search"
          placeholder={t("filterPlaceholder")}
          value={titleFilter}
          onChange={(e) => setTitleFilter(e.target.value)}
          className="max-w-sm"
          aria-label={t("filterPlaceholder")}
        />
        <div className="flex items-center gap-2">
          <label className="text-sm text-muted-foreground whitespace-nowrap">
            {t("filterFrom")}
          </label>
          <Input
            type="date"
            value={startDateFilter}
            onChange={(e) => {
              setStartDateFilter(e.target.value);
              setPage(1);
            }}
            className="w-40"
            aria-label={t("filterFrom")}
          />
        </div>
        <div className="flex items-center gap-2">
          <label className="text-sm text-muted-foreground whitespace-nowrap">
            {t("filterUntil")}
          </label>
          <Input
            type="date"
            value={endDateFilter}
            onChange={(e) => {
              setEndDateFilter(e.target.value);
              setPage(1);
            }}
            className="w-40"
            aria-label={t("filterUntil")}
          />
        </div>
        {(titleFilter || startDateFilter || endDateFilter) && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setTitleFilter("");
              setDebouncedTitle("");
              setStartDateFilter("");
              setEndDateFilter("");
              setPage(1);
            }}
            data-testid="clear-filters-button"
          >
            <X className="h-4 w-4 mr-1" />
            {t("clearFilters")}
          </Button>
        )}
      </div>

      {/* Loading */}
      {isLoading && (
        <div
          className="flex items-center justify-center py-16 gap-3 text-muted-foreground"
          aria-live="polite"
          aria-busy="true"
        >
          <Loader2 className="h-5 w-5 animate-spin" />
          <span>{t("loading")}</span>
        </div>
      )}

      {/* Error */}
      {!isLoading && loadError && (
        <div className="text-center py-16">
          <p className="text-destructive mb-4">{t("loadingError")}</p>
          <Button variant="outline" onClick={() => void fetchTrips()}>
            {t("retry")}
          </Button>
        </div>
      )}

      {/* Empty state */}
      {!isLoading && !loadError && trips.length === 0 && (
        <div className="text-center py-16 text-muted-foreground">
          <p>{t("noTrips")}</p>
        </div>
      )}

      {/* Trip list */}
      {!isLoading && !loadError && trips.length > 0 && (
        <>
          <p className="text-sm text-muted-foreground mb-3" aria-live="polite">
            {t("totalItems", { count: totalItems })}
          </p>
          <ul className="space-y-3" role="list">
            {trips.map((trip) => (
              <li key={trip.id}>
                <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-4 shadow-sm hover:bg-accent/50 transition-colors">
                  {/* Clickable trip info */}
                  <button
                    type="button"
                    className="flex-1 text-left min-w-0 cursor-pointer"
                    onClick={() => router.push(`/trips/${trip.id ?? ""}`)}
                    data-testid={`trip-item-${trip.id ?? ""}`}
                  >
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                      <span className="font-semibold truncate">
                        {trip.title ?? t("untitled")}
                      </span>
                      <TripStatusBadge status={trip.status} />
                      {(trip.stageCount ?? 0) > 0 && (
                        <span className="text-sm text-muted-foreground">
                          {t("stages", { count: trip.stageCount ?? 0 })}
                        </span>
                      )}
                      {(trip.totalDistance ?? 0) > 0 && (
                        <span className="text-sm text-muted-foreground">
                          {formatDistanceKm(trip.totalDistance ?? 0)}
                        </span>
                      )}
                    </div>
                    <div className="mt-1 text-sm text-muted-foreground">
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
                        <span>{t("noDates")}</span>
                      )}
                    </div>
                  </button>

                  {/* Actions */}
                  <div className="flex items-center gap-1 shrink-0">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10"
                      onClick={() => setDeleteTarget(trip)}
                      title={t("deleteTrip")}
                      aria-label={t("deleteTrip")}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>

          {/* Pagination */}
          {totalPages > 1 && (
            <nav
              className="flex items-center justify-center gap-3 mt-8"
              aria-label={t("page", { current: page, total: totalPages })}
            >
              <Button
                variant="outline"
                size="icon"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                aria-label={t("previousPage")}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <span className="text-sm text-muted-foreground">
                {t("page", { current: page, total: totalPages })}
              </span>
              <Button
                variant="outline"
                size="icon"
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                aria-label={t("nextPage")}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </nav>
          )}
        </>
      )}

      {/* Delete confirmation dialog */}
      <Dialog
        open={!!deleteTarget}
        onOpenChange={(open) => {
          if (!open) setDeleteTarget(null);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("deleteTripConfirmTitle")}</DialogTitle>
            <DialogDescription>
              {t("deleteTripConfirmDescription", {
                title: deleteTarget?.title ?? "",
              })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setDeleteTarget(null)}
              disabled={isDeleting}
            >
              {t("deleteTripCancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={isDeleting}
            >
              {isDeleting && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
              {t("deleteTripConfirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </main>
  );
}

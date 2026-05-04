"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { Bike, SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";

export type TripsEmptyStateVariant = "empty" | "no-results";

interface TripsEmptyStateProps {
  variant: TripsEmptyStateVariant;
  /** Human-readable summary of currently active filters (no-results only). */
  activeFiltersLabel?: string;
  /** Reset all filters (no-results only). */
  onResetFilters?: () => void;
}

/**
 * Two mutually-exclusive empty states for the /trips page:
 *   - `empty`     : the user has no trips at all → encourage creating one.
 *   - `no-results`: filters are active but yield zero results → reset/keep searching.
 */
export function TripsEmptyState({
  variant,
  activeFiltersLabel,
  onResetFilters,
}: TripsEmptyStateProps) {
  const t = useTranslations("tripList.emptyState");

  if (variant === "no-results") {
    return (
      <section
        className="mx-auto flex max-w-md flex-col items-center text-center py-12 md:py-16"
        data-testid="trips-empty-no-results"
        aria-live="polite"
      >
        <div
          className="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-muted"
          aria-hidden="true"
        >
          <SearchX className="h-10 w-10 text-muted-foreground" />
        </div>
        <h2 className="font-serif text-2xl font-semibold tracking-tight">
          {t("noResultsTitle")}
        </h2>
        {activeFiltersLabel && (
          <p
            className="mt-3 text-sm text-muted-foreground"
            data-testid="trips-empty-active-filters"
          >
            {t("activeFilters")} {activeFiltersLabel}
          </p>
        )}
        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          {onResetFilters && (
            <Button
              variant="default"
              onClick={onResetFilters}
              data-testid="trips-empty-reset-filters"
            >
              {t("resetFilters")}
            </Button>
          )}
          <Button
            asChild
            variant="ghost"
            data-testid="trips-empty-new-trip-secondary"
          >
            <Link href="/trips/new">{t("newTrip")}</Link>
          </Button>
        </div>
      </section>
    );
  }

  // variant === "empty"
  return (
    <section
      className="mx-auto flex max-w-md flex-col items-center text-center py-12 md:py-16"
      data-testid="trips-empty-no-trips"
      aria-live="polite"
    >
      <div
        className="relative mb-6 flex h-28 w-28 items-center justify-center rounded-full bg-brand-light"
        aria-hidden="true"
      >
        <Bike className="h-14 w-14 text-brand" strokeWidth={1.5} />
        <span className="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-emerald-500 shadow-md" />
        <span className="absolute -left-2 bottom-1 h-2 w-2 rounded-full bg-amber-400 shadow-sm" />
      </div>
      <h2 className="font-serif text-2xl md:text-3xl font-semibold tracking-tight">
        {t("createFirstTitle")}
      </h2>
      <p className="mt-3 text-base text-muted-foreground">
        {t("createFirstDescription")}
      </p>
      <Button
        asChild
        variant="default"
        size="lg"
        className="mt-6"
        data-testid="trips-empty-new-trip-primary"
      >
        <Link href="/trips/new">{t("newTrip")}</Link>
      </Button>
    </section>
  );
}

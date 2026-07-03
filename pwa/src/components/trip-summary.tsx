import { useTranslations, useLocale } from "next-intl";
import dayjs from "dayjs";
import "dayjs/locale/fr";
import "dayjs/locale/en";
import {
  ArrowUp,
  ArrowDown,
  Bike,
  Mountain,
  Info,
  Euro,
  CalendarDays,
  User,
  Pencil,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { NoDatesBanner } from "@/components/no-dates-banner";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import { getActivePresetKey } from "@/lib/pacing-presets";
import { useUiStore } from "@/store/ui-store";
import type { WeatherData } from "@/lib/validation/schemas";

interface TripSummaryProps {
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  weather: WeatherData | null;
  isWeatherLoading?: boolean;
  isProcessing?: boolean;
  estimatedBudgetMin?: number;
  estimatedBudgetMax?: number;
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  /**
   * When true (editable trip view), surface the "no dates set" alert under the
   * trip info (recette #649). Off in read-only contexts (shared view) where the
   * config drawer that the CTA opens is not mounted.
   */
  showNoDatesBanner?: boolean;
  /**
   * Read-only (shared) view: render the dates / profile as plain labels with no
   * edit affordance (no click, no pencil), since the config drawer they would
   * open is not mounted there (recette #649).
   */
  readOnly?: boolean;
}

export function TripSummary({
  totalDistance,
  totalElevation,
  totalElevationLoss,
  weather,
  isWeatherLoading,
  isProcessing,
  estimatedBudgetMin,
  estimatedBudgetMax,
  startDate,
  endDate,
  fatigueFactor,
  elevationPenalty,
  maxDistancePerDay,
  averageSpeed,
  showNoDatesBanner = false,
  readOnly = false,
}: TripSummaryProps) {
  const t = useTranslations("tripSummary");
  const tPacing = useTranslations("pacing");
  const locale = useLocale();
  const openConfigPanelAt = useUiStore((s) => s.openConfigPanelAt);
  const weatherBlockStatus = useUiStore((s) => s.blockStatus.weather);
  const showSkeleton =
    isProcessing && totalDistance === null && totalElevation === null;

  // Per-block weather spinner (ADR-043). Spin while the block is pending /
  // running. Anti-infinite-spinner guard: a `null` block (TTL expired
  // server-side) never spins — weather is rendered when present, otherwise the
  // row stays silent. `isWeatherLoading` (derived from the global processing
  // flag) is kept as a fallback for legacy / mocked flows that don't drive
  // `blockStatus`.
  const isWeatherPending =
    weatherBlockStatus === "pending" || weatherBlockStatus === "running";
  const showWeatherSkeleton = !weather && (isWeatherPending || isWeatherLoading);

  if (!showSkeleton && totalDistance === null && totalElevation === null)
    return null;

  const WeatherIcon = weather
    ? (weatherIconMap[weather.icon] ?? DefaultWeatherIcon)
    : DefaultWeatherIcon;

  const activePresetKey = getActivePresetKey(
    maxDistancePerDay,
    averageSpeed,
    elevationPenalty,
    fatigueFactor,
  );

  const profileLabel = activePresetKey
    ? tPacing(`preset_${activePresetKey}`)
    : t("customProfile");

  const formatDateRange = (start: string, end: string) => {
    const s = dayjs(start).locale(locale);
    const e = dayjs(end).locale(locale);
    if (s.month() === e.month() && s.year() === e.year()) {
      // Same month: "19 → 21 mars"
      return `${s.date()} → ${e.format("D MMM")}`;
    }
    // Different months: "31 mars → 2 avril"
    return `${s.format("D MMM")} → ${e.format("D MMM")}`;
  };

  const datesDisplay =
    startDate && endDate
      ? formatDateRange(startDate, endDate)
      : startDate
        ? dayjs(startDate).locale(locale).format("D MMM")
        : t("noDates");

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-muted-foreground">
        <div className="flex items-center gap-1.5">
          <Bike className="h-4 w-4 text-brand" />
          {totalDistance !== null ? (
            <span data-testid="total-distance">
              {Math.round(totalDistance)}km
            </span>
          ) : (
            <Skeleton className="w-16 h-4" />
          )}
        </div>
        <div className="flex items-center gap-1.5">
          <Mountain className="h-4 w-4 text-orange-500" />
          {totalElevation !== null ? (
            <span
              className="flex items-center gap-1"
              data-testid="total-elevation"
            >
              <ArrowUp className="h-3 w-3 text-red-500" />
              {Math.round(totalElevation)}m
              <ArrowDown className="h-3 w-3 text-blue-500" />
              {Math.round(totalElevationLoss ?? 0)}m
            </span>
          ) : (
            <Skeleton className="w-24 h-4" />
          )}
        </div>
        {/* Force line break on mobile after distance+elevation */}
        <div className="basis-full h-0 md:hidden" aria-hidden="true" />
        <div className="flex items-center gap-1.5">
          {weather ? (
            <>
              <WeatherIcon className="h-4 w-4" />
              <span>
                {weather.description}, {Math.round(weather.tempMin)}-
                {Math.round(weather.tempMax)}°C
              </span>
            </>
          ) : showWeatherSkeleton ? (
            <Skeleton className="w-32 h-4" data-testid="weather-skeleton" />
          ) : null}
        </div>
        {estimatedBudgetMin !== undefined &&
          estimatedBudgetMax !== undefined &&
          (estimatedBudgetMin > 0 || estimatedBudgetMax > 0) && (
            <div className="flex items-center gap-1.5">
              <Euro className="h-4 w-4 text-green-600" />
              <span data-testid="estimated-budget">
                {Math.round(estimatedBudgetMin)}€ —{" "}
                {Math.round(estimatedBudgetMax)}€
              </span>
            </div>
          )}
        {/* Force line break on mobile */}
        <div className="basis-full h-0 md:hidden" aria-hidden="true" />
        {/* Dates chip — clickable (editable view) → opens ConfigPanel dates
            section with a pencil hint; a plain, non-interactive label in the
            read-only shared view (recette #649). */}
        {readOnly ? (
          <div className="flex items-center gap-1.5" data-testid="summary-dates">
            <CalendarDays className="h-4 w-4 text-brand" />
            <span>{datesDisplay}</span>
          </div>
        ) : (
          <button
            type="button"
            className="group flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
            onClick={() => openConfigPanelAt("dates")}
            // No aria-label: it would override the visible dates and break WCAG
            // 2.5.3 (Label in Name). The visible text is the accessible name; the
            // edit affordance is conveyed by the title (A11Y-002).
            title={t("editDatesHint")}
            data-testid="summary-dates"
          >
            <CalendarDays className="h-4 w-4 text-brand" aria-hidden="true" />
            <span>{datesDisplay}</span>
            <Pencil
              className="h-3 w-3 text-muted-foreground/60 group-hover:text-foreground transition-colors"
              aria-hidden="true"
            />
          </button>
        )}
        {/* Cyclo profile chip — clickable (editable view) → opens ConfigPanel
            pacing section with a pencil hint; a plain label in the read-only
            shared view (recette #649). */}
        {readOnly ? (
          <div
            className="flex items-center gap-1.5"
            data-testid="summary-profile"
          >
            <User className="h-4 w-4 text-brand" />
            <span>{profileLabel}</span>
          </div>
        ) : (
          <button
            type="button"
            className="group flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
            onClick={() => openConfigPanelAt("pacing")}
            // No aria-label: it would override the visible profile and break WCAG
            // 2.5.3 (Label in Name). The visible text is the accessible name (A11Y-002).
            title={t("editProfileHint")}
            data-testid="summary-profile"
          >
            <User className="h-4 w-4 text-brand" aria-hidden="true" />
            <span>{profileLabel}</span>
            <Pencil
              className="h-3 w-3 text-muted-foreground/60 group-hover:text-foreground transition-colors"
              aria-hidden="true"
            />
          </button>
        )}
      </div>

      {/* No-dates alert — moved here (recette #649) so it sits directly under
          the trip info and above the disclaimer, keeping its amber alert style.
          Only shown in the editable trip view when no start date is set.
          Centered so the banner shrinks to its content and lines up with the
          centered info block above instead of stretching full-width (#729). */}
      {showNoDatesBanner && !startDate && (
        <div className="flex justify-center pt-1">
          <NoDatesBanner onOpenConfig={() => openConfigPanelAt("dates")} />
        </div>
      )}

      <p className="flex items-center justify-center gap-1 text-xs text-muted-foreground">
        <Info className="h-3 w-3" aria-hidden="true" />
        {t("disclaimer")}
      </p>
    </div>
  );
}

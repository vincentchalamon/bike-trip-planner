"use client";

import { useTranslations } from "next-intl";
import {
  AlertTriangle,
  Clock,
  ExternalLink,
  MapPin,
  Phone,
  UtensilsCrossed,
  Tent,
  Droplet,
  Wrench,
} from "lucide-react";
import type { PoiSuggestion } from "@/store/ui-store";
import { cn } from "@/lib/utils";

interface PoiCardProps {
  poi: PoiSuggestion;
}

const CATEGORY_ICONS: Record<
  string,
  React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>
> = {
  food: UtensilsCrossed,
  shelter: Tent,
  water: Droplet,
  mechanic: Wrench,
};

/**
 * Formats meters as a compact human-readable string (e.g. `450 m`, `2.3 km`).
 *
 * Avoids `Intl.NumberFormat` setup churn at render time: the chat panel can
 * render a handful of cards per message and we want the cheapest possible
 * formatter.
 */
function formatDistance(meters: number): string {
  if (!Number.isFinite(meters) || meters < 0) return "—";
  if (meters < 1000) return `${Math.round(meters)} m`;
  return `${(meters / 1000).toFixed(1).replace(/\.0$/, "")} km`;
}

/**
 * Extracts the closing time (HH:MM) from a RFC 3339 timestamp without
 * dragging in `date-fns`. Falls back to the empty string when the input is
 * unparseable so the caller can decide what to render.
 */
function formatClosingTime(iso: string | null): string {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleTimeString(undefined, {
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * Single POI card rendered beneath an in-ride assistant reply.
 *
 * Surfaces the essentials a rider needs in a glance: category icon, name,
 * distance from the current position, today's opening-hours raw string, a
 * "closes at HH:MM" badge when the venue is about to shut, and the optional
 * detour penalty. The "Open in Google Maps" button uses the backend-provided
 * deeplink so we never have to assemble lat/lon URLs client-side.
 */
export function PoiCard({ poi }: PoiCardProps) {
  const t = useTranslations("chat.inRide");

  const Icon = CATEGORY_ICONS[poi.category] ?? MapPin;
  const closesAt = formatClosingTime(poi.closes_at ?? null);
  const hasOpeningHours =
    !!poi.opening_hours_today && poi.opening_hours_today.trim() !== "";
  const hasWarning = !!poi.warning && poi.warning.trim() !== "";

  return (
    <article
      data-testid="poi-card"
      data-category={poi.category}
      className={cn(
        "flex flex-col gap-2 rounded-xl border border-border bg-background p-3 shadow-sm",
        hasWarning && "border-amber-300 bg-amber-50/40",
      )}
    >
      <header className="flex items-start gap-2">
        <span
          className={cn(
            "inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full",
            "bg-brand/10 text-brand",
          )}
          aria-hidden="true"
        >
          <Icon className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold leading-tight text-foreground truncate">
            {poi.name}
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {formatDistance(poi.distance_m)}
            {poi.detour_m > 0 && (
              <span
                data-testid="poi-card-detour"
                className="ml-2 inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
              >
                {t("detourBadge", {
                  km: (poi.detour_m / 1000).toFixed(1).replace(/\.0$/, ""),
                })}
              </span>
            )}
          </p>
        </div>
      </header>

      <div className="flex flex-col gap-1 text-xs">
        {hasOpeningHours ? (
          <p
            data-testid="poi-card-hours"
            className="flex items-center gap-1 text-muted-foreground"
          >
            <Clock className="h-3 w-3" aria-hidden="true" />
            <span className="truncate">{poi.opening_hours_today}</span>
          </p>
        ) : (
          <p
            data-testid="poi-card-no-hours"
            className="flex items-center gap-1 text-amber-700"
          >
            <AlertTriangle className="h-3 w-3" aria-hidden="true" />
            <span>{t("noOpeningHours")}</span>
          </p>
        )}

        {closesAt !== "" && (
          <p
            data-testid="poi-card-closes-at"
            className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800"
          >
            {t("closesAt", { time: closesAt })}
          </p>
        )}

        {poi.phone && (
          <a
            href={`tel:${poi.phone}`}
            className="flex items-center gap-1 text-brand hover:underline"
          >
            <Phone className="h-3 w-3" aria-hidden="true" />
            <span>{poi.phone}</span>
          </a>
        )}

        {hasWarning && (
          <p
            data-testid="poi-card-warning"
            className="flex items-start gap-1 text-amber-700"
          >
            <AlertTriangle
              className="mt-0.5 h-3 w-3 shrink-0"
              aria-hidden="true"
            />
            <span>{poi.warning}</span>
          </p>
        )}
      </div>

      <a
        href={poi.deeplink}
        target="_blank"
        rel="noopener noreferrer"
        data-testid="poi-card-open-maps"
        className={cn(
          "inline-flex items-center justify-center gap-1 rounded-md border border-border bg-background",
          "px-3 py-1.5 text-xs font-medium text-foreground shadow-sm",
          "hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand",
        )}
      >
        <ExternalLink className="h-3 w-3" aria-hidden="true" />
        {t("openInMaps")}
      </a>
    </article>
  );
}

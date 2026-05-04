"use client";

/**
 * Cultural POI popover — two variants based on the richness of the data.
 *
 * Variant A — "Enriched" (Wikidata / DataTourisme):
 *   photo, multilingual description, structured opening hours, price,
 *   Wikipedia link, "Navigate" button.
 *
 * Variant B — "Minimal" (OSM only):
 *   name, OSM type, "Navigate" button.
 *
 * The variant is picked from {@link isEnrichedPoi}, which mirrors the
 * detection used in `MapView.tsx` and `alert-list.tsx`: any of
 * `description`, `openingHours`, `estimatedPrice`, `imageUrl`, `wikidataId`
 * or `wikipediaUrl` flips the alert into the enriched variant.
 *
 * Issue #398 — sprint 26.
 */
import { useTranslations, useLocale } from "next-intl";
import type { AlertData } from "@/lib/validation/schemas";
import { Button } from "@/components/ui/button";
import { Navigation, ExternalLink, X } from "lucide-react";
import {
  CulturalPoiIcon,
  CulturalPoiEnrichedIcon,
} from "@/components/Map/icons";
import { cn } from "@/lib/utils";
import { formatOpeningHoursStatus } from "@/lib/poi-opening-hours";

export interface PoiPopoverProps {
  alert: AlertData;
  onClose: () => void;
}

/**
 * Returns true when the cultural POI alert carries any enrichment field —
 * matches the heuristic used elsewhere when `hasEnrichedData` is not yet
 * exposed by the backend OSM POI payload (issue #348 follow-up).
 */
export function isEnrichedPoi(alert: AlertData): boolean {
  return (
    alert.description != null ||
    alert.openingHours != null ||
    alert.estimatedPrice != null ||
    alert.imageUrl != null ||
    alert.wikidataId != null ||
    alert.wikipediaUrl != null
  );
}

/**
 * Builds the platform-aware navigation URL.
 *
 * On iOS / iPadOS we open Apple Maps, otherwise Google Maps. Both providers
 * accept a `?q=lat,lon` deep link.
 */
function buildNavigationUrl(lat: number, lon: number): string {
  if (typeof navigator !== "undefined") {
    const ua = navigator.userAgent || "";
    const isApple =
      /iPad|iPhone|iPod/i.test(ua) ||
      (ua.includes("Mac") && "ontouchend" in document);
    if (isApple) {
      return `https://maps.apple.com/?q=${lat},${lon}`;
    }
  }
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lon}`;
}

export function PoiPopover({ alert, onClose }: PoiPopoverProps) {
  const t = useTranslations("poiPopover");
  const locale = useLocale();
  const enriched = isEnrichedPoi(alert);

  const lat = alert.poiLat ?? alert.lat ?? null;
  const lon = alert.poiLon ?? alert.lon ?? null;
  const name = alert.poiName ?? alert.message;
  const type = alert.poiType ?? alert.source ?? "";

  const navigationUrl =
    lat != null && lon != null ? buildNavigationUrl(lat, lon) : null;
  const openingStatus = alert.openingHours
    ? formatOpeningHoursStatus(alert.openingHours, locale, new Date())
    : null;

  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-labelledby="poi-popover-title"
      data-testid="poi-popover"
      data-variant={enriched ? "enriched" : "minimal"}
      onKeyDown={(e) => {
        if (e.key === "Escape") onClose();
      }}
      className={cn(
        "relative w-72 max-w-[18rem] overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg",
        "animate-in fade-in-0 zoom-in-95",
      )}
    >
      <button
        type="button"
        onClick={onClose}
        aria-label={t("close")}
        className="absolute top-2 right-2 z-10 inline-flex h-6 w-6 items-center justify-center rounded-full bg-background/80 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
        data-testid="poi-popover-close"
      >
        <X className="h-3.5 w-3.5" aria-hidden />
      </button>

      {enriched && alert.imageUrl?.startsWith("https://") && (
        <div className="aspect-[5/3] w-full bg-muted">
          {/* eslint-disable-next-line @next/next/no-img-element -- external Wikimedia thumbnails are not resolvable by next/image */}
          <img
            src={alert.imageUrl}
            alt={name}
            loading="lazy"
            className="h-full w-full object-cover"
            data-testid="poi-popover-image"
          />
        </div>
      )}

      <div className="flex flex-col gap-2 p-3">
        <div className="flex items-start gap-2">
          <span className="mt-0.5 shrink-0 text-[#b45309]" aria-hidden>
            {enriched ? (
              <CulturalPoiEnrichedIcon size={20} />
            ) : (
              <CulturalPoiIcon size={20} />
            )}
          </span>
          <div className="min-w-0 flex-1">
            <h3
              id="poi-popover-title"
              className="font-serif text-base font-semibold leading-tight tracking-tight"
              data-testid="poi-popover-title"
            >
              {name}
            </h3>
            {type && (
              <p
                className="text-xs text-muted-foreground"
                data-testid="poi-popover-type"
              >
                {type}
              </p>
            )}
          </div>
        </div>

        {enriched && alert.description && (
          <p
            className="text-xs text-muted-foreground line-clamp-3"
            data-testid="poi-popover-description"
          >
            {alert.description}
          </p>
        )}

        {enriched && openingStatus && (
          <p
            className={cn(
              "text-xs font-medium",
              openingStatus.isOpen
                ? "text-emerald-700 dark:text-emerald-400"
                : "text-amber-700 dark:text-amber-400",
            )}
            data-testid="poi-popover-opening-hours"
          >
            {openingStatus.label}
          </p>
        )}

        {enriched && typeof alert.estimatedPrice === "number" && (
          <p
            className="text-xs text-muted-foreground"
            data-testid="poi-popover-price"
          >
            {alert.estimatedPrice === 0
              ? t("free")
              : t("price", { value: alert.estimatedPrice.toFixed(2) })}
          </p>
        )}

        {enriched && alert.wikipediaUrl?.startsWith("https://") && (
          <a
            href={alert.wikipediaUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
            data-testid="poi-popover-wikipedia"
          >
            <ExternalLink className="h-3 w-3" aria-hidden />
            {t("wikipedia")}
          </a>
        )}

        {navigationUrl && (
          <Button
            asChild
            size="sm"
            variant="default"
            className="mt-1 h-8 w-full text-xs"
            data-testid="poi-popover-navigate"
          >
            <a
              href={navigationUrl}
              target="_blank"
              rel="noopener noreferrer"
              aria-label={t("navigateAriaLabel", { name })}
            >
              <Navigation className="h-3.5 w-3.5" aria-hidden />
              {t("navigate")}
            </a>
          </Button>
        )}
      </div>
    </div>
  );
}

"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import {
  MARKER_CATEGORIES,
  MARKER_CATEGORY_COLOR,
  MarkerIcon,
  type MarkerCategory,
} from "@/components/Map/icons";
import { cn } from "@/lib/utils";

interface MapLegendProps {
  /**
   * When `inline` is true, the legend renders as a static block (used inside
   * the FAQ accordion or any content area). Otherwise, a floating button
   * toggles a popover anchored to the map.
   */
  inline?: boolean;
  className?: string;
}

/** Stable unique IDs for the 12 categories — used to fetch i18n labels. */
const LEGEND_LABEL_KEYS: Record<MarkerCategory, string> = {
  accommodation: "accommodation",
  water: "water",
  supply: "supply",
  "bike-workshop": "bikeWorkshop",
  "railway-station": "railwayStation",
  health: "health",
  "border-crossing": "borderCrossing",
  "river-crossing": "riverCrossing",
  "early-departure": "earlyDeparture",
  "cultural-poi": "culturalPoi",
  event: "event",
  "user-waypoint": "userWaypoint",
};

/**
 * Renders a key/legend explaining the 12 marker categories. When `inline` is
 * not set, exposes a floating "Légende" button that opens a popover.
 */
export function MapLegend({ inline = false, className }: MapLegendProps) {
  const t = useTranslations("mapLegend");
  const [open, setOpen] = useState(false);
  const popoverRef = useRef<HTMLDivElement>(null);

  const toggle = useCallback(() => setOpen((v) => !v), []);
  const close = useCallback(() => setOpen(false), []);

  // Close on Escape and on outside click
  useEffect(() => {
    if (!open || inline) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") close();
    };
    const onClick = (e: MouseEvent) => {
      if (
        popoverRef.current &&
        !popoverRef.current.contains(e.target as Node)
      ) {
        close();
      }
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onClick);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onClick);
    };
  }, [open, inline, close]);

  const list = (
    <ul
      className="grid grid-cols-1 gap-2 sm:grid-cols-2"
      data-testid="map-legend-list"
    >
      {MARKER_CATEGORIES.map((category) => {
        const Icon = MarkerIcon[category];
        const colorClass = MARKER_CATEGORY_COLOR[category];
        const labelKey = LEGEND_LABEL_KEYS[category];
        return (
          <li
            key={category}
            className="flex items-center gap-2 text-sm"
            data-testid={`map-legend-item-${category}`}
          >
            <span
              className={cn(
                "inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--surface)]",
                colorClass,
              )}
              aria-hidden
            >
              <Icon size={20} />
            </span>
            <span className="text-[var(--ink)] font-sans">{t(labelKey)}</span>
          </li>
        );
      })}
    </ul>
  );

  if (inline) {
    return (
      <section
        className={cn(
          "rounded-xl border border-border bg-[var(--surface)] p-4 text-[var(--ink)]",
          className,
        )}
        data-testid="map-legend"
        aria-label={t("title")}
      >
        <h3 className="mb-3 font-sans text-base font-semibold">{t("title")}</h3>
        {list}
      </section>
    );
  }

  return (
    <div
      className={cn("absolute bottom-3 left-3 z-10", className)}
      ref={popoverRef}
    >
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-controls="map-legend-popover"
        className="bg-[var(--surface)]/95 backdrop-blur-sm border border-border text-[var(--ink)] text-xs font-sans font-medium px-3 py-1.5 rounded-lg shadow-sm hover:bg-accent transition-colors cursor-pointer"
        data-testid="map-legend-toggle"
      >
        {open ? t("close") : t("open")}
      </button>

      {open && (
        <div
          id="map-legend-popover"
          role="region"
          aria-label={t("title")}
          className="mt-2 w-72 rounded-xl border border-border bg-[var(--surface)] p-3 text-[var(--ink)] shadow-lg"
          data-testid="map-legend"
        >
          <h3 className="mb-3 font-sans text-sm font-semibold">{t("title")}</h3>
          {list}
        </div>
      )}
    </div>
  );
}

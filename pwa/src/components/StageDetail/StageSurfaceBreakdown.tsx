"use client";

import { useTranslations } from "next-intl";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import type { SurfaceSegmentData } from "@/lib/validation/schemas";

/**
 * Surface family used to group raw OSM `surface=*` values into a small set of
 * visual buckets. Keep this list aligned with `SURFACE_FAMILIES` and the
 * matching i18n keys in `messages/{fr,en}.json` (`surfaceBreakdown.label_*`).
 */
type SurfaceFamily =
  | "paved"
  | "gravel"
  | "cobblestone"
  | "unpaved"
  | "unknown";

/**
 * Maps OSM `surface=*` raw values to coarser display families. The list mirrors
 * the values handled by `SurfaceAlertAnalyzer` on the backend so the visual
 * grouping stays consistent with the alert engine.
 */
const SURFACE_TO_FAMILY: Record<string, SurfaceFamily> = {
  paved: "paved",
  asphalt: "paved",
  concrete: "paved",
  concrete_lanes: "paved",
  concrete_plates: "paved",
  paving_stones: "cobblestone",
  sett: "cobblestone",
  cobblestone: "cobblestone",
  unhewn_cobblestone: "cobblestone",
  bricks: "cobblestone",
  metal: "paved",
  wood: "paved",
  gravel: "gravel",
  fine_gravel: "gravel",
  pebblestone: "gravel",
  compacted: "gravel",
  unpaved: "unpaved",
  dirt: "unpaved",
  ground: "unpaved",
  earth: "unpaved",
  grass: "unpaved",
  sand: "unpaved",
  mud: "unpaved",
};

/**
 * Tailwind classes used to colour each surface family in the stacked bar.
 * Sprint 25 design tokens (semantic foreground/muted) used for the unknown
 * fallback so the bar still has a calm background where the data is sparse.
 */
const FAMILY_BAR_CLASS: Record<SurfaceFamily, string> = {
  paved: "bg-sky-500 dark:bg-sky-400",
  gravel: "bg-amber-500 dark:bg-amber-400",
  cobblestone: "bg-stone-500 dark:bg-stone-400",
  unpaved: "bg-orange-600 dark:bg-orange-500",
  unknown: "bg-muted-foreground/40",
};

/** Stable order — the bar always renders families in this sequence. */
const FAMILY_ORDER: readonly SurfaceFamily[] = [
  "paved",
  "gravel",
  "cobblestone",
  "unpaved",
  "unknown",
] as const;

function familyOf(surface: string): SurfaceFamily {
  return SURFACE_TO_FAMILY[surface] ?? "unknown";
}

interface AggregatedFamily {
  family: SurfaceFamily;
  meters: number;
  /** Underlying raw `surface=*` values that fed this family — used in tooltips. */
  rawSurfaces: string[];
}

/** Public for test purposes — pure aggregation step. */
export function aggregateBreakdown(
  segments: readonly SurfaceSegmentData[],
): { totals: AggregatedFamily[]; totalMeters: number } {
  const buckets = new Map<SurfaceFamily, AggregatedFamily>();

  for (const segment of segments) {
    if (segment.lengthMeters <= 0) continue;
    const family = familyOf(segment.surface);
    const bucket = buckets.get(family) ?? {
      family,
      meters: 0,
      rawSurfaces: [],
    };
    bucket.meters += segment.lengthMeters;
    if (
      segment.surface &&
      bucket.rawSurfaces.indexOf(segment.surface) === -1
    ) {
      bucket.rawSurfaces.push(segment.surface);
    }
    buckets.set(family, bucket);
  }

  const totalMeters = Array.from(buckets.values()).reduce(
    (sum, b) => sum + b.meters,
    0,
  );

  const totals = FAMILY_ORDER.flatMap((family) => {
    const bucket = buckets.get(family);
    return bucket ? [bucket] : [];
  });

  return { totals, totalMeters };
}

interface StageSurfaceBreakdownProps {
  breakdown: readonly SurfaceSegmentData[];
}

/**
 * Stacked horizontal bar showing the proportion of each surface family along
 * the stage (e.g. 60% paved / 30% gravel / 10% cobblestone).
 *
 * Conditional: returns `null` when the backend has not provided the field, or
 * when every segment has zero length. The component is forward-compatible —
 * see `StageDataSchema.surfaceBreakdown` for the wire-format contract.
 *
 * Reuses sprint-25 design tokens (border, card, muted-foreground) and the same
 * `rounded-lg border bg-card/40` shell as the difficulty/weather cards so the
 * three blocks line up visually in the right-hand stage detail panel.
 */
export function StageSurfaceBreakdown({
  breakdown,
}: StageSurfaceBreakdownProps) {
  const t = useTranslations("surfaceBreakdown");

  const { totals, totalMeters } = aggregateBreakdown(breakdown);
  if (totalMeters <= 0 || totals.length === 0) {
    return null;
  }

  // Round to whole percent and adjust the dominant slice so the rendered bar
  // always sums to exactly 100% (avoids the classic 99% / 101% rounding gap).
  const rawPercents = totals.map((bucket) => ({
    bucket,
    rounded: Math.round((bucket.meters / totalMeters) * 100),
  }));
  const roundedSum = rawPercents.reduce((sum, p) => sum + p.rounded, 0);
  if (roundedSum !== 100 && rawPercents.length > 0) {
    // Largest slice absorbs the rounding delta.
    const largest = rawPercents.reduce((acc, p) =>
      p.rounded > acc.rounded ? p : acc,
    );
    largest.rounded += 100 - roundedSum;
  }

  return (
    <section
      data-testid="stage-surface-breakdown"
      aria-label={t("ariaLabel")}
      className="rounded-lg border border-border bg-card/40 p-3"
    >
      <header className="mb-2 flex items-center justify-between gap-2">
        <h3 className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          {t("title")}
        </h3>
      </header>

      <div
        className="relative flex h-2.5 w-full overflow-hidden rounded-full bg-muted"
        role="img"
        aria-label={rawPercents
          .map(
            (p) =>
              `${p.rounded}% ${t(`label_${p.bucket.family}` as `label_${SurfaceFamily}`)}`,
          )
          .join(", ")}
      >
        {rawPercents.map((p) => (
          <Tooltip key={p.bucket.family}>
            <TooltipTrigger asChild>
              <div
                className={`h-full ${FAMILY_BAR_CLASS[p.bucket.family]} cursor-default first:rounded-l-full last:rounded-r-full transition-[width] duration-300`}
                style={{ width: `${p.rounded}%` }}
                data-testid={`stage-surface-segment-${p.bucket.family}`}
                data-percent={p.rounded}
              />
            </TooltipTrigger>
            <TooltipContent side="top" className="text-xs">
              <span className="font-medium">
                {t(`label_${p.bucket.family}` as `label_${SurfaceFamily}`)}
              </span>
              {" — "}
              {p.rounded}% ({(p.bucket.meters / 1000).toFixed(1)} km)
              {p.bucket.rawSurfaces.length > 0 && (
                <span className="block text-muted-foreground">
                  {p.bucket.rawSurfaces.join(", ")}
                </span>
              )}
            </TooltipContent>
          </Tooltip>
        ))}
      </div>

      <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
        {rawPercents.map((p) => (
          <li
            key={p.bucket.family}
            className="flex items-center gap-1.5"
            data-testid={`stage-surface-legend-${p.bucket.family}`}
          >
            <span
              aria-hidden="true"
              className={`h-2 w-2 rounded-full ${FAMILY_BAR_CLASS[p.bucket.family]}`}
            />
            <span>
              {t(`label_${p.bucket.family}` as `label_${SurfaceFamily}`)}
            </span>
            <span className="tabular-nums text-muted-foreground/80">
              {p.rounded}%
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

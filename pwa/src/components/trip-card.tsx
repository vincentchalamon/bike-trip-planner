"use client";

import { useMemo } from "react";
import { useLocale, useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import dayjs from "dayjs";
import "dayjs/locale/fr";
import { Mountain, MapPin, Trash2, Calendar } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TripStatusBadge } from "@/components/trip-status-badge";
import { formatDistanceKm } from "@/lib/formatters";
import { cn } from "@/lib/utils";
import type { components } from "@/lib/api/schema";

type TripListItem = components["schemas"]["Trip.TripListItem.jsonld"];

interface TripCardProps {
  trip: TripListItem;
  onDelete?: (trip: TripListItem) => void;
}

/**
 * Card displaying a trip summary with a decorative mini-map preview.
 *
 * The mini-map is rendered as a deterministic SVG polyline derived from
 * the trip id. Until the backend exposes decimated geometry on the list DTO,
 * this preserves a route-like visual without per-card detail fetches.
 */
export function TripCard({ trip, onDelete }: TripCardProps) {
  const t = useTranslations("tripList");
  const locale = useLocale();
  const router = useRouter();

  const tripId = trip.id ?? "";
  const polylinePath = useMemo(() => buildPolylinePath(tripId), [tripId]);
  const distance = formatDistanceKm(trip.totalDistance ?? 0);
  const stageCount = trip.stageCount ?? 0;
  const dateLabel = formatDateRange(
    trip.startDate ?? null,
    trip.endDate ?? null,
    locale,
    t("noDates"),
  );

  return (
    <article
      className={cn(
        "group relative flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm",
        "transition-all hover:shadow-md hover:border-brand/40 focus-within:ring-2 focus-within:ring-ring",
      )}
      data-testid={`trip-card-${tripId}`}
    >
      {/* Mini-map preview */}
      <button
        type="button"
        onClick={() => router.push(`/trips/${tripId}`)}
        className="relative block w-full text-left cursor-pointer focus:outline-none"
        aria-label={t("openTrip", { title: trip.title ?? t("untitled") })}
        data-testid={`trip-item-${tripId}`}
      >
        <TripMiniMap path={polylinePath} tripId={tripId} />
        <div className="absolute top-3 right-3">
          <TripStatusBadge status={trip.status} />
        </div>
      </button>

      {/* Content */}
      <div className="flex flex-1 flex-col gap-3 p-4">
        <header className="flex items-start justify-between gap-2">
          <button
            type="button"
            onClick={() => router.push(`/trips/${tripId}`)}
            className="text-left flex-1 min-w-0 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-sm"
          >
            <h3 className="font-serif text-lg font-semibold leading-tight tracking-tight truncate">
              {trip.title ?? t("untitled")}
            </h3>
            <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
              <Calendar className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate">{dateLabel}</span>
            </p>
          </button>

          {onDelete && (
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 shrink-0 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
              onClick={(e) => {
                e.stopPropagation();
                onDelete(trip);
              }}
              title={t("deleteTrip")}
              aria-label={t("deleteTrip")}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          )}
        </header>

        {/* Stats */}
        <dl className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
          {distance && (
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
              <dt className="sr-only">{t("totalDistance")}</dt>
              <dd className="font-medium text-foreground">{distance}</dd>
            </div>
          )}
          {stageCount > 0 && (
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <Mountain className="h-3.5 w-3.5" aria-hidden="true" />
              <dt className="sr-only">{t("stageCount")}</dt>
              <dd className="font-medium text-foreground">
                {t("stages", { count: stageCount })}
              </dd>
            </div>
          )}
        </dl>
      </div>
    </article>
  );
}

/**
 * Decorative mini-map: gradient terrain background with a stylised polyline.
 *
 * SVG `<defs>` ids are suffixed with `tripId` to avoid duplicate-id collisions
 * when many cards are rendered in a list.
 */
function TripMiniMap({ path, tripId }: { path: string; tripId: string }) {
  const idSuffix = sanitizeIdSuffix(tripId);
  const gridId = `trip-card-grid-${idSuffix}`;
  const routeId = `trip-card-route-${idSuffix}`;
  return (
    <div
      className="relative h-32 w-full overflow-hidden bg-gradient-to-br from-emerald-50 via-sky-50 to-amber-50 dark:from-emerald-950/40 dark:via-sky-950/40 dark:to-amber-950/40"
      aria-hidden="true"
    >
      <svg
        viewBox="0 0 200 100"
        preserveAspectRatio="none"
        className="absolute inset-0 h-full w-full"
      >
        <defs>
          <pattern
            id={gridId}
            width="20"
            height="20"
            patternUnits="userSpaceOnUse"
          >
            <path
              d="M 20 0 L 0 0 0 20"
              fill="none"
              stroke="currentColor"
              strokeWidth="0.3"
              className="text-foreground/10"
            />
          </pattern>
          <linearGradient id={routeId} x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stopColor="var(--brand)" />
            <stop offset="100%" stopColor="var(--brand-hover)" />
          </linearGradient>
        </defs>
        <rect width="200" height="100" fill={`url(#${gridId})`} />

        {/* Halo */}
        <path
          d={path}
          fill="none"
          stroke="white"
          strokeOpacity="0.6"
          strokeWidth="4"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        {/* Route */}
        <path
          d={path}
          fill="none"
          stroke={`url(#${routeId})`}
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <PolylineEndpoints path={path} />
      </svg>
    </div>
  );
}

/** Sanitize an arbitrary string into a safe SVG id fragment. */
function sanitizeIdSuffix(value: string): string {
  const cleaned = value.replace(/[^a-zA-Z0-9_-]/g, "");
  return cleaned.length > 0 ? cleaned : "default";
}

function PolylineEndpoints({ path }: { path: string }) {
  const points = useMemo(() => parsePathPoints(path), [path]);
  const first = points[0];
  const last = points[points.length - 1];
  if (!first || !last) return null;
  return (
    <>
      <circle cx={first.x} cy={first.y} r="2.5" className="fill-emerald-500" />
      <circle cx={last.x} cy={last.y} r="2.5" className="fill-rose-500" />
    </>
  );
}

function parsePathPoints(path: string): { x: number; y: number }[] {
  const out: { x: number; y: number }[] = [];
  const re = /[ML]\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(path)) !== null) {
    out.push({ x: Number(match[1]), y: Number(match[2]) });
  }
  return out;
}

/** Deterministic seeded PRNG (mulberry32). */
function mulberry32(seed: number): () => number {
  let t = seed >>> 0;
  return () => {
    t = (t + 0x6d2b79f5) >>> 0;
    let r = t;
    r = Math.imul(r ^ (r >>> 15), r | 1);
    r ^= r + Math.imul(r ^ (r >>> 7), r | 61);
    return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
  };
}

function hashString(s: string): number {
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

/** Build an SVG path for a meandering route stable per `seed`. */
function buildPolylinePath(seed: string): string {
  const rand = mulberry32(hashString(seed || "trip"));
  const points = 7;
  const padding = 12;
  const stepX = (200 - padding * 2) / (points - 1);
  const coords: { x: number; y: number }[] = [];
  let prevY = padding + rand() * (100 - padding * 2);
  for (let i = 0; i < points; i++) {
    const x = padding + i * stepX + (rand() - 0.5) * 6;
    const drift = (rand() - 0.5) * 40;
    const y = Math.max(padding, Math.min(100 - padding, prevY + drift));
    coords.push({ x, y });
    prevY = y;
  }
  return coords
    .map((p, i) => `${i === 0 ? "M" : "L"} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`)
    .join(" ");
}

function formatDateRange(
  startDate: string | null,
  endDate: string | null,
  locale: string,
  fallback: string,
): string {
  if (!startDate && !endDate) return fallback;
  const fmt = (d: string | null) =>
    d ? dayjs(d).locale(locale).format("D MMM YYYY") : "?";
  if (startDate && endDate && startDate === endDate) return fmt(startDate);
  return `${fmt(startDate)} — ${fmt(endDate)}`;
}

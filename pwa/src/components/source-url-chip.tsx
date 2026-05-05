"use client";

import { useTranslations } from "next-intl";
import { CheckCircle2, AlertCircle } from "lucide-react";
import { cn } from "@/lib/utils";
import { isValidUrl } from "@/lib/validation/url";

/**
 * Detected route source provider, derived from a free-form URL.
 *
 * Mirrors the backend `RouteFetcherRegistry` strategies. The detection runs
 * client-side after the URL field is validated to give the user immediate
 * feedback as a coloured chip beneath the input.
 */
export type SourceProvider =
  | "komoot"
  | "strava"
  | "ridewithgps"
  | "unsupported";

const PROVIDER_PATTERNS: Record<
  Exclude<SourceProvider, "unsupported">,
  readonly RegExp[]
> = {
  komoot: [
    /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?(tour|collection)\/\d+/i,
  ],
  strava: [/^https:\/\/www\.strava\.com\/routes\/\d+/i],
  ridewithgps: [/^https:\/\/ridewithgps\.com\/routes\/\d+/i],
};

/**
 * Detect the route source from a URL. Returns null when the value is empty
 * or not a valid URL (no chip should be displayed yet — wait for further
 * input). Returns "unsupported" for valid URLs that don't match any known
 * source.
 */
export function detectSourceProvider(value: string): SourceProvider | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  if (!isValidUrl(trimmed)) return null;

  for (const [provider, patterns] of Object.entries(PROVIDER_PATTERNS) as Array<
    [Exclude<SourceProvider, "unsupported">, readonly RegExp[]]
  >) {
    if (patterns.some((p) => p.test(trimmed))) return provider;
  }
  return "unsupported";
}

interface SourceUrlChipProps {
  /** URL value being entered. Detection runs on each render. */
  value: string;
  className?: string;
}

/**
 * Small inline chip displayed under the URL input on the trip-creation page.
 *
 * Communicates the detected route source (Komoot / Strava / RideWithGPS) or
 * flags an unsupported URL. Renders nothing while the value is empty or not
 * a parseable URL — to avoid scolding users mid-typing.
 */
export function SourceUrlChip({ value, className }: SourceUrlChipProps) {
  const t = useTranslations("sourceChip");
  const provider = detectSourceProvider(value);
  if (provider === null) return null;

  const config: Record<
    SourceProvider,
    { label: string; classes: string; icon: React.ReactNode }
  > = {
    komoot: {
      label: t("komoot"),
      // Brand-ish green chip for Komoot
      classes:
        "bg-green-50 text-green-800 border-green-200 dark:bg-green-950/40 dark:text-green-300 dark:border-green-800",
      icon: <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />,
    },
    strava: {
      label: t("strava"),
      // Strava signature orange
      classes:
        "bg-orange-50 text-orange-800 border-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:border-orange-800",
      icon: <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />,
    },
    ridewithgps: {
      label: t("ridewithgps"),
      // RideWithGPS signature blue
      classes:
        "bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800",
      icon: <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />,
    },
    unsupported: {
      label: t("unsupported"),
      classes:
        "bg-red-50 text-red-800 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-800",
      icon: <AlertCircle className="h-3.5 w-3.5" aria-hidden="true" />,
    },
  };

  const c = config[provider];
  return (
    <span
      role="status"
      aria-live="polite"
      data-testid="source-url-chip"
      data-provider={provider}
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium",
        c.classes,
        className,
      )}
    >
      {c.icon}
      {c.label}
    </span>
  );
}

"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";

interface TripNotFoundProps {
  /**
   * Variant — selects the message tone.
   * - `trip`: "trip not found" (used by /trips/[id])
   * - `share`: "shared link revoked or invalid" (used by /s/[code])
   */
  variant?: "trip" | "share";
  /** Optional override for the back-link target (defaults per-variant). */
  backHref?: string;
  backLabel?: string;
}

/**
 * Stylised "trip not found" page (sprint 27, #402).
 *
 * Used by both `/trips/[id]` (trip 404) and `/s/[code]` (revoked share). The
 * variant prop swaps the title/subtitle copy while keeping the same warm
 * illustration and amber-accented button. The illustration mirrors the
 * sprint 25 visual language used by `/not-found`.
 */
export function TripNotFound({
  variant = "trip",
  backHref,
  backLabel,
}: TripNotFoundProps) {
  const t = useTranslations(
    variant === "share" ? "shareNotFound" : "tripNotFound",
  );

  const defaultHref = variant === "share" ? "/" : "/trips";

  return (
    <main
      className="flex min-h-[60vh] items-center justify-center px-4 py-12 bg-[var(--color-surface)] text-[var(--color-ink)]"
      data-testid={`${variant}-not-found-page`}
    >
      <div className="text-center space-y-6 max-w-md">
        {/* Reuse the cyclist-on-mountain motif of /not-found */}
        <svg
          aria-label={t("illustrationAlt")}
          role="img"
          viewBox="0 0 200 120"
          className="mx-auto h-32 w-auto text-[var(--color-accent-brand)]"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          data-testid={`${variant}-not-found-illustration`}
        >
          <path d="M0 90 L40 50 L70 75 L110 30 L150 70 L200 45 L200 120 L0 120 Z" />
          <circle cx="160" cy="25" r="8" />
          <circle cx="55" cy="100" r="9" />
          <circle cx="85" cy="100" r="9" />
          <path d="M55 100 L70 80 L85 100 M70 80 L78 80 L85 100 M70 80 L65 70" />
          <path d="M70 60 q2 -8 6 -8 q4 0 4 4 q0 4 -4 6 q-2 1 -2 4" />
          <circle cx="74" cy="70" r="0.5" fill="currentColor" />
        </svg>

        <div className="space-y-3">
          <h1
            className="font-serif text-3xl md:text-4xl font-semibold tracking-tight"
            data-testid={`${variant}-not-found-title`}
          >
            {t("title")}
          </h1>
          <p
            className="font-sans text-base md:text-lg text-[var(--color-ink)]/70"
            data-testid={`${variant}-not-found-subtitle`}
          >
            {t("subtitle")}
          </p>
        </div>

        <Button asChild size="lg" data-testid={`${variant}-not-found-back`}>
          <Link href={backHref ?? defaultHref}>{backLabel ?? t("back")}</Link>
        </Button>
      </div>
    </main>
  );
}

"use client";

import { useTranslations } from "next-intl";

/**
 * Empty-state placeholder for an empty roadbook (sprint 27, #402).
 *
 * Rendered when a trip has been loaded but contains no stages — usually
 * because the import pipeline hasn't materialised stages yet, or the user
 * navigated to a freshly-created draft. Mirrors the "off the trail" tone of
 * the not-found page: warm illustration plus a short, encouraging message.
 */
export function RoadbookEmptyState({ className }: { className?: string }) {
  const t = useTranslations("roadbookEmpty");

  return (
    <div
      className={[
        "flex flex-col items-center justify-center gap-5 rounded-xl border border-dashed border-border bg-card/40 px-6 py-12 text-center",
        className ?? "",
      ].join(" ")}
      role="status"
      aria-live="polite"
      data-testid="roadbook-empty-state"
    >
      {/* Minimalist roadbook illustration: open notebook with a road */}
      <svg
        aria-hidden="true"
        viewBox="0 0 200 120"
        className="h-24 w-auto text-[var(--color-accent-brand)]"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        data-testid="roadbook-empty-state-illustration"
      >
        {/* Notebook spine */}
        <line x1="100" y1="20" x2="100" y2="105" />
        {/* Notebook covers */}
        <path d="M30 20 L100 20 L100 105 L30 105 Z" />
        <path d="M100 20 L170 20 L170 105 L100 105 Z" />
        {/* Winding road */}
        <path d="M45 95 Q60 75 75 80 T 95 50" />
        <path d="M105 50 Q120 30 140 45 T 160 30" />
        {/* Compass rose */}
        <circle cx="155" cy="90" r="8" />
        <line x1="155" y1="82" x2="155" y2="98" />
        <line x1="147" y1="90" x2="163" y2="90" />
      </svg>

      <div className="space-y-1.5 max-w-sm">
        <h2
          className="font-serif text-xl md:text-2xl font-semibold tracking-tight text-foreground"
          data-testid="roadbook-empty-state-title"
        >
          {t("title")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
    </div>
  );
}

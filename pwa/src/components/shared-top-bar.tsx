"use client";

import { useTranslations } from "next-intl";
import Link from "next/link";
import { Bike } from "lucide-react";
import { TripDownloads } from "@/components/trip-downloads";

interface SharedTopBarProps {
  /** Trip title rendered next to the brand. Omitted when not loaded yet. */
  tripTitle?: string;
}

/**
 * Simplified top bar for the shared trip view (`/s/[code]`).
 *
 * Contrast with the owner top bar in {@link TripPlanner}: there is no
 * undo/redo, no Share button, no profile menu, no config gear, and no
 * insertion / deletion controls. Only the brand link (left) and the global
 * GPX download (right) are exposed.
 *
 * The Garmin FIT export from sprint 34 is intentionally omitted until that
 * feature ships — see issue #404.
 */
export function SharedTopBar({ tripTitle }: SharedTopBarProps) {
  const t = useTranslations("sharedTopBar");

  return (
    <header
      data-testid="shared-top-bar"
      className="sticky top-0 z-30 w-full border-b border-border bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60"
    >
      <div className="max-w-[1200px] mx-auto flex items-center gap-4 px-4 md:px-6 h-14">
        {/* Brand — links back to home */}
        <Link
          href="/"
          className="flex items-center gap-2 font-bold text-base text-brand hover:opacity-80 transition-opacity shrink-0"
          aria-label={t("brandHome")}
        >
          <Bike className="h-5 w-5" aria-hidden="true" />
          <span className="hidden sm:inline">{t("brand")}</span>
        </Link>

        {/* Trip title — hidden on small screens to keep the bar compact */}
        {tripTitle && (
          <span
            className="hidden md:block flex-1 min-w-0 truncate text-sm text-muted-foreground"
            data-testid="shared-top-bar-title"
            title={tripTitle}
          >
            {tripTitle}
          </span>
        )}

        {/* Spacer when no title to push the download button to the right */}
        {!tripTitle && <div className="flex-1" />}

        {/* Global GPX download — only action available in read-only mode */}
        <div className="shrink-0">
          <TripDownloads tripId={undefined} tripTitle={tripTitle ?? ""} />
        </div>
      </div>
    </header>
  );
}

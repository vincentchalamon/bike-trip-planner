"use client";

import { useTranslations } from "next-intl";
import { usePathname } from "next/navigation";
import Link from "next/link";
import {
  Bike,
  HelpCircle,
  Share2,
  Plus,
  Map,
  User,
  Settings,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { UndoRedoButtons } from "@/components/undo-redo-buttons";
import { TripDownloads } from "@/components/trip-downloads";
import { LocaleSwitcher } from "@/components/locale-switcher";
import { ThemeToggle } from "@/components/theme-toggle";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/store/ui-store";
import { useAuthStore } from "@/store/auth-store";

/**
 * Desktop top bar (#384).
 *
 * Left → right composition:
 *  1. Brand (logo + "Bike Trip Planner")
 *  2. Navigation tabs ("Nouveau voyage" / "Mes voyages")
 *  3. Undo / Redo — only on a trip detail route (`/trips/[id]`)
 *  4. Share button — only on a trip detail route
 *  5. Help "?" — opens the unified help modal (shortcuts + FAQ)
 *  6. Language pills (FR | EN) — always visible
 *  7. Theme toggle (light / dark / auto)
 *  8. Profile circle (user initial) — direct link to /account/settings
 */
export function TopBar({
  onShare,
  onOpenConfig,
  tripId,
  tripTitle,
}: {
  onShare?: () => void;
  onOpenConfig?: () => void;
  /** When set (a trip is loaded), enables the global GPX/FIT download menu. */
  tripId?: string;
  tripTitle?: string;
}) {
  const t = useTranslations();
  const tNav = useTranslations("navigation");
  const pathname = usePathname();
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);
  const email = useAuthStore((s) => s.user?.email ?? "");

  // Undo/Redo + Share + config are only meaningful while a trip is loaded.
  // A trip lives at /trips/[id] but is also loaded in-place on the home page
  // when the user opens a saved trip card (no route change). We therefore key
  // these controls off the loaded trip (`tripId`) rather than the path. The
  // /trips list and /trips/new wizard are explicitly excluded.
  const isTripDetailRoute = /^\/trips\/[^/]+$/.test(pathname ?? "");
  const isWizard = pathname === "/trips/new";
  const showTripActions = (isTripDetailRoute || !!tripId) && !isWizard;

  const initial = email.trim().charAt(0).toUpperCase() || "?";

  function isActive(href: string) {
    return pathname === href;
  }

  return (
    <header
      data-testid="top-bar"
      className="w-full border-b border-border bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60"
    >
      <div className="max-w-[1200px] mx-auto flex items-center gap-1 sm:gap-2 px-2 sm:px-4 md:px-6 h-14">
        {/* 1. Brand */}
        <Link
          href="/"
          className="flex items-center gap-2 font-bold text-base text-brand hover:opacity-80 transition-opacity shrink-0"
          aria-label={tNav("brandHome")}
          data-testid="top-bar-brand"
        >
          <Bike className="h-5 w-5" aria-hidden="true" />
          <span className="hidden sm:inline">{tNav("brand")}</span>
        </Link>

        {/* 2. Navigation tabs — hidden on the smallest screens to keep the bar
            within the viewport; the brand logo still links back home. */}
        <nav
          className="hidden sm:flex items-center gap-1 ml-2"
          aria-label={tNav("primary")}
        >
          <Button
            asChild
            variant="ghost"
            size="sm"
            className={cn(
              "h-9 gap-1 cursor-pointer",
              isActive("/trips/new") && "bg-accent text-accent-foreground",
            )}
            data-testid="nav-new-trip"
          >
            <Link
              href="/trips/new"
              aria-current={isActive("/trips/new") ? "page" : undefined}
            >
              <Plus className="h-4 w-4" />
              <span className="hidden md:inline">{tNav("newTrip")}</span>
            </Link>
          </Button>
          <Button
            asChild
            variant="ghost"
            size="sm"
            className={cn(
              "h-9 gap-1 cursor-pointer",
              isActive("/trips") && "bg-accent text-accent-foreground",
            )}
            data-testid="nav-my-trips"
          >
            <Link
              href="/trips"
              aria-current={isActive("/trips") ? "page" : undefined}
            >
              <Map className="h-4 w-4" />
              <span className="hidden md:inline">{tNav("myTrips")}</span>
            </Link>
          </Button>
        </nav>

        {/* Spacer */}
        <div className="flex-1" />

        {/* Global GPX/FIT download — only when a trip is loaded */}
        {showTripActions && tripId && (
          <TripDownloads tripId={tripId} tripTitle={tripTitle ?? ""} />
        )}

        {/* 3. Undo / Redo — only when a trip is loaded. Hidden on small
            screens to keep the bar within the viewport (shortcuts still work). */}
        {showTripActions && (
          <div className="hidden sm:flex">
            <UndoRedoButtons />
          </div>
        )}

        {/* 4. Share — only when a trip is loaded */}
        {showTripActions && onShare && (
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9 cursor-pointer"
            onClick={onShare}
            title={t("share.title")}
            aria-label={t("share.title")}
            data-testid="share-button"
          >
            <Share2 className="h-4 w-4" />
          </Button>
        )}

        {/* Config gear — only when a trip is loaded (opens the settings drawer) */}
        {showTripActions && onOpenConfig && (
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9 cursor-pointer"
            onClick={onOpenConfig}
            title={t("config.open")}
            aria-label={t("config.open")}
            data-testid="config-open-button"
          >
            <Settings className="h-4 w-4" />
          </Button>
        )}

        {/* 5. Help */}
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={() => setHelpModalOpen(true)}
          title={t("help.openButton")}
          aria-label={t("help.openButton")}
          data-testid="help-button"
        >
          <HelpCircle className="h-4 w-4" />
        </Button>

        {/* 6. Language pills */}
        <LocaleSwitcher />

        {/* 7. Theme toggle */}
        <ThemeToggle />

        {/* 8. Profile circle */}
        <Button
          asChild
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer rounded-full"
          title={tNav("accountSettings")}
          aria-label={tNav("accountSettings")}
          data-testid="profile-button"
        >
          <Link href="/account/settings">
            {initial !== "?" ? (
              <span
                className="flex h-7 w-7 items-center justify-center rounded-full bg-brand text-xs font-semibold text-white"
                data-testid="profile-initial"
              >
                {initial}
              </span>
            ) : (
              <User className="h-4 w-4" />
            )}
          </Link>
        </Button>
      </div>
    </header>
  );
}

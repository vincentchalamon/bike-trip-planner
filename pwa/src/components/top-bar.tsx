"use client";

import { useTranslations } from "next-intl";
import { usePathname } from "next/navigation";
import Link from "next/link";
import { Bike, HelpCircle, Plus, Map, User } from "lucide-react";
import { Button } from "@/components/ui/button";
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
 *  3. Help "?" — opens the unified help modal (shortcuts + FAQ)
 *  4. Language pills (FR | EN) — always visible
 *  5. Theme toggle (light / dark / auto)
 *  6. Profile circle (user initial) — direct link to /account/settings
 *
 * Trip-specific action buttons (downloads, undo/redo, share, config) used to
 * live here but were moved next to the trip title (recette #649) so the global
 * bar only carries app-wide controls.
 */
export function TopBar({
  showHelp = true,
}: {
  /**
   * Whether to show the help "?" button. Defaults to true (roadbook context).
   * The help modal is only mounted by the trip planner, so pages that mount the
   * bar standalone (e.g. /trips) pass false to avoid a dead button.
   */
  showHelp?: boolean;
}) {
  const t = useTranslations();
  const tNav = useTranslations("navigation");
  const pathname = usePathname();
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);
  const email = useAuthStore((s) => s.user?.email ?? "");

  const initial = email.trim().charAt(0).toUpperCase() || "?";

  function isActive(href: string) {
    return pathname === href;
  }

  // The home page ("/") is the authenticated dashboard's "new trip" entry
  // point — same screen as /trips/new — so the tab must read as active there
  // too (#649). A loaded trip lives at /trips/[id] and must not light it up.
  const isNewTripActive = isActive("/trips/new") || isActive("/");

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
              isNewTripActive && "bg-accent text-accent-foreground",
            )}
            data-testid="nav-new-trip"
          >
            <Link
              href="/trips/new"
              aria-current={isNewTripActive ? "page" : undefined}
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

        {/* Help — only where the help modal is mounted (roadbook context) */}
        {showHelp && (
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
        )}

        {/* 4. Language pills */}
        <LocaleSwitcher />

        {/* 5. Theme toggle */}
        <ThemeToggle />

        {/* 6. Profile circle */}
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
                className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-fill text-xs font-semibold text-white"
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

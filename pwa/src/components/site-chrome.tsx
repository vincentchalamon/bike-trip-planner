"use client";

import type { ReactNode } from "react";
import { TopBar } from "@/components/top-bar";
import { PublicTopBar } from "@/components/public-top-bar";
import { HelpModal } from "@/components/help-modal";
import { LandingFooter } from "@/components/landing/footer";

/**
 * Persistent site chrome: header + the homepage footer, shared by every
 * authenticated and public page, the trip loading / "Voyage introuvable"
 * states, and the error pages (recette #649).
 *
 * Before this, each page rendered its own header/footer ad hoc, so the footer
 * was missing on "Mes voyages", the trip view and "Nouveau voyage", and absent
 * entirely from the loading and error states. Centralising it here (used by the
 * `(app)` / `(public)` route-group layouts, the home dashboard and the error
 * pages) guarantees the same {@link LandingFooter} — which carries the OSM/data
 * attribution — appears everywhere, with no duplication.
 *
 * The `app` variant also mounts the {@link HelpModal} so the top-bar help
 * button (and the `?` shortcut on the roadbook) works on every authenticated
 * page, not only the trip view.
 *
 * Marked `"use client"`: it composes only client islands (`TopBar` /
 * `PublicTopBar`, `HelpModal`, `LandingFooter`) and is itself imported by client
 * components (`error.tsx`, `home-content.tsx`), so the explicit boundary keeps
 * the contract honest and guards against a server-only import slipping in later.
 * The server route-group layouts that render it treat it as a client island.
 */
export function SiteChrome({
  children,
  variant = "app",
}: {
  children: ReactNode;
  /**
   * `app`: authenticated {@link TopBar} (brand, nav, help, profile) + help
   * modal. `public`: marketing {@link PublicTopBar} (brand, sign-in, request
   * access).
   */
  variant?: "app" | "public";
}) {
  return (
    <div className="min-h-screen flex flex-col bg-background">
      {variant === "public" ? <PublicTopBar /> : <TopBar />}
      <div className="flex-1">{children}</div>
      <LandingFooter />
      {variant === "app" && <HelpModal />}
    </div>
  );
}

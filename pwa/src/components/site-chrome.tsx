"use client";

import type { ReactNode } from "react";
import { TopBar } from "@/components/top-bar";
import { HelpModal } from "@/components/help-modal";
import { LandingFooter } from "@/components/landing/footer";

/**
 * Persistent site chrome: the app {@link TopBar} + the homepage footer, shared
 * by every authenticated and public page (FAQ, legal, privacy), the trip
 * loading / "Voyage introuvable" states and the error pages (recette #649).
 *
 * Public pages used to render a different marketing header (`PublicTopBar`),
 * which broke the visual continuity with the app pages. They now share the same
 * {@link TopBar}, background and footer; the bar adapts to the auth state
 * (nav/profile when signed in, a "sign in" link otherwise). The landing (`/`)
 * keeps its own transparent hero header and is intentionally not part of this
 * chrome.
 *
 * The {@link HelpModal} is always mounted so the top-bar help button (and the
 * `?` shortcut on the roadbook) works on every page that exposes it.
 *
 * Marked `"use client"`: it composes only client islands (`TopBar`,
 * `HelpModal`, `LandingFooter`) and is itself imported by client components
 * (`error.tsx`, `home-content.tsx`), so the explicit boundary keeps the
 * contract honest and guards against a server-only import slipping in later.
 */
export function SiteChrome({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen flex flex-col bg-background">
      <TopBar />
      <div className="flex-1">{children}</div>
      <LandingFooter />
      <HelpModal />
    </div>
  );
}

import type { ReactNode } from "react";
import { SiteChrome } from "@/components/site-chrome";
import { resolveServerSession } from "@/lib/auth/server-session";

/**
 * Chrome + server-side auth gate for the authenticated app pages: "Mes
 * voyages", "Nouveau voyage", the trip view (and its loading / "Voyage
 * introuvable" states) and account settings. The header + homepage footer live
 * here once; each page renders its own content (recette #649). The `(app)`
 * route group keeps the URLs unchanged (the parentheses are stripped).
 *
 * On the web, the server validates the session (ADR-047) and redirects an
 * anonymous visitor to /login BEFORE render, so a protected deep-link (e.g.
 * `/trips/{id}`) never flashes protected chrome. `resolveServerSession()`
 * returns `null` on the mobile static build (no server) or a backend blip → the
 * client-side `AuthGuard` stays the gate (fail-open).
 */
export default async function AppLayout({ children }: { children: ReactNode }) {
  const session = await resolveServerSession();
  if (session && !session.authenticated) {
    const { redirect } = await import("next/navigation");
    redirect("/login");
  }

  return <SiteChrome>{children}</SiteChrome>;
}

import type { ReactNode } from "react";
import { SiteChrome } from "@/components/site-chrome";

/**
 * Chrome for the authenticated app pages: "Mes voyages", "Nouveau voyage", the
 * trip view (and its loading / "Voyage introuvable" states) and account
 * settings. The header + the homepage footer live here once; each page only
 * renders its own content (recette #649). The `(app)` route group keeps the
 * URLs unchanged (the parentheses are stripped from the path).
 */
export default function AppLayout({ children }: { children: ReactNode }) {
  return <SiteChrome>{children}</SiteChrome>;
}

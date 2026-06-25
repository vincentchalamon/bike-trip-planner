import type { ReactNode } from "react";
import { SiteChrome } from "@/components/site-chrome";

/**
 * Chrome for the public pages (FAQ, legal, privacy): the shared {@link TopBar}
 * + the homepage footer, defined once — the same header/footer/theme as the app
 * pages (recette #649). Each page renders only its content. The `(public)`
 * route group keeps the URLs unchanged. The landing (`/`) keeps its own
 * transparent hero header and is intentionally not part of this group.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
  return <SiteChrome>{children}</SiteChrome>;
}

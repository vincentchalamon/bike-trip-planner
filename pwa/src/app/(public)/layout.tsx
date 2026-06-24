import type { ReactNode } from "react";
import { SiteChrome } from "@/components/site-chrome";

/**
 * Chrome for the public, unauthenticated pages (FAQ, legal, privacy):
 * the marketing {@link PublicTopBar} + the homepage footer, defined once. Each
 * page renders only its content (recette #649). The `(public)` route group
 * keeps the URLs unchanged. The landing (`/`) keeps its own transparent hero
 * header and is intentionally not part of this group.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
  return <SiteChrome variant="public">{children}</SiteChrome>;
}

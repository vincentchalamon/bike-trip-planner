import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/constants";

// Required for `output: export` (mobile/Capacitor build): metadata route
// handlers must be statically generated at build time.
export const dynamic = "force-static";

/**
 * sitemap.xml (audit 35.2 SEO-002). Lists the public, indexable pages only —
 * never the authenticated app or the private share links.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  const publicPaths = ["/", "/login", "/faq", "/legal", "/privacy"];

  return publicPaths.map((path) => ({
    url: new URL(path, SITE_URL).toString(),
    changeFrequency: "monthly",
    priority: "/" === path ? 1 : 0.7,
  }));
}

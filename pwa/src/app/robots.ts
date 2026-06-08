import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/constants";

// Required for `output: export` (mobile/Capacitor build): metadata route
// handlers must be statically generated at build time.
export const dynamic = "force-static";

/**
 * robots.txt (audit 35.2 SEO-002). Crawlers may index the public pages; the
 * authenticated app (`/trips`, `/account`) and the unguessable share links
 * (`/s/`) are disallowed so they are neither crawled nor indexed.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/trips", "/account", "/s/"],
    },
    sitemap: new URL("/sitemap.xml", SITE_URL).toString(),
  };
}

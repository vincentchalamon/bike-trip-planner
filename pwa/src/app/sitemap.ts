import type { MetadataRoute } from "next";
import { API_URL } from "@/lib/constants";

/**
 * sitemap.xml (audit 35.2 SEO-002). Lists the public, indexable pages only —
 * never the authenticated app or the private share links.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  const publicPaths = ["/", "/login", "/faq", "/legal", "/privacy"];

  return publicPaths.map((path) => ({
    url: new URL(path, API_URL).toString(),
    changeFrequency: "monthly",
    priority: "/" === path ? 1 : 0.7,
  }));
}

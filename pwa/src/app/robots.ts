import type { MetadataRoute } from "next";

/**
 * robots.txt (W7.1, private beta). Prod and previews must stay out of
 * search indexes entirely, so every user-agent is disallowed site-wide.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      disallow: "/",
    },
  };
}

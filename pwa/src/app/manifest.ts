import type { MetadataRoute } from "next";

// Minimal PWA installability (issue #839): no service worker, just an
// installable manifest. Colors mirror the design tokens in globals.css
// (`--surface` #faf7f0 as background, `--brand` #a8561a as theme). In the
// mobile `output: export` build this is emitted as a static
// /manifest.webmanifest file (no dynamic APIs used).
// `force-static` is required so the route handler is prerendered under
// `output: export` (Capacitor mobile build) instead of failing the build.
export const dynamic = "force-static";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Bike Trip Planner",
    short_name: "BikeTrip",
    description: "Plan your bikepacking trips",
    start_url: "/",
    display: "standalone",
    background_color: "#faf7f0",
    theme_color: "#a8561a",
    icons: [
      {
        src: "/icon-192x192.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icon-512x512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/icon-maskable-512x512.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
  };
}

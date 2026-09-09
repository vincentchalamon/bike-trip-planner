// MapLibre GL v6 is ESM-only and locates its Web Worker at runtime via
// `new URL('./maplibre-gl-worker.mjs', import.meta.url)` — a dynamic form the
// Turbopack production build cannot statically analyse, so the worker chunk is
// never emitted and the map renders blank (no tiles, no markers). Copy the
// worker and the shared chunk it imports into public/ so they are served as
// same-origin static assets; MapView points MapLibre at them via setWorkerUrl().
import { cpSync, existsSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
// maplibre-gl may be hoisted to the workspace root or kept under pwa/.
const src = [
  join(here, "..", "node_modules", "maplibre-gl", "dist"),
  join(here, "..", "..", "node_modules", "maplibre-gl", "dist"),
].find((dir) => existsSync(join(dir, "maplibre-gl-worker.mjs")));

if (!src) {
  throw new Error("maplibre-gl dist not found in node_modules");
}

const dest = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "public",
  "vendor",
  "maplibre",
);

mkdirSync(dest, { recursive: true });
for (const file of ["maplibre-gl-worker.mjs", "maplibre-gl-shared.mjs"]) {
  cpSync(join(src, file), join(dest, file));
}
console.log(`Copied MapLibre worker assets to ${dest}`);
